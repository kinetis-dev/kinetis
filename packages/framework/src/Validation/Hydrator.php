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
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

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
 * - A parameter typed as a single non-instantiable class (an interface, an
 *   abstract class, an enum): only an existing instance is accepted. No
 *   request value can construct one — the case this exists for is the
 *   UploadedFileInterface Dispatcher merges into a multipart field.
 * - A parameter typed `array` carrying #[ListOf(SomeClass::class)]: a JSON
 *   array whose every element is either object-shaped (hydrated into
 *   SomeClass) or already a SomeClass instance. Element violations
 *   surface under the ["field", index] / ["field", index, "nestedField"]
 *   path.
 * - A parameter typed `array` carrying #[ObjectMap]: a JSON object of
 *   arbitrary keys, handed to the constructor as its plain array form.
 *   Unlike every other accepted shape, this one admits only a value
 *   carrying JsonObject provenance — see resolveObjectMapValue().
 * - A nullable variant of any of the above.
 *
 * Every other definition is rejected with an
 * UnsupportedDtoDefinitionException while the plan is compiled — at build
 * time for an AOT-compiled plan, on the first hydrate() call for a live
 * one: a union or intersection parameter type, a recursive or mutually
 * recursive class reference (a plan embeds each nested class inline, so
 * recursion has no finite plan and nothing var_export() could bake into a
 * cache file), a class type reflection cannot resolve (self/parent/static),
 * a builtin type outside SUPPORTED_BUILTIN_TYPES, #[ListOf] on a parameter
 * that isn't typed `array`, #[ListOf] naming a class that cannot be
 * instantiated, #[ObjectMap] on a parameter that isn't typed `array`, and
 * #[ObjectMap] combined with #[ListOf].
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
 * Every failure this class raises is a Kinetis\Validation\Violation
 * carrying a segmented path, a stable code, the message, and the values
 * that message was built from. resolveScalar() is the one entry every
 * source of a raw scalar goes through — a #[Body] DTO field here, a
 * #[Query]/path parameter via Kinetis\Http\Dispatcher, an MCP tool
 * argument via Kinetis\Mcp\McpDispatcher — so null handling, source
 * normalization, type checking, casting and the field's own constraints
 * all happen once, in one order, and a wrong-shaped value can never
 * reach a real constructor unchecked regardless of which one dispatched
 * it. objectExpectedViolation() and requiredViolation() stay public
 * beside it for the two failures a caller detects before it has a
 * scalar to resolve at all.
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
 * @phpstan-type HydrationPlanParameter array{
 *     name: string,
 *     scalarType: ?string,
 *     dtoClass: ?class-string,
 *     nestedPlan: ?array<string, mixed>,
 *     listItemClass: ?class-string,
 *     listItemPlan: ?array<string, mixed>,
 *     objectMap: bool,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 *     allowsNull: bool,
 *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
 * }
 * @phpstan-type HydrationPlan array{
 *     className: class-string,
 *     hasConstructor: bool,
 *     parameters: list<HydrationPlanParameter>,
 * }
 */
final class Hydrator
{
    private const string GIVEN_SUFFIX = ' given.';

    private const string NOT_A_JSON_ARRAY = 'must be a JSON array, not a JSON object.';

    private const string NOT_A_JSON_OBJECT = 'must be a JSON object, not a JSON array.';

    private const string NOT_FINITE = 'must be a finite number.';

    private const string NOT_AN_INTEGER = 'must be an integer within the platform integer range.';

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

    private const array HYDRATION_PLAN_KEYS = ['className', 'hasConstructor', 'parameters'];

    private const array HYDRATION_PLAN_PARAMETER_KEYS = [
        'name', 'scalarType', 'dtoClass', 'nestedPlan', 'listItemClass', 'listItemPlan',
        'objectMap', 'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
    ];

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

        if ($constructor === null) {
            return ['className' => $class, 'hasConstructor' => false, 'parameters' => []];
        }

