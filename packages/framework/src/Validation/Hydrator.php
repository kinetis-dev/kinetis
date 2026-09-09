<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Reflection\Exception\UnsupportedDefaultValueException;
use Kinetis\Reflection\ParameterDefault;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\ValidationException;
use BackedEnum;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * Builds a DTO from raw array data (typically a decoded JSON request body),
 * checking each constructor parameter's Constraint attributes (Email,
 * MinLength, ...) before construction. All fields are validated up front so
 * the caller gets every error at once, not just the first one hit.
 *
 * A DTO is described by a hydration plan compiled from its constructor.
 * compilePlan() accepts a finite set of parameter shapes:
 *
 * - A parameter typed with one of the builtin types in
 *   SUPPORTED_BUILTIN_TYPES.
 * - A parameter typed as a single instantiable class: an object-shaped
 *   value is hydrated into that class recursively — its own violations
 *   surfacing under the ["field", "nestedField"] path — and a value that
 *   is already an instance of it is taken as given. Object-shaped means a
 *   JSON object (a JsonObject marker) or a map-shaped PHP array; a JSON
 *   array is not an object and never hydrates one, `[]` included.
 * - A parameter typed as a single non-instantiable class (an interface,
 *   an abstract class, a unit enum): only an existing instance is
 *   accepted. No request value can construct one — the case this exists
 *   for is the UploadedFileInterface Kinetis\Http\Dispatcher merges
 *   into a form-encoded body from the request's normalized uploaded
 *   files. That one interface is resolved through
 *   resolveUploadedFile() rather than as a bare instance: its transport
 *   status is checked before its own rules are, and before any code can
 *   touch its stream.
 * - A parameter typed as a backed enum: the case its backing value
 *   names. The value is resolved as that backing scalar first, so a
 *   wrong primitive is an ordinary type violation and a correctly typed
 *   value naming no case an enum_case one, never a TypeError. An
 *   existing case is taken as given.
 * - A parameter typed `array` carrying #[ListOf]: a JSON array whose
 *   every element is one value of the element type that attribute
 *   names — a scalar, a backed enum case, an uploaded file, or a DTO
 *   hydrated from an object-shaped element (or already an instance of
 *   it). Element violations surface under the ["field", index] /
 *   ["field", index, "nestedField"] path. A scalar, backed-enum or
 *   upload list may also carry #[Each] rules, which every element
 *   passes before the field's own rules ever see the list. See
 *   listItem().
 * - A parameter typed `array` carrying #[ObjectMap]: a JSON object of
 *   arbitrary keys, handed to the constructor as its plain array form.
 *   Unlike every other accepted shape, this one admits only a value
 *   carrying JsonObject provenance — see resolveObjectMapValue().
 * - A nullable variant of any of the above.
 * - `T|Absent` or `T|null|Absent`, defaulted to exactly Absent::Value,
 *   where T is exactly one of the shapes above: the presence union an
 *   update DTO uses to tell an omitted member from one explicitly sent
 *   as null. See Absent, and absentUnion() for the four ways such a
 *   declaration is rejected. This is the only union a DTO field may
 *   declare, and it exists for DTO constructor fields alone —
 *   Kinetis\Http\Dispatcher, Kinetis\Mcp\McpRegistry and
 *   Kinetis\Mcp\McpDispatcher still reject every union on a controller
 *   or tool method parameter.
 *
 * A DTO class may also carry ObjectConstraint attributes: cross-field
 * rules that run once, against the constructed object, after every field
 * has resolved and passed its own rules. compilePlan() stores them at the
 * plan root as the same literal {class, args} descriptors a field rule
 * gets, and checks each rule's own fields() names against this
 * constructor first. See ObjectConstraint, collectObjectRules() and
 * objectRuleViolations().
 *
 * Every other definition is rejected with an
 * UnsupportedDtoDefinitionException while the plan is compiled — at build
 * time for an AOT-compiled plan, on the first hydrate() call for a live
 * one: an intersection type, a union that is not one of the two Absent
 * forms, a malformed Absent union, a recursive or mutually
 * recursive class reference (a plan embeds each nested class inline, so
 * recursion has no finite plan and nothing var_export() could bake into a
 * cache file), a class type reflection cannot resolve (self/parent/static),
 * a builtin type outside SUPPORTED_BUILTIN_TYPES, #[ListOf] on a parameter
 * that isn't typed `array`, #[ListOf] naming an element type outside the
 * admitted set, a backed enum with no cases, #[Each] on a parameter
 * declaring no #[ListOf] or on a list of DTOs, #[Each] naming a class
 * that is not a Constraint, #[ObjectMap] on a parameter that isn't typed
 * `array`, #[ObjectMap] combined with #[ListOf], and an ObjectConstraint
 * naming a field this constructor does not declare.
 *
 * A parameter's own default value is captured under the rule
 * Kinetis\Reflection\ParameterDefault owns, shared with Dispatcher's
 * binding plan: an object default other than an enum case is rejected
 * there, at the same point, with an UnsupportedDefaultValueException.
 *
 * Every builtin-typed parameter is type-checked before it is cast, never
 * after, and which spellings that check admits is the caller's declared
 * InputSource: a JSON body promises JSON primitives, a query string or a
 * form body carries text, and a database row carries whatever its driver
 * produced. See InputSource itself for the three vocabularies. What they
 * agree on: `string` requires an actual string, `array`/`iterable` both
 * require a real JSON array, and `mixed` accepts anything by definition.
 *
 * A missing or explicitly-null value is a separate concern from a
 * wrong-shaped one: a missing key on a defaultless parameter is "is
 * required.", and an explicitly-null value for a parameter whose declared
 * type doesn't allow null is "must not be null." — both validation
 * failures, never a raw TypeError escaping the constructor.
 *
 * A member the DTO does not declare is a third: under InputSource::Json
 * it is "is not expected." on its own path, at every nesting level, so a
 * misspelled field fails instead of silently doing nothing. Text and
 * Native stay open — a form body legitimately carries CSRF, submit and
 * honeypot fields, and a Native caller hands over whatever row it read —
 * so neither rejects an unknown member. See unknownMemberViolations().
 *
 * Every failure this class raises is a Kinetis\Validation\Violation
 * carrying a segmented path, a stable code, the message, and the values
 * that message was built from. resolveScalar() is the one entry every
 * source of a raw scalar goes through — a #[Body] DTO field here, a
 * #[Query]/path parameter via Kinetis\Http\Dispatcher, an MCP tool
 * argument via Kinetis\Mcp\McpDispatcher — so null handling, type
 * checking, source normalization, casting and the field's own constraints
 * all happen once, in one order, and a wrong-shaped value can never
 * reach a real constructor unchecked regardless of which one dispatched
 * it. resolveUploadedFile() is that same one entry for an uploaded
 * file, whose transport status is checked before its rules are, and it
 * is equally shared: a #[Body] DTO field, a #[ListOf] element, and an
 * UploadedFileInterface-typed controller parameter all bind through it.
 * objectExpectedViolation(), requiredViolation() and
 * unexpectedFieldViolation() stay public beside them for the three
 * failures a caller detects before it has a value to resolve at all.
 *
 * Holds exactly one piece of static state: a memoization cache of
 * compilePlan() output, keyed by DTO class. This is a deliberate,
 * documented exemption from the NoStaticPropertiesRule this codebase
 * enforces (see phpstan.neon): a plan is pure derived data, identical on
 * every request for the process's lifetime, so persisting it across
 * requests cannot bleed request state — it only avoids re-running the
 * same reflection for every hydrated row. ParameterDefault is what keeps
 * "pure derived data" true of the one field that could otherwise hold a
 * live value, a captured default. $compiledPlan remains an optional
 * argument so ahead-of-time compiled plans (Kinetis\Cache) keep skipping
 * even the first live compile.
 *
 * HydrationPlan can't self-reference `nestedPlan` in its own type alias —
 * PHPStan (at least this version) rejects that as a circular definition
 * even for genuine self-recursion, not just mutual recursion between two
 * aliases — so `nestedPlan` is loosely typed as `?array` here. It's always
 * actually shaped exactly like HydrationPlan itself at runtime; only the
 * static type is less precise at arbitrary nesting depth.
 *
 * @phpstan-type HydrationPlanListItem array{
 *     scalarType: ?string,
 *     enumClass: ?class-string,
 *     dtoClass: ?class-string,
 *     nestedPlan: ?array<string, mixed>,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 * @phpstan-type HydrationPlanParameter array{
 *     name: string,
 *     scalarType: ?string,
 *     enumClass: ?class-string,
 *     dtoClass: ?class-string,
 *     nestedPlan: ?array<string, mixed>,
 *     listItem: ?array<string, mixed>,
 *     objectMap: bool,
 *     absent: bool,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     allowsNull: bool,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 * @phpstan-type HydrationPlan array{
 *     className: class-string,
 *     hasConstructor: bool,
 *     parameters: list<HydrationPlanParameter>,
 *     objectRules: list<array{class: class-string<ObjectConstraint>, args: array<int|string, mixed>}>,
 * }
 */
final class Hydrator
{
    private const string GIVEN_SUFFIX = ' given.';

    private const string NOT_A_JSON_ARRAY = 'must be a JSON array, not a JSON object.';

    private const string NOT_A_JSON_OBJECT = 'must be a JSON object, not a JSON array.';

    private const string NOT_FINITE = 'must be a finite number.';

    private const string NOT_AN_INTEGER = 'must be an integer within the platform integer range.';

    private const string UPLOAD_FAILED = 'could not be uploaded.';

    /**
     * The stable machine names every input source reports for a value
     * hydration itself refused. One code per message template, so a
     * renderer that rebuilds or translates a message never has to parse
     * the English one: TYPE_MISMATCH carries `expected`/`given` type
     * names, NOT_AN_INSTANCE the required `class`, and the rest
     * describe themselves. A rule that a well-shaped value then broke
     * reports the rule's own code instead — see Constraint.
     */
    private const string CODE_REQUIRED = 'required';

    private const string CODE_NULL_NOT_ALLOWED = 'null_not_allowed';

    private const string CODE_TYPE_MISMATCH = 'type_mismatch';

    private const string CODE_NOT_AN_INTEGER = 'not_an_integer';

    private const string CODE_NOT_FINITE = 'not_finite';

    private const string CODE_NOT_A_JSON_ARRAY = 'not_a_json_array';

    private const string CODE_NOT_A_JSON_OBJECT = 'not_a_json_object';

    private const string CODE_NOT_AN_INSTANCE = 'not_an_instance';

    private const string CODE_UNEXPECTED_FIELD = 'unexpected_field';

    private const string CODE_ENUM_CASE = 'enum_case';

    private const string CODE_UPLOAD_FAILED = 'upload_failed';

    /**
     * The builtin types a request-bound parameter may declare. A DTO
     * field outside this set fails when its plan is compiled; a
     * #[Query]/path parameter outside it fails when
     * Kinetis\Http\Dispatcher derives the route's binding plan; and
     * Kinetis\Validation\JsonSchema describes exactly this set. Every
     * other builtin — `null`, `true`, `false`, `object`, `callable` —
     * has no request representation worth the machinery to accept it.
     *
     * @var list<string>
     */
    public const array SUPPORTED_BUILTIN_TYPES = ['string', 'int', 'float', 'bool', 'array', 'iterable', 'mixed'];

    private const array HYDRATION_PLAN_KEYS = ['className', 'hasConstructor', 'parameters', 'objectRules'];

    private const array HYDRATION_PLAN_PARAMETER_KEYS = [
        'name', 'scalarType', 'enumClass', 'dtoClass', 'nestedPlan', 'listItem',
        'objectMap', 'absent', 'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
    ];

    private const array HYDRATION_PLAN_LIST_ITEM_KEYS = [
        'scalarType', 'enumClass', 'dtoClass', 'nestedPlan', 'constraints',
    ];

    /**
     * The scalar spellings #[ListOf] admits as an element type — a
     * subset of SUPPORTED_BUILTIN_TYPES. `array` and `iterable` would
     * name a list of lists, whose own elements nothing describes, and
     * `mixed` an element type that is no type at all.
     *
     * @var list<string>
     */
    private const array LIST_ITEM_SCALAR_TYPES = ['string', 'int', 'float', 'bool'];

    /**
     * Memoized compilePlan() output — see the class docblock for why this
     * static property is exempt from NoStaticPropertiesRule.
     *
     * @var array<class-string, HydrationPlan>
     */
    private static array $planCache = [];

    /**
     * $source declares which wire vocabulary $data was written in, and
     * therefore which spellings of each declared scalar type bind — see
     * InputSource. It defaults to Native, the vocabulary of a caller
     * that already holds PHP values (a database row from
     * `Kinetis\QueryBuilder\Query`, an array a service assembled
     * itself). A transport names its own: `Kinetis\Http\Dispatcher`
     * passes Json or Text per request from the body's media type,
     * `Kinetis\Mcp\McpDispatcher` passes Json.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @param HydrationPlan|null $compiledPlan
     * @return T
     * @throws ValidationException
     * @throws UnsupportedDtoDefinitionException
     * @throws UnsupportedDefaultValueException
     */
    public static function hydrate(string $class, array $data, ?array $compiledPlan = null, InputSource $source = InputSource::Native): object
    {
        /** @var T */
        return self::hydrateFromPlan(
            $compiledPlan ?? self::$planCache[$class] ??= self::compilePlan($class),
            $data,
            $source,
        );
    }

    /**
     * Pure reflection -> plan; no input data involved, so the result is
     * identical for every hydrate() call this DTO class will ever receive.
     * Used both by the live per-call fallback above (when no compiled plan
     * is supplied) and by Kinetis\Cache\Compiler ahead of time. Recurses into
     * every instantiable class-typed constructor parameter, embedding that
     * class's own plan inline as `nestedPlan` — so compiling just the
     * top-level DTO a route/tool binds directly already produces a fully
     * nested-inclusive plan, with zero further discovery needed elsewhere
     * (see Kinetis\Cache\Compiler's own doc comment for why its discovery
     * pass doesn't need to change to stay correct here).
     *
     * Constraint entries capture the attribute's literal constructor
     * arguments via ReflectionAttribute::getArguments() — NOT newInstance()
     * — so each descriptor is plain data (e.g. #[MinLength(5)] ->
     * {class: MinLength::class, args: [5]}), reconstructable later via
     * `new $class(...$args)` with zero reflection.
     *
     * $visiting carries the classes already being compiled in the current
     * recursion chain, so a self-referencing or mutually referencing
     * definition is rejected the moment a class repeats. See the class
     * docblock for the complete set of definitions this refuses.
     *
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return HydrationPlan
     * @throws UnsupportedDtoDefinitionException
     * @throws UnsupportedDefaultValueException
     */
    public static function compilePlan(string $class, array $visiting = []): array
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw UnsupportedDtoDefinitionException::notInstantiable($class);
        }

        $constructor = $reflection->getConstructor();
        $objectRules = self::collectObjectRules($reflection);

        if ($constructor === null) {
            return ['className' => $class, 'hasConstructor' => false, 'parameters' => [], 'objectRules' => $objectRules];
        }

        $visiting[$class] = true;
        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = self::compileParameter($parameter, $class, $visiting);
        }

        return ['className' => $class, 'hasConstructor' => true, 'parameters' => $parameters, 'objectRules' => $objectRules];
    }

    /**
     * Validates a compiled `array<string, HydrationPlan>` map — this
     * class is the one abstraction that owns `HydrationPlan`'s shape
     * (its recursive `nestedPlan` and its list-item descriptor
     * included), so this is the one place that shape is ever checked,
     * called by
     * `Kinetis\Cache\HttpCache::fromArray()` rather than that class
     * re-deriving the same recursive rules itself. Every top-level key
     * must be a real string (PHP silently coerces a numeric-looking
     * array key to int).
     *
     * @param array<array-key, mixed> $plans
     * @throws CacheArtifactExceptionInterface
     */
    public static function validatePlans(array $plans): void
    {
        foreach ($plans as $key => $plan) {
            if (!is_string($key)) {
                throw InvalidCacheArtifactException::malformedEntry('HydrationPlan', 'a key that is not a string');
            }

            if (!is_array($plan)) {
                throw InvalidCacheArtifactException::wrongFieldType('HydrationPlan', $key, 'an array');
            }

            self::validatePlan($plan);
        }
    }

    /**
     * One `HydrationPlan` shape, recursing into every plan nested
     * inside it — a parameter's own `nestedPlan`, and the one a list
     * item's descriptor carries.
     *
     * @param array<array-key, mixed> $plan
     * @throws CacheArtifactExceptionInterface
     */
    private static function validatePlan(array $plan): void
    {
        ArtifactValidation::exactKeys($plan, 'HydrationPlan', self::HYDRATION_PLAN_KEYS);

        ArtifactValidation::string($plan, 'HydrationPlan', 'className');
        ArtifactValidation::bool($plan, 'HydrationPlan', 'hasConstructor');
        ArtifactValidation::listOfConstraintDescriptors($plan, 'HydrationPlan', 'objectRules');
        $parameters = ArtifactValidation::listOfArrays($plan, 'HydrationPlan', 'parameters');

        foreach ($parameters as $parameter) {
            ArtifactValidation::exactKeys($parameter, 'HydrationPlanParameter', self::HYDRATION_PLAN_PARAMETER_KEYS);

            ArtifactValidation::string($parameter, 'HydrationPlanParameter', 'name');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'scalarType');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'enumClass');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'dtoClass');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'objectMap');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'absent');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'hasDefault');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'allowsNull');
            // defaultValue's own presence is already guaranteed by
            // exactKeys() above; its value holds an arbitrary PHP
            // default with no single type to check further.
            ArtifactValidation::listOfConstraintDescriptors($parameter, 'HydrationPlanParameter', 'constraints');

            self::validateNestedPlan($parameter, 'HydrationPlanParameter', 'nestedPlan');
            self::validateListItem($parameter['listItem'] ?? null);
        }
    }

    /**
     * One `HydrationPlanListItem` descriptor: the plain-data element
     * shape a #[ListOf] parameter carries, and null on every other one.
     * Which of its `enumClass`/`dtoClass`/`scalarType` fields are filled
     * is what names the element family, so each is checked for its own
     * type here and the branch is read at runtime.
     *
     * @throws CacheArtifactExceptionInterface
     */
    private static function validateListItem(mixed $item): void
    {
        if ($item === null) {
            return;
        }

        if (!is_array($item)) {
            throw InvalidCacheArtifactException::wrongFieldType('HydrationPlanParameter', 'listItem', 'an array or null');
        }

        ArtifactValidation::exactKeys($item, 'HydrationPlanListItem', self::HYDRATION_PLAN_LIST_ITEM_KEYS);

        ArtifactValidation::nullableString($item, 'HydrationPlanListItem', 'scalarType');
        ArtifactValidation::nullableString($item, 'HydrationPlanListItem', 'enumClass');
        ArtifactValidation::nullableString($item, 'HydrationPlanListItem', 'dtoClass');
        ArtifactValidation::listOfConstraintDescriptors($item, 'HydrationPlanListItem', 'constraints');

        self::validateNestedPlan($item, 'HydrationPlanListItem', 'nestedPlan');
    }

    /**
     * One inline plan a parameter or a list item carries — itself the
     * identical `HydrationPlan` shape, one level deeper, exactly as
     * `compilePlan()` embeds it, or null where nothing is nested.
     * Naturally bounded by the data itself: `compilePlan()` rejects a
     * recursive definition outright, so a circular plan is not
     * producible in the first place.
     *
     * @param array<array-key, mixed> $owner
     * @throws CacheArtifactExceptionInterface
     */
    private static function validateNestedPlan(array $owner, string $type, string $field): void
    {
        $nested = $owner[$field] ?? null;

        if ($nested === null) {
            return;
        }

        if (!is_array($nested)) {
            throw InvalidCacheArtifactException::wrongFieldType($type, $field, 'an array or null');
        }

        self::validatePlan($nested);
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return HydrationPlanParameter
     * @throws UnsupportedDtoDefinitionException
     * @throws UnsupportedDefaultValueException
     */
    private static function compileParameter(ReflectionParameter $parameter, string $class, array $visiting): array
    {
        $declared = $parameter->getType();
        $absent = self::absentUnion($parameter, $class);
        // From here on the parameter is described by the one type a
        // supplied value has: T itself for a presence union, the declared
        // type otherwise. Everything below — nesting, #[ObjectMap], the
        // builtin check — asks the same questions of the same shape either
        // way, which is what keeps `T|Absent` exactly as expressive as `T`
        // and no more.
        $type = $absent !== null ? $absent[0] : $declared;
        [$enumScalarType, $enumClass, $dtoClass, $nestedPlan, $listItem] = self::compileNesting($type, $parameter, $class, $visiting);
        $objectMap = self::compileObjectMap($type, $parameter, $class, $listItem !== null);
        // A backed enum's wire value is the scalar its cases are
        // written in, so the field carries that type; every other
        // scalar is the declaration's own builtin. Both are int or
        // string for an enum, so only a declared builtin can fall
        // outside the supported set.
        $scalarType = $enumScalarType ?? ($type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null);

        if ($scalarType !== null && !in_array($scalarType, self::SUPPORTED_BUILTIN_TYPES, true)) {
            throw UnsupportedDtoDefinitionException::unsupportedBuiltinType($class, $parameter->getName(), $scalarType);
        }

        return [
            'name' => $parameter->getName(),
            'scalarType' => $scalarType,
            'enumClass' => $enumClass,
            'dtoClass' => $dtoClass,
            'nestedPlan' => $nestedPlan,
            'listItem' => $listItem,
            'objectMap' => $objectMap,
            'absent' => $absent !== null,
            'hasDefault' => $parameter->isDefaultValueAvailable(),
            'defaultValue' => ParameterDefault::capture($parameter, $class),
            // An untyped parameter accepts anything, null included. A
            // presence union answers for its own value type: `null` is
            // permitted only where the declaration names it, never merely
            // because Absent made the type a union.
            'allowsNull' => $absent !== null ? $absent[1] : ($type === null || $type->allowsNull()),
            'constraints' => self::collectConstraints($parameter),
        ];
    }

    /**
     * The value type behind a `T|Absent`/`T|null|Absent` presence union,
     * and whether that union declares `null` — or null when $parameter
     * declares no union at all, which is every other parameter and the
     * common case.
     *
     * Public for Kinetis\Validation\JsonSchema, which has to describe the
     * same parameter and must reach exactly the same answer: the schema
     * publishes T (widened with `null` where declared), never Absent, and
     * marks the member optional because the declaration carries a default.
     * A second implementation of these rules could publish a union the
     * hydrator does not accept.
     *
     * Union members are matched by name, never by position: `string|Absent`
     * and `Absent|string` are the same declaration, and nothing here reads
     * the order Reflection reports them in. Four declarations are refused
     * rather than given an approximate meaning: the marker with no value
     * type beside it, two value types beside it, no default at all
     * (nothing but the default can ever produce the marker, so the field
     * could never hold it), and a default that is not Absent::Value. A
     * union with no Absent member, and any intersection, remain the plain
     * composite-type rejection they already were.
     *
     * @return array{0: ReflectionNamedType, 1: bool}|null
     * @throws UnsupportedDtoDefinitionException
     */
    public static function absentUnion(ReflectionParameter $parameter, string $owner): ?array
    {
        $type = $parameter->getType();
        $name = $parameter->getName();

        // `Absent` and `?Absent` are named types, not unions: PHP folds a
        // two-member `X|null` back into a nullable named type. Either way
        // the declaration names the marker and no value, which is the
        // same mistake a union spelling it would make.
        if ($type instanceof ReflectionNamedType && $type->getName() === Absent::class) {
            throw UnsupportedDtoDefinitionException::absentUnionWithoutValueType($owner, $name);
        }

        if (!$type instanceof ReflectionUnionType) {
            return null;
        }

        $valueTypes = [];
        $allowsNull = false;
        $hasAbsent = false;

        foreach ($type->getTypes() as $member) {
            // A DNF union member (`(A&B)|null`) is an intersection, which
            // no hydrated value shape corresponds to.
            if (!$member instanceof ReflectionNamedType) {
                throw UnsupportedDtoDefinitionException::compositeType($owner, $name);
            }

            if ($member->getName() === 'null') {
                $allowsNull = true;
            } elseif ($member->getName() === Absent::class) {
                $hasAbsent = true;
            } else {
                $valueTypes[] = $member;
            }
        }

        if (!$hasAbsent) {
            throw UnsupportedDtoDefinitionException::compositeType($owner, $name);
        }

        if (count($valueTypes) > 1) {
            $names = array_map(static fn (ReflectionNamedType $t): string => $t->getName(), $valueTypes);
            // Reflection reports union members in its own order, which is
            // neither the declaration's nor stable across the shapes a
            // union can take; sorting makes one declaration produce one
            // message.
            sort($names);

            throw UnsupportedDtoDefinitionException::absentUnionWithMultipleValueTypes(
                $owner,
                $name,
                implode(' and ', $names),
            );
        }

        if (!$parameter->isDefaultValueAvailable()) {
            throw UnsupportedDtoDefinitionException::absentUnionWithoutDefault($owner, $name);
        }

        /** @var mixed $default */
        $default = $parameter->getDefaultValue();

        if ($default !== Absent::Value) {
            throw UnsupportedDtoDefinitionException::absentUnionWithWrongDefault(
                $owner,
                $name,
                get_debug_type($default),
            );
        }

        // A union always carries at least two members, and PHP folds the
        // two-member `Absent|null` back into the named `?Absent` refused
        // above — so a union reaching here names at least one value type.
        /** @var non-empty-list<ReflectionNamedType> $valueTypes */
        return [$valueTypes[0], $allowsNull];
    }

    /**
     * The half of one parameter's plan its declared type and #[ListOf]
     * decide, as `[enum backing type, enum class, DTO class, that DTO's
     * own inline plan, list item descriptor]`. A non-instantiable
     * nested class keeps its class name with a null plan — the field
     * then accepts an existing instance and nothing else.
     *
     * The first element is filled only for a backed enum, whose wire
     * value is the scalar its cases are written in; every other scalar
     * type is read from the declaration by the caller.
     *
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return array{0: ?string, 1: ?class-string, 2: ?class-string, 3: ?array<string, mixed>, 4: ?array<string, mixed>}
     * @throws UnsupportedDtoDefinitionException
     */
    private static function compileNesting(?ReflectionType $type, ReflectionParameter $parameter, string $class, array $visiting): array
    {
        $name = $parameter->getName();

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw UnsupportedDtoDefinitionException::compositeType($class, $name);
        }

        $item = self::listItem($parameter, $type);

        if ($item !== null) {
            $itemClass = $item['dtoClass'];

            return [null, null, null, null, [
                'scalarType' => $item['scalarType'],
                'enumClass' => $item['enumClass'],
                'dtoClass' => $itemClass,
                // An upload element has no constructor to compile and
                // takes an instance as given, exactly as a single
                // upload field does — the same null plan a
                // non-instantiable class-typed field already carries.
                'nestedPlan' => $itemClass === null || !self::isInstantiable($itemClass)
                    ? null
                    : self::compileNestedPlan($itemClass, $class, $name, $visiting),
                'constraints' => $item['constraints'],
            ]];
        }

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [null, null, null, null, null];
        }

        /** @var class-string $nestedClass */
        $nestedClass = $type->getName();

        if (!class_exists($nestedClass) && !interface_exists($nestedClass)) {
            throw UnsupportedDtoDefinitionException::unresolvableClass($class, $name, $nestedClass);
        }

        $backingType = self::backedEnumScalarType($nestedClass, $parameter);

        if ($backingType !== null) {
            return [$backingType, $nestedClass, null, null, null];
        }

        return self::isInstantiable($nestedClass)
            ? [null, null, $nestedClass, self::compileNestedPlan($nestedClass, $class, $name, $visiting), null]
            : [null, null, $nestedClass, null, null];
    }

    /**
     * The element domain one parameter's #[ListOf] admits, plus the
     * #[Each] rules every element runs — or null when the parameter
     * declares no list at all. Exactly one of `scalarType`,
     * `enumClass` and `dtoClass` names a scalar element, a backed-enum
     * one and a class one respectively; for an enum, `scalarType`
     * additionally carries the backing type its wire value has. A
     * `dtoClass` of UploadedFileInterface is the one class element that
     * is not a DTO: it holds an uploaded file, taken as given after its
     * transport status passes.
     *
     * Public so Kinetis\Validation\JsonSchema describes exactly what
     * hydration accepts: `items` is built from this same
     * classification, and a declaration refused here is refused there,
     * in the same words, rather than published as a schema no request
     * could ever satisfy.
     *
     * $type is the parameter's value type, already resolved through any
     * presence union, for the same reason every other question about
     * the parameter is asked of that type.
     *
     * @return array{scalarType: ?string, enumClass: ?class-string, dtoClass: ?class-string, constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>}|null
     * @throws UnsupportedDtoDefinitionException
     */
    public static function listItem(ReflectionParameter $parameter, ?ReflectionType $type): ?array
    {
        $listOf = $parameter->getAttributes(ListOf::class);
        $each = $parameter->getAttributes(Each::class);

        if ($listOf === []) {
            if ($each !== []) {
                throw UnsupportedDtoDefinitionException::eachWithoutListOf(self::owner($parameter), $parameter->getName());
            }

            return null;
        }

        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'array') {
            throw UnsupportedDtoDefinitionException::listOfOnNonArrayParameter(self::owner($parameter), $parameter->getName());
        }

        $kind = self::listItemKind($listOf[0]->newInstance()->itemType(), $parameter);

        return [
            'scalarType' => $kind['scalarType'],
            'enumClass' => $kind['enumClass'],
            'dtoClass' => $kind['dtoClass'],
            'constraints' => self::itemRules($each, $parameter, $kind['dtoClass']),
        ];
    }

    /**
     * Which element family #[ListOf] named. Everything else is refused
     * here: an empty name, a builtin with no element vocabulary, a name
     * no class answers to, and a class no wire value could ever
     * produce — an interface, an abstract class, a unit enum.
     * UploadedFileInterface is the single named exception: a repeated
     * file control is a real wire shape, so a list of it is a real
     * element domain even though the interface cannot be instantiated.
     *
     * @return array{scalarType: ?string, enumClass: ?class-string, dtoClass: ?class-string}
     * @throws UnsupportedDtoDefinitionException
     */
    private static function listItemKind(string $itemType, ReflectionParameter $parameter): array
    {
        if (in_array($itemType, self::LIST_ITEM_SCALAR_TYPES, true)) {
            return ['scalarType' => $itemType, 'enumClass' => null, 'dtoClass' => null];
        }

        $backingType = self::backedEnumScalarType($itemType, $parameter);

        if ($backingType !== null) {
            /** @var class-string $enumClass */
            $enumClass = $itemType;

            return ['scalarType' => $backingType, 'enumClass' => $enumClass, 'dtoClass' => null];
        }

        // The one non-instantiable element type with a wire
        // representation: a repeated file control is a real multipart
        // shape, and Kinetis\Http\Dispatcher hands the normalized
        // branch over as a list of instances. It is admitted by exact
        // name — every other interface, abstract class and unit enum
        // still names an element no request could produce.
        if ($itemType === UploadedFileInterface::class || self::isInstantiable($itemType)) {
            /** @var class-string $dtoClass */
            $dtoClass = $itemType;

            return ['scalarType' => null, 'enumClass' => null, 'dtoClass' => $dtoClass];
        }

        throw UnsupportedDtoDefinitionException::unsupportedListItemType(
            self::owner($parameter),
            $parameter->getName(),
            $itemType,
        );
    }

    /**
     * The scalar type a backed enum's cases are written in, or null
     * when $class is not a backed enum at all.
     *
     * Public so Kinetis\Validation\JsonSchema publishes the `type` a
     * field of that enum actually binds. An enum with no cases is
     * refused wherever it is met: no value can name a case that does
     * not exist, and JSON Schema has no empty `enum` to publish.
     *
     * enum_exists() is what makes the BackedEnum relationship a
     * question about a real enum. The interface satisfies `is_a()`
     * against itself, and its inherited cases() is abstract — asking it
     * for cases is an engine Error, not an empty list — so a field or a
     * #[ListOf] naming the interface itself answers null here and keeps
     * the instance-only shape every other non-instantiable class type
     * has.
     *
     * @throws UnsupportedDtoDefinitionException
     */
    public static function backedEnumScalarType(string $class, ReflectionParameter $parameter): ?string
    {
        if (!enum_exists($class) || !is_a($class, BackedEnum::class, true)) {
            return null;
        }

        if ($class::cases() === []) {
            throw UnsupportedDtoDefinitionException::emptyBackedEnum(
                self::owner($parameter),
                $parameter->getName(),
                $class,
            );
        }

        $backingType = new ReflectionEnum($class)->getBackingType();
        // A backed enum always reports one; is_a() above has already
        // established that this class is one.
        assert($backingType instanceof ReflectionNamedType);

        return $backingType->getName();
    }

    /**
     * One list's #[Each] rules, in declaration order, as the same
     * literal {class, args} descriptors a field rule gets — positional
     * and named arguments kept exactly as written, since that is what
     * the rule is built from. The rule class itself is never
     * constructed here, so arguments it refuses fail where every other
     * rule's do, when the rule is first built.
     *
     * @param list<ReflectionAttribute<Each>> $attributes
     * @param ?class-string $dtoClass the element class of a DTO list, null for any other
     * @return list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>
     * @throws UnsupportedDtoDefinitionException
     */
    private static function itemRules(array $attributes, ReflectionParameter $parameter, ?string $dtoClass): array
    {
        // An upload element is not a DTO whose own fields could carry
        // the rule instead: a rule about one uploaded file has nowhere
        // else to be written, so #[Each] is the declaration for it.
        if ($attributes !== [] && $dtoClass !== null && $dtoClass !== UploadedFileInterface::class) {
            throw UnsupportedDtoDefinitionException::eachOnDtoList(
                self::owner($parameter),
                $parameter->getName(),
                $dtoClass,
            );
        }

        $rules = [];

        foreach ($attributes as $attribute) {
            $each = $attribute->newInstance();
            $constraint = $each->constraint();

            if (!is_a($constraint, Constraint::class, true)) {
                throw UnsupportedDtoDefinitionException::eachNotAConstraint(
                    self::owner($parameter),
                    $parameter->getName(),
                    $constraint,
                );
            }

            $rules[] = ['class' => $constraint, 'args' => $each->arguments()];
        }

        return $rules;
    }

    /**
     * The class a definition failure names: the one whose constructor
     * declares $parameter. A DTO field always has one; a method's own
     * parameter list, which JsonSchema describes through the same
     * classification, falls back to the function's own name.
     */
    private static function owner(ReflectionParameter $parameter): string
    {
        return $parameter->getDeclaringClass()?->getName() ?? $parameter->getDeclaringFunction()->getName();
    }

    /**
     * Whether this parameter carries #[ObjectMap], having first refused
     * the two declarations that cannot mean anything: a type other than
     * builtin `array`, since the attribute exists only to pick which
     * JSON shape an `array` field admits and no other type offers that
     * choice; and #[ListOf] on the same parameter, whose JSON array is
     * precisely the shape #[ObjectMap] refuses. A union type is already
     * rejected by compileNesting(), which runs first.
     *
     * The boolean is the whole plan entry: the attribute takes no
     * options, so nothing else about it has to survive into an
     * artifact.
     *
     * @param class-string $class
     * @throws UnsupportedDtoDefinitionException
     */
    private static function compileObjectMap(?ReflectionType $type, ReflectionParameter $parameter, string $class, bool $isList): bool
    {
        if ($parameter->getAttributes(ObjectMap::class) === []) {
            return false;
        }

        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'array') {
            throw UnsupportedDtoDefinitionException::objectMapOnNonArrayParameter($class, $parameter->getName());
        }

        if ($isList) {
            throw UnsupportedDtoDefinitionException::objectMapWithListOf($class, $parameter->getName());
        }

        return true;
    }

    /**
     * @param class-string $nested
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return HydrationPlan
     * @throws UnsupportedDtoDefinitionException
     * @throws UnsupportedDefaultValueException
     */
    private static function compileNestedPlan(string $nested, string $class, string $parameter, array $visiting): array
    {
        if (isset($visiting[$nested])) {
            throw UnsupportedDtoDefinitionException::recursiveDefinition($class, $parameter, $nested);
        }

        return self::compilePlan($nested, $visiting);
    }

    private static function isInstantiable(string $class): bool
    {
        return (class_exists($class) || interface_exists($class))
            && new ReflectionClass($class)->isInstantiable();
    }

    /**
     * Public specifically so Kinetis\Http\Dispatcher can collect the
     * identical constraint descriptors for a #[Query]/path parameter —
     * closing the gap where #[GreaterThan]/#[In]/etc. worked on a #[Body]
     * DTO field but were silently no-ops anywhere else.
     *
     * @return list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>
     */
    public static function collectConstraints(ReflectionParameter $parameter): array
    {
        $constraints = [];

        foreach ($parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $constraints[] = [
                'class' => $attribute->getName(),
                'args' => $attribute->getArguments(),
            ];
        }

        return $constraints;
    }

    /**
     * A DTO class's own ObjectConstraint attributes, captured as the same
     * literal {class, args} descriptors a field rule gets, so a plan
     * stays plain data an artifact can hold.
     *
     * Public for the same reason collectConstraints() is: Kinetis\Validation\JsonSchema
     * asks the identical attributes for their schema keywords, from the
     * identical descriptors, so a published document and an enforced rule
     * cannot describe different rule sets.
     *
     * Each rule is built here once, asked which constructor fields it
     * names, and discarded — the descriptor is what leaves this method.
     * That check belongs here because both callers pass through it: a
     * rule naming a field $class's constructor does not declare fails
     * before a plan or a schema is ever accepted, instead of publishing
     * an anyOf no request could satisfy or silently never matching. It
     * also runs the rule's own constructor, so arguments the rule itself
     * refuses fail where the definition is compiled too.
     *
     * @param ReflectionClass<object> $class
     * @return list<array{class: class-string<ObjectConstraint>, args: array<int|string, mixed>}>
     * @throws UnsupportedDtoDefinitionException
     */
    public static function collectObjectRules(ReflectionClass $class): array
    {
        $attributes = $class->getAttributes(ObjectConstraint::class, ReflectionAttribute::IS_INSTANCEOF);

        if ($attributes === []) {
            return [];
        }

        $declared = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $class->getConstructor()?->getParameters() ?? [],
        );

        $rules = [];

        foreach ($attributes as $attribute) {
            foreach ($attribute->newInstance()->fields() as $field) {
                if (!in_array($field, $declared, true)) {
                    throw UnsupportedDtoDefinitionException::objectRuleUnknownField(
                        $class->getName(),
                        $attribute->getName(),
                        $field,
                    );
                }
            }

            $rules[] = [
                'class' => $attribute->getName(),
                'args' => $attribute->getArguments(),
            ];
        }

        return $rules;
    }

    /**
     * The one hydration algorithm both the live and compiled paths share —
     * the only difference between them is how $plan was obtained. Needs no
     * Reflection object at all: `new $className(...$arguments)` supports
     * named-argument construction from a string-keyed array the same way
     * ReflectionClass::newInstanceArgs() did.
     *
     * @param HydrationPlan $plan
     * @param array<string, mixed> $data
     * @throws ValidationException
     */
    private static function hydrateFromPlan(array $plan, array $data, InputSource $source): object
    {
        /** @var class-string $className */
        $className = $plan['className'];

        $violations = [];
        $arguments = [];
        $supplied = [];

        foreach ($plan['parameters'] as $parameter) {
            $name = $parameter['name'];

            if (!array_key_exists($name, $data)) {
                if ($parameter['hasDefault']) {
                    $arguments[$name] = $parameter['defaultValue'];
                } else {
                    $violations[] = self::requiredViolation([$name]);
                }

                continue;
            }

            // Supplied means the input named the member, whatever it said
            // about it — an explicit null included. An object rule reading
            // presence needs the client's own answer, not the resolved
            // value's; and a member that failed never reaches a rule at
            // all, since no rule runs after a field failure.
            $supplied[] = $name;

            [$value, $valueViolations] = self::resolveParameterValue($name, $data[$name], $parameter, $source);

            if ($valueViolations !== []) {
                $violations = [...$violations, ...$valueViolations];

                continue;
            }

            $arguments[$name] = $value;
        }

        $violations = [...$violations, ...self::unknownMemberViolations($plan, $data, $source)];

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        $object = $plan['hasConstructor'] ? new $className(...$arguments) : new $className();
        $ruleViolations = self::objectRuleViolations($plan, $object, $supplied);

        if ($ruleViolations !== []) {
            throw ValidationException::fromViolations($ruleViolations);
        }

        return $object;
    }

    /**
     * Every member of $data the DTO does not declare — under
     * InputSource::Json, and only there.
     *
     * A JSON document and an MCP tool call describe an object whose
     * members are exactly the DTO's own, so a member outside that set is
     * something the client believes it is sending and the application
     * will never read: a misspelling, a field that was renamed, a value
     * meant for a different endpoint. Silently discarding it makes the
     * request look accepted while doing none of what it asked, which is
     * why the generated schema says `additionalProperties: false` and
     * why this makes that true.
     *
     * The other two sources stay open, and not by omission. A
     * form-encoded or multipart body legitimately carries members no DTO
     * declares — a CSRF token, the submit button's own name, a honeypot
     * field — and Kinetis\QueryBuilder hands hydrate() whole database
     * rows whose columns are wider than the DTO reading them. Closing
     * either would break input that is doing nothing wrong.
     *
     * Reported in the input's own order, after the declared members'
     * failures, so one request produces one deterministic list however
     * the two kinds interleave.
     *
     * @param HydrationPlan $plan
     * @param array<string, mixed> $data
     * @return list<Violation>
     */
    private static function unknownMemberViolations(array $plan, array $data, InputSource $source): array
    {
        if ($source !== InputSource::Json) {
            return [];
        }

        $declared = [];

        foreach ($plan['parameters'] as $parameter) {
            $declared[$parameter['name']] = true;
        }

        $violations = [];

        foreach (array_keys($data) as $member) {
            if (!isset($declared[$member])) {
                $violations[] = self::unexpectedFieldViolation([$member]);
            }
        }

        return $violations;
    }

    /**
     * The DTO's own ObjectConstraint attributes, run against the
     * constructed object.
     *
     * They run last, and only on a DTO that fully succeeded: a
     * cross-field rule reads real field values, and after a field failure
     * the only values available are the declaration's own defaults, so a
     * rule running then would report on something the client never sent.
     * They are also the only rules that see presence — which members the
     * input actually named — because that is the one thing a constructed
     * object cannot be asked. See ValidationContext.
     *
     * Each rule is constructed from its literal {class, args} descriptor,
     * asked once, and discarded, exactly as a field rule is; the context
     * is built for this one hydration and outlives nothing. A rule that
     * yields anything but a Violation has broken its own contract, which
     * is a definition failure, never a client response.
     *
     * @param HydrationPlan $plan
     * @param list<string> $supplied
     * @return list<Violation>
     * @throws UnsupportedDtoDefinitionException
     */
    private static function objectRuleViolations(array $plan, object $object, array $supplied): array
    {
        if ($plan['objectRules'] === []) {
            return [];
        }

        $context = new ValidationContext($supplied);
        $violations = [];

        foreach ($plan['objectRules'] as $descriptor) {
            $ruleClass = $descriptor['class'];

            /** @var mixed $violation */
            foreach (new $ruleClass(...$descriptor['args'])->validate($object, $context) as $violation) {
                if (!$violation instanceof Violation) {
                    throw UnsupportedDtoDefinitionException::objectRuleYieldedNonViolation(
                        $plan['className'],
                        $ruleClass,
                        get_debug_type($violation),
                    );
                }

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Resolves one parameter's raw value into its hydrated, fully
     * validated form — a nested DTO, a backed enum case, a typed list,
     * an object map, or a cast scalar — matching whichever of
     * $parameter's dtoClass/enumClass/listItem/objectMap/plain-scalar
     * shape applies. A non-empty second element means the parameter
     * failed; the caller merges those violations into its own list and
     * binds no argument for it.
     *
     * $source threads through both recursive branches: a form-encoded
     * body reaching a nested or list DTO's own scalar fields (via PHP's
     * bracket-style `field[sub]=value` convention) is written in exactly
     * the same vocabulary as the top level.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveParameterValue(string $name, mixed $value, array $parameter, InputSource $source): array
    {
        // Absent marks a member the input did not mention, so the input
        // cannot be the thing that produces it. Checked before the shape
        // below rather than left to it: for most value types the marker
        // already fails the type check, but a declaration whose T is a
        // supertype of an enum case (`UnitEnum|Absent`) would otherwise
        // accept the marker as a real value and read an omission the
        // client never made.
        if ($parameter['absent'] && $value === Absent::Value) {
            return [null, [self::mismatch([$name], self::expectedWireType($parameter), $value)]];
        }

        // Null is decided before any shape is examined. For a parameter
        // whose declared type doesn't accept it, null would otherwise
        // slip past every check below (each exempts it) and reach the
        // constructor as a raw TypeError. For one that does, null is the
        // value, whatever the declared shape — and no rule runs against
        // it, since a rule describes what a present value must look like
        // and the declared type has already said null is allowed.
        if ($value === null) {
            return $parameter['allowsNull'] ? [null, []] : [null, [self::nullNotAllowedViolation([$name])]];
        }

        if ($parameter['dtoClass'] === UploadedFileInterface::class) {
            // Returned rather than falling through to the shared
            // constraint pass below: the upload path runs the field's
            // own rules itself, after the transport status gate, so
            // they run exactly once and never against a file that
            // failed to arrive.
            return self::resolveUploadedFile([$name], $value, $parameter['constraints']);
        }

        if ($parameter['dtoClass'] !== null) {
            /** @var HydrationPlan|null $nestedPlan */
            $nestedPlan = $parameter['nestedPlan'];

            $resolved = self::resolveClassTypedValue([$name], $value, $parameter['dtoClass'], $nestedPlan, $source);
        } elseif ($parameter['enumClass'] !== null) {
            // The enum path runs the field's own rules itself, against
            // the case it resolved — the value the field holds, and the
            // one a rule about that field describes.
            return self::resolveEnumValue(
                $source,
                [$name],
                $value,
                $parameter['enumClass'],
                $parameter['scalarType'],
                $parameter['allowsNull'],
                $parameter['constraints'],
            );
        } elseif ($parameter['listItem'] !== null) {
            /** @var HydrationPlanListItem $listItem */
            $listItem = $parameter['listItem'];

            $resolved = self::resolveListValue($name, $value, $listItem, $source);
        } elseif ($parameter['objectMap']) {
            $resolved = self::resolveObjectMapValue($name, $value);
        } else {
            // The one shared raw-scalar path, which runs the field's own
            // constraints itself. Reached by every parameter #[ListOf]
            // and #[ObjectMap] did not claim, `array`/`iterable`
            // included.
            return self::resolveScalar($source, [$name], $value, $parameter['scalarType'], $parameter['allowsNull'], $parameter['constraints']);
        }

        // A constraint reads a hydrated value — #[MinItems] counts
        // elements a #[ListOf] field has already built — so it runs only
        // once that value exists. A parameter that failed to resolve has
        // nothing for a rule to describe.
        return $resolved[1] !== []
            ? $resolved
            : [$resolved[0], self::constraintViolations($parameter['constraints'], $resolved[0], [$name])];
    }

    /**
     * The one raw-scalar resolution path, entered by every source: a
     * #[Body] DTO field via resolveParameterValue() above, a
     * #[Query]/path parameter via Kinetis\Http\Dispatcher, and an MCP
     * tool argument via Kinetis\Mcp\McpDispatcher. Null, the
     * declared-type check for the value's own source, that source's own
     * normalization, the cast, and the field's own constraints happen
     * here, in that order, so no source can end up applying a different
     * order or reaching a different answer.
     *
     * Presence is not here. Whether a value is absent, and what an
     * absent one means, is knowable only where it was read: a query key
     * that never appeared and a DTO member missing from a decoded object
     * are different facts with their own answers. A caller resolves
     * absence first and calls this with the value it actually has.
     *
     * A constraint that throws is a programmer or server error, not a
     * client violation: it propagates as an ordinary exception.
     *
     * @param list<string|int> $path the caller's own path to this value
     * @param list<array{class: class-string<Constraint>, args: array<int|string, mixed>}> $constraints
     * @return array{0: mixed, 1: list<Violation>} the resolved value, and
     *         the violations it raised — a non-empty list means the value
     *         is unusable and the caller must bind nothing for it
     */
    public static function resolveScalar(
        InputSource $source,
        array $path,
        mixed $value,
        ?string $scalarType,
        bool $allowsNull,
        array $constraints = [],
    ): array {
        if ($value === null) {
            return $allowsNull ? [null, []] : [null, [self::nullNotAllowedViolation($path)]];
        }

        if ($scalarType !== null) {
            $violation = self::typeMismatchViolation($path, $scalarType, $value, $source);

            if ($violation !== null) {
                return [null, [$violation]];
            }
        }

        $value = self::normalizeForSource($source, $scalarType, $value);

        // The type check above (for array/iterable specifically) runs
        // against the still-JsonObject-marked value, so it can correctly
        // reject an object-shaped wire value — but the value a `mixed`
        // field (or an array/iterable field's own nested contents, which
        // JsonTree::convert() may have marked at any depth) actually
        // receives must never leak that marker: unwrap() recursively
        // restores the plain-array tree application code has always
        // seen. A no-op for anything that was never marked at all (a
        // #[Query]/path scalar, or a Native hydrate() call that never
        // went through JsonTree::convert()).
        $value = self::castScalar(JsonTree::unwrap($value), $scalarType);

        return [$value, self::constraintViolations($constraints, $value, $path)];
    }

    /**
     * The one uploaded-file resolution path, the counterpart
     * {@see resolveScalar()} is for a raw scalar. Every typed route
     * into an uploaded file enters here: a #[Body] DTO field declaring
     * UploadedFileInterface, one element of a
     * #[ListOf(UploadedFileInterface::class)] field, and an
     * UploadedFileInterface-typed controller parameter via
     * Kinetis\Http\Dispatcher — so the transport status, the rule
     * order and the violation vocabulary are the same answer wherever a
     * file is bound.
     *
     * The order is what makes the rest safe. A value that is not an
     * instance at all gets the ordinary instance violation; a file
     * whose PSR-7 status is not UPLOAD_ERR_OK gets one `upload_failed`
     * carrying the raw status as `error`, and nothing further is asked
     * of it. Only a file that actually arrived reaches its own rules,
     * so no rule — and no stream read behind one — ever runs against a
     * file whose stream would throw.
     *
     * One code covers every non-OK status. The supported multipart
     * parser produces exactly UPLOAD_ERR_OK and UPLOAD_ERR_NO_FILE, and
     * a NO_FILE leaf is already omission by the time a value reaches
     * here; a status from anywhere else describes a server or client
     * transfer that did not complete, which is one fact a client can
     * act on, with `error` keeping the exact status for a log.
     *
     * Nothing is retained: the rules are built from their literal
     * descriptors, asked, and discarded, exactly as a scalar field's
     * are.
     *
     * @param list<string|int> $path the caller's own path to this value
     * @param list<array{class: class-string<Constraint>, args: array<int|string, mixed>}> $constraints
     * @return array{0: mixed, 1: list<Violation>} the file, and the
     *         violations it raised — a non-empty list means the caller
     *         must bind nothing for it
     */
    public static function resolveUploadedFile(array $path, mixed $value, array $constraints = []): array
    {
        if (!$value instanceof UploadedFileInterface) {
            return [null, [self::notAnInstanceViolation($path, UploadedFileInterface::class)]];
        }

        $error = $value->getError();

        if ($error !== UPLOAD_ERR_OK) {
            return [null, [new Violation($path, self::CODE_UPLOAD_FAILED, self::UPLOAD_FAILED, ['error' => $error])]];
        }

        return [$value, self::constraintViolations($constraints, $value, $path)];
    }

    /**
     * The one source-specific rewrite there is: `true`/`false` spelled
     * as text. A query string, a path segment and a form-encoded body
     * have no boolean literal, and OpenAPI documents those two words as
     * a boolean's textual spelling — so once the type check above has
     * accepted one, it becomes the boolean it spells, and castScalar()
     * never meets the string `"false"`, whose `(bool)` cast is `true`.
     *
     * `bool`'s `"1"`/`"0"` spellings need no rewrite: they cast
     * correctly as raw strings. Nothing else, from any source, is
     * rewritten at all — a JSON body's own string `"true"` never
     * reaches here, having already been refused as a string where a
     * boolean was declared.
     */
    private static function normalizeForSource(InputSource $source, ?string $scalarType, mixed $value): mixed
    {
        if ($source !== InputSource::Text || $scalarType !== 'bool' || !is_string($value)) {
            return $value;
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * An explicitly-null value for a target whose declared type does not
     * accept one. Public for Kinetis\Mcp\McpDispatcher, which decides
     * null for a DTO-typed tool argument before it examines the value's
     * shape, exactly as resolveParameterValue() does for a #[Body] DTO
     * field.
     *
     * @param list<string|int> $path
     */
    public static function nullNotAllowedViolation(array $path): Violation
    {
        return new Violation($path, self::CODE_NULL_NOT_ALLOWED, 'must not be null.');
    }

    /**
     * The #[ObjectMap] branch of resolveParameterValue(): a JSON object,
     * of any keys, unwrapped into the plain array the property receives.
     *
     * Provenance is the whole check. A JSON object and a JSON array
     * decode to the same PHP array — `{}` and `[]` most visibly — so
     * only the JsonObject marker JsonTree::convert() puts on every JSON
     * object distinguishes them, and a value that never carried the
     * marker cannot be read as an object without guessing. A
     * form-encoded body and a direct Hydrator::hydrate() call with a
     * hand-built array both fall on that side: neither source has a JSON
     * object to preserve, so neither fills an #[ObjectMap] property.
     *
     * unwrap() is recursive, so nested objects inside the map reach the
     * property as plain arrays too, exactly like a `mixed` field's own
     * contents.
     *
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveObjectMapValue(string $name, mixed $value): array
    {
        if (!$value instanceof JsonObject) {
            return [null, [self::objectMapShapeViolation([$name], $value)]];
        }

        return [JsonTree::unwrap($value), []];
    }

    /**
     * A value that had to be a JSON object and wasn't. An array names
     * the real problem — inside the marked pipeline it is a genuine JSON
     * array, `[]` included — rather than describeType()'s generic label.
     *
     * @param list<string|int> $path
     */
    private static function objectMapShapeViolation(array $path, mixed $value): Violation
    {
        return is_array($value)
            ? new Violation($path, self::CODE_NOT_A_JSON_OBJECT, self::NOT_A_JSON_OBJECT)
            : self::objectExpectedViolation($path, $value);
    }

    /**
     * One class-typed value — a nested DTO field under its own field name,
     * or one #[ListOf] element under its own ["field", index] path. Two
     * shapes are accepted and nothing else: an object-shaped value — a
     * JsonObject marker or a map-shaped PHP array — hydrated into $class
     * against $plan; or a value that is already a $class instance, taken
     * as given (a caller hydrating from data it partly built itself).
     * $plan is null exactly when $class cannot be instantiated, so no
     * value could ever be hydrated into it and an instance is the only
     * accepted shape. UploadedFileInterface never reaches here: both the
     * field and the element branch route it to resolveUploadedFile()
     * first, which gates its transport status before anything else.
     *
     * @param list<string|int> $path
     * @param class-string $class
     * @param HydrationPlan|null $plan
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveClassTypedValue(array $path, mixed $value, string $class, ?array $plan, InputSource $source): array
    {
        if ($value instanceof $class) {
            return [$value, []];
        }

        // Object-shaped is exactly two spellings. A genuine JSON object
        // arrives marked as a JsonObject once JsonTree::convert() is in
        // the picture (see Dispatcher's own decodeJsonBody() docblock);
        // a direct hydrate() call, or a form-decoded body, spells the
        // same thing as a map-shaped PHP array. A list-shaped array is
        // never object-shaped — inside the marked pipeline it is a
        // genuine JSON array, `[]` included, since convert() marks every
        // JSON object and leaves every JSON array plain.
        $data = match (true) {
            $value instanceof JsonObject => $value->toArray(),
            is_array($value) && !array_is_list($value) => $value,
            default => null,
        };

        if ($plan === null || $data === null) {
            return [null, [self::classTypedMismatchViolation($path, $value, $class, $plan !== null)]];
        }

        try {
            return [self::hydrateFromPlan($plan, $data, $source), []];
        } catch (ValidationException $e) {
            $nested = [];

            foreach ($e->violations as $violation) {
                $nested[] = $violation->under(...$path);
            }

            return [null, $nested];
        }
    }

    /**
     * A value that reaches here as an array is always list-shaped — a
     * map-shaped one has already hydrated — so it names the real problem
     * (a JSON array where the schema declares an object) rather than
     * describeType()'s generic label.
     *
     * @param list<string|int> $path
     * @param class-string $class
     */
    private static function classTypedMismatchViolation(array $path, mixed $value, string $class, bool $hydratable): Violation
    {
        if (!$hydratable || is_object($value)) {
            return self::notAnInstanceViolation($path, $class);
        }

        return is_array($value)
            ? new Violation($path, self::CODE_NOT_A_JSON_OBJECT, self::NOT_A_JSON_OBJECT)
            : self::objectExpectedViolation($path, $value);
    }

    /**
     * The listItem branch of resolveParameterValue(): the field's own
     * value must be a real JSON array (the shape its JSON Schema
     * claims), and every element is resolved as one value of the
     * declared element type under its own ["field", index] path.
     *
     * Every element is attempted, so one request reports every bad
     * element rather than only the first. One failure leaves the whole
     * field unresolved, which is what keeps the list's own rules —
     * #[MinItems], an application rule counting or summing elements —
     * from ever running against a list that was never built.
     *
     * @param HydrationPlanListItem $item
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveListValue(string $name, mixed $value, array $item, InputSource $source): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [null, [self::listShapeViolation([$name], $value)]];
        }

        $items = [];
        $violations = [];

        foreach ($value as $index => $element) {
            [$resolved, $elementViolations] = self::resolveListItem([$name, $index], $element, $item, $source);

            if ($elementViolations !== []) {
                $violations = [...$violations, ...$elementViolations];

                continue;
            }

            $items[] = $resolved;
        }

        return $violations !== [] ? [null, $violations] : [$items, []];
    }

    /**
     * One element, resolved as whichever family its #[ListOf] named: an
     * uploaded file through the one shared upload path, a DTO hydrated
     * from an object-shaped element (or taken as an instance) exactly
     * as a single DTO-typed field is, a backed enum read from its
     * backing value, or a scalar read through the one shared
     * raw-scalar path — each the same path the field itself would take,
     * so an element's status check, type check, source normalization
     * and rules cannot drift from a field's.
     *
     * The element's own #[Each] rules travel with it into whichever
     * resolver produces the value, so they run once the element's type
     * is established and never after it failed. No element is nullable:
     * a null one is a violation at its own index rather than an
     * unchecked hole in the list.
     *
     * @param list<string|int> $path
     * @param HydrationPlanListItem $item
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveListItem(array $path, mixed $value, array $item, InputSource $source): array
    {
        if ($item['dtoClass'] === UploadedFileInterface::class) {
            return self::resolveUploadedFile($path, $value, $item['constraints']);
        }

        if ($item['dtoClass'] !== null) {
            /** @var HydrationPlan|null $nestedPlan */
            $nestedPlan = $item['nestedPlan'];

            return self::resolveClassTypedValue($path, $value, $item['dtoClass'], $nestedPlan, $source);
        }

        if ($item['enumClass'] !== null) {
            return self::resolveEnumValue($source, $path, $value, $item['enumClass'], $item['scalarType'], false, $item['constraints']);
        }

        return self::resolveScalar($source, $path, $value, $item['scalarType'], false, $item['constraints']);
    }

    /**
     * One backed-enum value: a field declaring that enum, or one
     * element of a #[ListOf] naming it.
     *
     * An existing case is taken as given, exactly as a class-typed
     * field takes an instance. Anything else is the case's backing
     * value, resolved through resolveScalar() as the scalar the enum is
     * backed by — so a JSON body stays strict, a query string or form
     * body keeps its textual spellings, the integer-range and
     * finite-number checks still apply, and tryFrom() only ever sees a
     * correctly typed primitive rather than raising a TypeError under
     * strict_types. A correctly typed value naming no case is one
     * violation at this path.
     *
     * Rules run against the resolved case, which is the value the field
     * or element holds.
     *
     * @param list<string|int> $path
     * @param class-string $enumClass
     * @param list<array{class: class-string<Constraint>, args: array<int|string, mixed>}> $constraints
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveEnumValue(
        InputSource $source,
        array $path,
        mixed $value,
        string $enumClass,
        ?string $backingType,
        bool $allowsNull,
        array $constraints,
    ): array {
        if ($value === null) {
            return $allowsNull ? [null, []] : [null, [self::nullNotAllowedViolation($path)]];
        }

        if ($value instanceof $enumClass) {
            return [$value, self::constraintViolations($constraints, $value, $path)];
        }

        [$backing, $violations] = self::resolveScalar($source, $path, $value, $backingType, false);

        if ($violations !== []) {
            return [null, $violations];
        }

        // A plan's enumClass is exactly the backed enum compileNesting()
        // put there, and resolveScalar() has just established the value
        // is the primitive that enum is backed by.
        /** @var class-string<BackedEnum> $enum */
        $enum = $enumClass;
        /** @var int|string $backingValue */
        $backingValue = $backing;
        $case = $enum::tryFrom($backingValue);

        if ($case === null) {
            return [null, [self::enumCaseViolation($path, $enum)]];
        }

        return [$case, self::constraintViolations($constraints, $case, $path)];
    }

    /**
     * A correctly typed backing value naming no case of $enumClass —
     * the same membership vocabulary #[In] speaks, since it is the same
     * question asked of a closed set. The choices are the enum's own
     * backing values, read where the failure happens: a plan holds
     * class names and literals, never case objects.
     *
     * @param list<string|int> $path
     * @param class-string<BackedEnum> $enumClass
     */
    private static function enumCaseViolation(array $path, string $enumClass): Violation
    {
        $choices = array_map(static fn (BackedEnum $case): int|string => $case->value, $enumClass::cases());

        return new Violation(
            $path,
            self::CODE_ENUM_CASE,
            'must be one of: ' . implode(', ', array_map('strval', $choices)) . '.',
            ['choices' => $choices],
        );
    }

    /**
     * The declared-type-mismatch check resolveScalar() runs before
     * castScalar() casts anything — see the class docblock and
     * InputSource for the policy this implements and why. Reached only
     * from there, with a value already known non-null and already
     * normalized for $source.
     *
     * @param list<string|int> $path
     */
    private static function typeMismatchViolation(array $path, string $scalarType, mixed $value, InputSource $source): ?Violation
    {
        return match ($scalarType) {
            'string' => is_string($value) ? null : self::mismatch($path, 'string', $value),
            'int' => self::integerMismatchViolation($path, $value, $source),
            'float' => self::floatMismatchViolation($path, $value, $source),
            'bool' => self::booleanMismatchViolation($path, $value, $source),
            // A plain `array` field (no #[ListOf] — that shape is handled
            // entirely separately, by resolveListValue()) needs this
            // check for the same reason every other builtin type does:
            // without it, a non-array value would reach `new $className(...)`
            // unchecked, surfacing as a raw TypeError instead of the
            // same violation every other builtin type produces.
            // `iterable` gets the identical check: JSON input can only
            // ever decode into an array, never a real Traversable, and
            // a plain PHP array satisfies `iterable` — so the wire
            // contract and the accepted shape are the same as
            // `array`'s.
            'array', 'iterable' => self::listShapeMismatchViolation($path, $value),
            // `mixed` accepts anything by definition. No other builtin
            // reaches here: SUPPORTED_BUILTIN_TYPES is enforced where
            // each plan is built.
            default => null,
        };
    }

    /**
     * `array`/`iterable`'s own truthful wire contract: their JSON Schema
     * representation is `{type: array}`, which means a JSON *array*
     * (`[...]`), not any array-shaped PHP value — including a JSON
     * *object* (`{...}`), and including the empty object `{}`.
     *
     * A JSON object never reaches the `array_is_list()` check below at
     * all — see Dispatcher's own decodeJsonBody() docblock:
     * `Dispatcher`/`McpServer` decode with `associative: false` and run
     * the result through `JsonTree::convert()`, which wraps every JSON
     * object anywhere in the tree — including one with sequential-
     * looking numeric keys (`{"0":"a","1":"b"}`, which would otherwise
     * decode to the identical PHP shape a real JSON array does) and
     * including `{}` — in a `JsonObject` marker, which is an object and
     * therefore never an array here. Only a genuine JSON array (or
     * something that was never JSON-decoded through that pipeline at all
     * — a direct `Hydrator::hydrate()` call with a hand-built PHP array,
     * or a form-decoded body, neither of which carries any
     * JSON-object/array distinction to preserve in the first place) ever
     * reaches `array_is_list()` itself, which remains the correct,
     * precise check for *that* case.
     *
     * @param list<string|int> $path
     */
    private static function listShapeMismatchViolation(array $path, mixed $value): ?Violation
    {
        return is_array($value) && array_is_list($value) ? null : self::listShapeViolation($path, $value);
    }

    /**
     * The one message owner for a value that had to be a JSON array and
     * wasn't — shared by an `array`/`iterable` field and a `#[ListOf]`
     * one, which make the identical claim in their JSON Schema. A
     * `JsonObject` marker, and a map-shaped PHP array (exactly what a
     * JSON object decodes into outside the marked pipeline), both name
     * the real problem rather than describeType()'s generic label.
     *
     * @param list<string|int> $path
     */
    private static function listShapeViolation(array $path, mixed $value): Violation
    {
        return $value instanceof JsonObject || is_array($value)
            ? new Violation($path, self::CODE_NOT_A_JSON_ARRAY, self::NOT_A_JSON_ARRAY)
            : self::mismatch($path, 'array', $value);
    }

    /**
     * `int` accepts a real int under Json and Native, and there a
     * finite float with no fractional part inside the range the `(int)`
     * cast below can represent: JSON has a single number type, so a
     * producer writing `42.0` still wrote the integer 42.
     *
     * A *string* spelled as a plain base-10 integer (`"42"`, `"+42"`,
     * `"-42"`) is an integer's textual spelling, and binds under Text
     * and Native only — under Json a string is a string, and the schema
     * said integer. It is Text's only spelling, text being the only
     * thing that source carries. Such a string is read as written,
     * never through a float: a float has 53 bits of mantissa, so
     * `"1.0000000000000001"` and `"1"` are the same float and only one
     * of them is an integer. A decimal spelling (`"42.0"`), an exponent
     * spelling (`"4.2e1"`) and a whitespace-padded one are all rejected
     * for the same reason `4.5` is — the field declares an integer and
     * gets one, never a value the `(int)` cast has to reinterpret.
     *
     * @param list<string|int> $path
     */
    private static function integerMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        // A native number binds under Json and Native, each of which
        // carries real PHP values. Text carries none: every scalar it
        // holds is a raw string, so an `int` field there binds the
        // textual spelling below and nothing else.
        if ($source !== InputSource::Text) {
            if (is_int($value)) {
                return null;
            }

            if (is_float($value)) {
                // (float) PHP_INT_MAX rounds up to 2**63, one past the
                // largest representable int, so the upper bound is
                // exclusive; PHP_INT_MIN is exactly -2**63 as a float,
                // so the lower one is not.
                $exactInteger = is_finite($value)
                    && $value === floor($value)
                    && $value >= (float) PHP_INT_MIN
                    && $value < (float) PHP_INT_MAX;

                return $exactInteger ? null : self::notAnInteger($path);
            }
        }

        if (is_string($value) && $source !== InputSource::Json) {
            // FILTER_VALIDATE_INT is the base-10 integer-spelling check
            // and the native-range check in one, with no float step in
            // between to round a digit away. It tolerates surrounding
            // whitespace, which a declared integer value does not.
            $spelledAsInteger = $value === trim($value)
                && filter_var($value, FILTER_VALIDATE_INT) !== false;

            return $spelledAsInteger ? null : self::notAnInteger($path);
        }

        return self::mismatch($path, 'integer', $value);
    }

    /**
     * @param list<string|int> $path
     */
    private static function notAnInteger(array $path): Violation
    {
        return new Violation($path, self::CODE_NOT_AN_INTEGER, self::NOT_AN_INTEGER);
    }

    /**
     * `float` accepts either JSON number under Json and Native, and a
     * numeric string — a number's textual spelling, and Text's only one
     * — under Text and Native. It rejects any value that isn't finite:
     * `"1e999"` overflows to INF, which is not a number any consumer of
     * this field can act on.
     *
     * @param list<string|int> $path
     */
    private static function floatMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        // See integerMismatchViolation(): a native number is a spelling
        // Text does not have.
        if ($source !== InputSource::Text) {
            if (is_int($value)) {
                return null;
            }

            if (is_float($value)) {
                return is_finite($value) ? null : self::notFinite($path);
            }
        }

        if (is_string($value) && $source !== InputSource::Json && is_numeric($value)) {
            return is_finite((float) $value) ? null : self::notFinite($path);
        }

        return self::mismatch($path, 'number', $value);
    }

    /**
     * @param list<string|int> $path
     */
    private static function notFinite(array $path): Violation
    {
        return new Violation($path, self::CODE_NOT_FINITE, self::NOT_FINITE);
    }

    /**
     * Each source's own boolean vocabulary. Json takes the JSON literal
     * and nothing else. Text takes the four spellings a query string, a
     * path segment or a form body can write — `true`, `false`, `1`, `0`
     * — as the strings they arrive as, and normalizeForSource() turns
     * the two words into real booleans once this check has accepted
     * them. Native takes real booleans plus the `1`/`0`/`"1"`/`"0"` a
     * database driver produces for a boolean column.
     *
     * @param list<string|int> $path
     */
    private static function booleanMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        $accepted = match ($source) {
            InputSource::Json => [true, false],
            InputSource::Text => ['true', 'false', '1', '0'],
            InputSource::Native => [true, false, 0, 1, '0', '1'],
        };

        if (in_array($value, $accepted, true)) {
            return null;
        }

        return self::mismatch($path, 'boolean', $value);
    }

    /**
     * One declared-type mismatch: the sentence a client reads, plus the
     * expected/given pair a renderer needs to rebuild or translate it.
     * $expected is exactly one of `string`, `integer`, `number`,
     * `boolean`, `array` and `object` — the closed set whose English
     * article the initial-vowel test below answers correctly.
     *
     * @param list<string|int> $path
     */
    private static function mismatch(array $path, string $expected, mixed $value): Violation
    {
        $given = self::describeType($value);
        $article = str_contains('aeiou', $expected[0]) ? 'an ' : 'a ';

        return new Violation(
            $path,
            self::CODE_TYPE_MISMATCH,
            'must be ' . $article . $expected . ', ' . $given . self::GIVEN_SUFFIX,
            ['expected' => $expected, 'given' => $given],
        );
    }

    /**
     * An object that is not the declared class, for a target that takes
     * an already-constructed instance and has no way to build one from
     * the value given. Public for Kinetis\Mcp\McpDispatcher, whose
     * DTO-typed tool arguments accept the identical value.
     *
     * @param list<string|int> $path
     * @param class-string $class
     */
    public static function notAnInstanceViolation(array $path, string $class): Violation
    {
        return new Violation(
            $path,
            self::CODE_NOT_AN_INSTANCE,
            'must be a ' . $class . ' instance.',
            ['class' => $class],
        );
    }

    /**
     * A value that had to be an object and wasn't. Public for
     * Kinetis\Mcp\McpDispatcher, whose DTO-typed tool arguments make the
     * identical claim about the identical wire shape.
     *
     * @param list<string|int> $path
     */
    public static function objectExpectedViolation(array $path, mixed $value): Violation
    {
        return self::mismatch($path, 'object', $value);
    }

    /**
     * A defaultless parameter with no value to bind. Public for
     * Kinetis\Http\Dispatcher, which reports a missing #[Query]/path
     * value and a missing uploaded file the same way a DTO field's own
     * missing key is reported.
     *
     * @param list<string|int> $path
     */
    public static function requiredViolation(array $path): Violation
    {
        return new Violation($path, self::CODE_REQUIRED, 'is required.');
    }

    /**
     * A member of a closed input object that names nothing the target
     * declares. Public for Kinetis\Mcp\McpDispatcher, which closes a
     * tool call's own top-level `arguments` object the same way a JSON
     * DTO object is closed here — one code and one sentence, so an agent
     * reads the same answer whether it misspelled a tool argument or a
     * field inside one.
     *
     * @param list<string|int> $path
     */
    public static function unexpectedFieldViolation(array $path): Violation
    {
        return new Violation($path, self::CODE_UNEXPECTED_FIELD, 'is not expected.');
    }

    /**
     * Runs a field's own rules against an already type-checked, already
     * resolved value. Each rule is constructed from its literal
     * `{class, args}` descriptor, asked once, and discarded — nothing
     * about a rule instance survives the call, so none of them can carry
     * state into the next request.
     *
     * A rule answers with its own code, message and parameters; the only
     * thing this adds is where the failure happened, prefixing the
     * owning field's path onto the value-relative one the rule returned.
     *
     * @param list<array{class: class-string<Constraint>, args: array<int|string, mixed>}> $constraints
     * @param list<string|int> $path
     * @return list<Violation>
     */
    private static function constraintViolations(array $constraints, mixed $value, array $path): array
    {
        $violations = [];

        foreach ($constraints as $descriptor) {
            $constraintClass = $descriptor['class'];
            $violation = new $constraintClass(...$descriptor['args'])->validate($value);

            if ($violation !== null) {
                $violations[] = $violation->under(...$path);
            }
        }

        return $violations;
    }

    /**
     * The wire type name a presence union's own value type publishes —
     * the same word its JSON Schema `type` carries, so the sentence a
     * rejected Absent marker produces reads exactly like every other
     * declared-type mismatch on that field. A presence union always names
     * a value type, so one of these branches always applies; `mixed`
     * cannot appear in a PHP union at all.
     *
     * @param HydrationPlanParameter $parameter
     */
    private static function expectedWireType(array $parameter): string
    {
        if ($parameter['dtoClass'] !== null || $parameter['objectMap']) {
            return 'object';
        }

        return match ($parameter['scalarType']) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            'string' => 'string',
            default => 'array',
        };
    }

    private static function describeType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_array($value) => 'array',
            is_bool($value) => 'boolean',
            is_float($value) => 'float',
            is_int($value) => 'integer',
            is_object($value) => 'object',
            default => 'value',
        };
    }

    /**
     * Casts a value that has already passed typeMismatchViolation() to
     * its declared builtin type — every accepted value is exactly
     * representable in it, so no cast here can lose information.
     */
    private static function castScalar(mixed $value, ?string $scalarType): mixed
    {
        if ($scalarType === null || $value === null) {
            return $value;
        }

        return match ($scalarType) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }
}