        $visiting[$class] = true;
        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = self::compileParameter($parameter, $class, $visiting);
        }

        return ['className' => $class, 'hasConstructor' => true, 'parameters' => $parameters];
    }

    /**
     * Validates a compiled `array<string, HydrationPlan>` map — this
     * class is the one abstraction that owns `HydrationPlan`'s shape
     * (recursive `nestedPlan`/`listItemPlan` included), so this is the
     * one place that shape is ever checked, called by
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
     * One `HydrationPlan` shape, recursing into every parameter's own
     * non-null `nestedPlan`/`listItemPlan` — themselves the identical
     * shape, one level deeper, exactly as `compilePlan()` embeds them.
     * Naturally bounded by the data itself: `compilePlan()` rejects a
     * recursive definition outright, so a circular plan is not
     * producible in the first place.
     *
     * @param array<array-key, mixed> $plan
     * @throws CacheArtifactExceptionInterface
     */
    private static function validatePlan(array $plan): void
    {
        ArtifactValidation::exactKeys($plan, 'HydrationPlan', self::HYDRATION_PLAN_KEYS);

        ArtifactValidation::string($plan, 'HydrationPlan', 'className');
        ArtifactValidation::bool($plan, 'HydrationPlan', 'hasConstructor');
        $parameters = ArtifactValidation::listOfArrays($plan, 'HydrationPlan', 'parameters');

        foreach ($parameters as $parameter) {
            ArtifactValidation::exactKeys($parameter, 'HydrationPlanParameter', self::HYDRATION_PLAN_PARAMETER_KEYS);

            ArtifactValidation::string($parameter, 'HydrationPlanParameter', 'name');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'scalarType');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'dtoClass');
            ArtifactValidation::nullableString($parameter, 'HydrationPlanParameter', 'listItemClass');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'objectMap');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'hasDefault');
            ArtifactValidation::bool($parameter, 'HydrationPlanParameter', 'allowsNull');
            // defaultValue's own presence is already guaranteed by
            // exactKeys() above; its value holds an arbitrary PHP
            // default with no single type to check further.
            ArtifactValidation::listOfConstraintDescriptors($parameter, 'HydrationPlanParameter', 'constraints');

            foreach (['nestedPlan', 'listItemPlan'] as $planField) {
                $nested = $parameter[$planField] ?? null;

                if ($nested === null) {
                    continue;
                }

                if (!is_array($nested)) {
                    throw InvalidCacheArtifactException::wrongFieldType('HydrationPlanParameter', $planField, 'an array or null');
                }

                self::validatePlan($nested);
            }
        }
    }

    /**
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return array{
     *     name: string,
     *     scalarType: ?string,
     *     dtoClass: ?class-string,
     *     nestedPlan: ?array<string, mixed>,
     *     listItemClass: ?class-string,
     *     listItemPlan: ?array<string, mixed>,
     *     objectMap: bool,
     *     hasDefault: bool,
     *     defaultValue: mixed,
     *     allowsNull: bool,
     *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
     * }
     * @throws UnsupportedDtoDefinitionException
     * @throws UnsupportedDefaultValueException
     */
    private static function compileParameter(ReflectionParameter $parameter, string $class, array $visiting): array
    {
        $type = $parameter->getType();
        [$dtoClass, $nestedPlan, $listItemClass, $listItemPlan] = self::compileNesting($type, $parameter, $class, $visiting);
        $objectMap = self::compileObjectMap($type, $parameter, $class, $listItemClass !== null);
        $scalarType = $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null;

        if ($scalarType !== null && !in_array($scalarType, self::SUPPORTED_BUILTIN_TYPES, true)) {
            throw UnsupportedDtoDefinitionException::unsupportedBuiltinType($class, $parameter->getName(), $scalarType);
        }

        return [
            'name' => $parameter->getName(),
            'scalarType' => $scalarType,
            'dtoClass' => $dtoClass,
            'nestedPlan' => $nestedPlan,
            'listItemClass' => $listItemClass,
            'listItemPlan' => $listItemPlan,
            'objectMap' => $objectMap,
            'hasDefault' => $parameter->isDefaultValueAvailable(),
            'defaultValue' => ParameterDefault::capture($parameter, $class),
            // An untyped parameter accepts anything, null included.
            'allowsNull' => $type === null || $type->allowsNull(),
            'constraints' => self::collectConstraints($parameter),
        ];
    }

    /**
     * The class-typed half of one parameter's plan: a nested DTO class and
     * its own inline plan, or a #[ListOf] item class and its own. A
     * non-instantiable nested class keeps its class name with a null plan —
     * the field then accepts an existing instance and nothing else.
     *
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return array{0: ?class-string, 1: ?array<string, mixed>, 2: ?class-string, 3: ?array<string, mixed>}
     * @throws UnsupportedDtoDefinitionException
     */
    private static function compileNesting(?ReflectionType $type, ReflectionParameter $parameter, string $class, array $visiting): array
    {
        $name = $parameter->getName();

        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw UnsupportedDtoDefinitionException::compositeType($class, $name);
        }

        $listOf = $parameter->getAttributes(ListOf::class);

        if ($listOf !== []) {
            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'array') {
                throw UnsupportedDtoDefinitionException::listOfOnNonArrayParameter($class, $name);
            }

            $itemClass = $listOf[0]->newInstance()->itemClass();

            if (!self::isInstantiable($itemClass)) {
                throw UnsupportedDtoDefinitionException::listItemNotInstantiable($class, $name, $itemClass);
            }

            return [null, null, $itemClass, self::compileNestedPlan($itemClass, $class, $name, $visiting)];
        }

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [null, null, null, null];
        }

        /** @var class-string $nestedClass */
        $nestedClass = $type->getName();

        if (!class_exists($nestedClass) && !interface_exists($nestedClass)) {
            throw UnsupportedDtoDefinitionException::unresolvableClass($class, $name, $nestedClass);
        }

        return self::isInstantiable($nestedClass)
            ? [$nestedClass, self::compileNestedPlan($nestedClass, $class, $name, $visiting), null, null]
            : [$nestedClass, null, null, null];
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

        if (!$plan['hasConstructor']) {
            return new $className();
        }

        $violations = [];
        $arguments = [];

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

            [$value, $valueViolations] = self::resolveParameterValue($name, $data[$name], $parameter, $source);

            if ($valueViolations !== []) {
                $violations = [...$violations, ...$valueViolations];

                continue;
            }

            $arguments[$name] = $value;
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return new $className(...$arguments);
    }

    /**
     * Resolves one parameter's raw value into its hydrated, fully
     * validated form — a nested DTO, a list of nested DTOs, an object
     * map, or a cast scalar — matching whichever of $parameter's
     * dtoClass/listItemClass/objectMap/plain-scalar shape applies. A
     * non-empty second element means the parameter failed; the caller
     * merges those violations into its own list and binds no argument
     * for it.
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

        if ($parameter['dtoClass'] !== null) {
            /** @var HydrationPlan|null $nestedPlan */
            $nestedPlan = $parameter['nestedPlan'];

            $resolved = self::resolveClassTypedValue([$name], $value, $parameter['dtoClass'], $nestedPlan, $source);
        } elseif ($parameter['listItemClass'] !== null) {
            $resolved = self::resolveListValue($name, $value, $parameter, $source);
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
     * tool argument via Kinetis\Mcp\McpDispatcher. Null, source
     * normalization, the declared-type check, the cast, and the field's
     * own constraints happen here, in that order, so no source can end
     * up applying a different order or reaching a different answer.
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

        $value = self::normalizeForSource($source, $scalarType, $value);

        if ($scalarType !== null) {
            $violation = self::typeMismatchViolation($path, $scalarType, $value, $source);

            if ($violation !== null) {
                return [null, [$violation]];
            }
        }

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
     * The one source-specific rewrite there is: `true`/`false` spelled
     * as text. A query string, a path segment and a form-encoded body
     * have no boolean literal, and OpenAPI documents those two words as
     * a boolean's textual spelling — so they become real booleans before
     * the shared type check, which then receives an equivalent value
     * rather than a string standing in for one, and castScalar() never
     * meets the string `"false"`, whose `(bool)` cast is `true`.
     *
     * `bool`'s `"1"`/`"0"` spellings need no rewrite: they pass the
     * check as raw strings and cast correctly. Nothing else, from any
     * source, is rewritten at all — a JSON body's own string `"true"`
     * stays a string, and stays a violation.
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
     * @param list<string|int> $path
     */
    private static function nullNotAllowedViolation(array $path): Violation
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
     * as given (the UploadedFileInterface Dispatcher merges into a
     * multipart field, or a caller hydrating from data it partly built
     * itself). $plan is null exactly when $class cannot be instantiated,
     * so no value could ever be hydrated into it and an instance is the
     * only accepted shape.
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
     */
    private static function classTypedMismatchViolation(array $path, mixed $value, string $class, bool $hydratable): Violation
    {
        if (!$hydratable || is_object($value)) {
            return new Violation(
                $path,
                self::CODE_NOT_AN_INSTANCE,
                'must be a ' . $class . ' instance.',
                ['class' => $class],
            );
        }

        return is_array($value)
            ? new Violation($path, self::CODE_NOT_A_JSON_OBJECT, self::NOT_A_JSON_OBJECT)
            : self::objectExpectedViolation($path, $value);
    }

    /**
     * The listItemClass branch of resolveParameterValue(): the field's own
     * value must be a real JSON array (the shape its JSON Schema claims),
     * and every element is resolved as one class-typed value under its own
     * ["field", index] path.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: list<Violation>}
     */
    private static function resolveListValue(string $name, mixed $value, array $parameter, InputSource $source): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [null, [self::listShapeViolation([$name], $value)]];
        }

        /** @var class-string $listItemClass */
        $listItemClass = $parameter['listItemClass'];
        /** @var HydrationPlan|null $listItemPlan */
        $listItemPlan = $parameter['listItemPlan'];
        $items = [];
        $violations = [];

        foreach ($value as $index => $item) {
            [$hydratedItem, $itemViolations] = self::resolveClassTypedValue(
                [$name, $index],
                $item,
                $listItemClass,
                $listItemPlan,
                $source,
            );

            if ($itemViolations !== []) {
                $violations = [...$violations, ...$itemViolations];

                continue;
            }

            $items[] = $hydratedItem;
        }

        return $violations !== [] ? [null, $violations] : [$items, []];
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
     * `int` accepts a real int from every source, and a finite float
     * with no fractional part inside the range the `(int)` cast below
     * can represent: JSON has a single number type, so a producer
     * writing `42.0` still wrote the integer 42.
     *
     * A *string* spelled as a plain base-10 integer (`"42"`, `"+42"`,
     * `"-42"`) is an integer's textual spelling, and binds under Text
     * and Native only — under Json a string is a string, and the schema
     * said integer. Such a string is read as written, never through a
     * float: a float has 53 bits of mantissa, so `"1.0000000000000001"`
     * and `"1"` are the same float and only one of them is an integer. A
     * decimal spelling (`"42.0"`), an exponent spelling (`"4.2e1"`) and
     * a whitespace-padded one are all rejected for the same reason `4.5`
     * is — the field declares an integer and gets one, never a value the
     * `(int)` cast has to reinterpret.
     *
     * @param list<string|int> $path
     */
    private static function integerMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        if (is_int($value)) {
            return null;
        }

        if (is_float($value)) {
            // (float) PHP_INT_MAX rounds up to 2**63, one past the largest
            // representable int, so the upper bound is exclusive;
            // PHP_INT_MIN is exactly -2**63 as a float, so the lower one
            // is not.
            $exactInteger = is_finite($value)
                && $value === floor($value)
                && $value >= (float) PHP_INT_MIN
                && $value < (float) PHP_INT_MAX;

            return $exactInteger ? null : self::notAnInteger($path);
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
     * `float` accepts either JSON number from every source, and a
     * numeric string — a number's textual spelling — under Text and
     * Native only. It rejects any value that isn't finite: `"1e999"`
     * overflows to INF, which is not a number any consumer of this field
     * can act on.
     *
     * @param list<string|int> $path
     */
    private static function floatMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        if (is_int($value)) {
            return null;
        }

        if (is_float($value)) {
            return is_finite($value) ? null : self::notFinite($path);
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
     * Under Json a `bool` field takes the JSON literal and nothing else.
     * Text and Native additionally admit `1`/`0` and their string
     * spellings, which every textual source and every database driver
     * produces; Text's own `"true"`/`"false"` have already become real
     * booleans in normalizeForSource().
     *
     * @param list<string|int> $path
     */
    private static function booleanMismatchViolation(array $path, mixed $value, InputSource $source): ?Violation
    {
        $accepted = $source === InputSource::Json ? [true, false] : [true, false, 0, 1, '0', '1'];

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
