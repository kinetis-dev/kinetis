<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Validation\Exception\UnsupportedDtoDefinitionException;
use Kinetis\Validation\Exception\UnsupportedScalarTypeException;
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
 * - A builtin-typed parameter. typeMismatchMessage() carries the policy for
 *   every builtin type name PHP can attach to a parameter.
 * - A parameter typed as a single instantiable class: an object-shaped
 *   value is hydrated into that class recursively — its own errors
 *   surfacing under a dotted "field.nestedField" key — and a value that is
 *   already an instance of it is taken as given. Object-shaped means a
 *   JSON object (a JsonObject marker) or a map-shaped PHP array; a JSON
 *   array is not an object and never hydrates one, `[]` included.
 * - A parameter typed as a single non-instantiable class (an interface, an
 *   abstract class, an enum): only an existing instance is accepted. No
 *   request value can construct one — the case this exists for is the
 *   UploadedFileInterface Dispatcher merges into a multipart field.
 * - A parameter typed `array` carrying #[ListOf(SomeClass::class)]: a JSON
 *   array whose every element is either object-shaped (hydrated into
 *   SomeClass) or already a SomeClass instance. Element errors surface
 *   under a dotted "field.index" / "field.index.nestedField" key.
 * - A nullable variant of any of the above.
 *
 * Every other definition is rejected with an
 * UnsupportedDtoDefinitionException while the plan is compiled — at build
 * time for an AOT-compiled plan, on the first hydrate() call for a live
 * one: a union or intersection parameter type, a recursive or mutually
 * recursive class reference (a plan embeds each nested class inline, so
 * recursion has no finite plan and nothing var_export() could bake into a
 * cache file), a class type reflection cannot resolve (self/parent/static),
 * #[ListOf] on a parameter that isn't typed `array`, and #[ListOf] naming a
 * class that cannot be instantiated.
 *
 * Every builtin-typed parameter is type-checked before it is cast, never
 * after. `string` requires an actual string; `int` requires a real int, a
 * finite float with no fractional part, or a string spelled as a plain
 * base-10 integer (`42`, `42.0`, `"42"`), all inside PHP's native integer
 * range, and rejects a fractional, non-finite, out-of-range or
 * differently-spelled value (`"42.0"`, `"4.2e1"`) rather than truncating or
 * reinterpreting it; `float` accepts a real number or a numeric string and
 * rejects anything not finite; `bool` accepts only `true`, `false`, `1`,
 * `0`, `"1"`, `"0"`; `array`/`iterable` both require a real JSON array; a
 * standalone `null` type accepts only a literal null; standalone
 * `true`/`false` accept only that one literal boolean; `object`/`callable`
 * are rejected unconditionally — no JSON value can construct a plain
 * object, and a callable-typed parameter fed an attacker-controlled string
 * is an injection risk if it is ever invoked downstream. `mixed` accepts
 * anything by definition.
 *
 * A missing or explicitly-null value is a separate concern from a
 * wrong-shaped one: a missing key on a defaultless parameter is "is
 * required.", and an explicitly-null value for a parameter whose declared
 * type doesn't allow null is "must not be null." — both 422 validation
 * errors, never a raw TypeError escaping the constructor. typeMismatchMessage()
 * is the one boundary shared by every hydration call site — a #[Body] DTO
 * field here, a #[Query]/path parameter via Dispatcher, and an MCP tool
 * argument via McpDispatcher — so an unsupported value can never reach a
 * real constructor unchecked regardless of which one dispatched it, or
 * whether OpenAPI/MCP schema generation ever ran at all.
 *
 * Holds exactly one piece of static state: a memoization cache of
 * compilePlan() output, keyed by DTO class. This is a deliberate,
 * documented exemption from the NoStaticPropertiesRule this codebase
 * enforces (see phpstan.neon): a plan is pure derived data, identical on
 * every request for the process's lifetime, so persisting it across
 * requests cannot bleed request state — it only avoids re-running the
 * same reflection for every hydrated row. $compiledPlan remains an
 * optional argument so ahead-of-time compiled plans (Kinetis\Cache) keep
 * skipping even the first live compile.
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

    private const array HYDRATION_PLAN_KEYS = ['className', 'hasConstructor', 'parameters'];

    private const array HYDRATION_PLAN_PARAMETER_KEYS = [
        'name', 'scalarType', 'dtoClass', 'nestedPlan', 'listItemClass', 'listItemPlan',
        'hasDefault', 'defaultValue', 'allowsNull', 'constraints',
    ];

    /**
     * Memoized compilePlan() output — see the class docblock for why this
     * static property is exempt from NoStaticPropertiesRule.
     *
     * @var array<class-string, HydrationPlan>
     */
    private static array $planCache = [];

    /**
     * $normalizeFormLiterals — appended last, default `false`, so every
     * existing positional call keeps its exact current behavior — when
     * `true`, applies the identical "true"/"false" string-to-PHP-boolean
     * translation `Dispatcher::normalizeQueryOrPathLiteral()` already
     * applies for `#[Query]`/path values, scoped here to a `bool`/`true`/
     * `false`-typed field whenever `Dispatcher` knows the whole request
     * body is form-encoded (never JSON) — see resolveParameterValue()'s
     * own docblock for why this can't be applied unconditionally, the
     * same source-specific-value reasoning that already governs
     * `#[Query]`/path.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @param HydrationPlan|null $compiledPlan
     * @return T
     * @throws ValidationException
     * @throws UnsupportedDtoDefinitionException
     */
    public static function hydrate(string $class, array $data, ?array $compiledPlan = null, bool $normalizeFormLiterals = false): object
    {
        /** @var T */
        return self::hydrateFromPlan(
            $compiledPlan ?? self::$planCache[$class] ??= self::compilePlan($class),
            $data,
            $normalizeFormLiterals,
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
     *     hasDefault: bool,
     *     defaultValue: mixed,
     *     allowsNull: bool,
     *     constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>,
     * }
     * @throws UnsupportedDtoDefinitionException
     */
    private static function compileParameter(ReflectionParameter $parameter, string $class, array $visiting): array
    {
        $type = $parameter->getType();
        [$dtoClass, $nestedPlan, $listItemClass, $listItemPlan] = self::compileNesting($type, $parameter, $class, $visiting);

        return [
            'name' => $parameter->getName(),
            'scalarType' => $type instanceof ReflectionNamedType && $type->isBuiltin() ? $type->getName() : null,
            'dtoClass' => $dtoClass,
            'nestedPlan' => $nestedPlan,
            'listItemClass' => $listItemClass,
            'listItemPlan' => $listItemPlan,
            'hasDefault' => $parameter->isDefaultValueAvailable(),
            'defaultValue' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
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
     * @param class-string $nested
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return HydrationPlan
     * @throws UnsupportedDtoDefinitionException
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
    private static function hydrateFromPlan(array $plan, array $data, bool $normalizeFormLiterals = false): object
    {
        /** @var class-string $className */
        $className = $plan['className'];

        if (!$plan['hasConstructor']) {
            return new $className();
        }

        $errors = [];
        $arguments = [];

        foreach ($plan['parameters'] as $parameter) {
            $name = $parameter['name'];

            if (!array_key_exists($name, $data)) {
                if ($parameter['hasDefault']) {
                    $arguments[$name] = $parameter['defaultValue'];
                } else {
                    $errors[$name][] = 'is required.';
                }

                continue;
            }

            // An explicitly-null value for a parameter whose declared type
            // doesn't allow null would otherwise slip between the "is
            // required" check above (the key exists) and the type-mismatch
            // check (which exempts null) and reach the constructor as a raw
            // TypeError.
            if ($data[$name] === null && !$parameter['allowsNull']) {
                $errors[$name][] = 'must not be null.';

                continue;
            }

            [$value, $valueErrors] = self::resolveParameterValue($name, $data[$name], $parameter, $normalizeFormLiterals);

            if ($valueErrors !== []) {
                foreach ($valueErrors as $errorKey => $messages) {
                    $errors[$errorKey] = $messages;
                }

                continue;
            }

            foreach ($parameter['constraints'] as $descriptor) {
                /** @var class-string<Constraint> $constraintClass */
                $constraintClass = $descriptor['class'];
                $constraint = new $constraintClass(...$descriptor['args']);
                $message = $constraint->validate($value);

                if ($message !== null) {
                    $errors[$name][] = $message;
                }
            }

            $arguments[$name] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::forErrors($errors);
        }

        return new $className(...$arguments);
    }

    /**
     * Resolves one parameter's raw value into its hydrated form — a nested
     * DTO, a list of nested DTOs, or a cast scalar — matching whichever of
     * $parameter's dtoClass/listItemClass/plain-scalar shape applies. A
     * non-empty second element means hydration failed for this parameter;
     * the caller merges those into its own $errors and skips both the
     * constraints loop and assigning $arguments[$name] for it.
     *
     * $normalizeFormLiterals — see hydrate()'s own docblock. Applied
     * *before* the type-mismatch check, so the check itself still receives
     * an equivalent value, not a string standing in for one — the identical
     * two-step shape `Dispatcher::resolveScalarFromPlan()` already uses for
     * `#[Query]`/path. Never applied when a `dtoClass`/`listItemClass`
     * field routes elsewhere below: standard form encoding has no
     * nested-object wire representation at all, so this only ever matters
     * for a flat scalar field — but the flag itself still threads through
     * both recursive branches, since a form-encoded body reaching a
     * nested/list DTO's own scalar fields (via PHP's bracket-style
     * `field[sub]=value` form-field-name convention) is exactly as non-JSON
     * a source as the top level.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveParameterValue(string $name, mixed $value, array $parameter, bool $normalizeFormLiterals = false): array
    {
        if ($value === null) {
            // hydrateFromPlan() above has already rejected null for a
            // parameter whose declared type doesn't accept it; for one
            // that does, null is the value, whatever its declared shape.
            return [null, []];
        }

        if ($parameter['dtoClass'] !== null) {
            return self::resolveClassTypedValue($name, $value, $parameter['dtoClass'], $parameter['nestedPlan'], $normalizeFormLiterals);
        }

        if ($parameter['listItemClass'] !== null) {
            return self::resolveListValue($name, $value, $parameter, $normalizeFormLiterals);
        }

        if ($normalizeFormLiterals) {
            $value = self::normalizeFormLiteral($parameter['scalarType'], $value);
        }

        if ($parameter['scalarType'] !== null) {
            $message = self::typeMismatchMessage($parameter['scalarType'], $value);

            if ($message !== null) {
                return [null, [$name => [$message]]];
            }
        }

        // The type-mismatch check above (for array/iterable specifically)
        // runs against the still-JsonObject-marked value, so it can
        // correctly reject an object-shaped wire value — but the value a
        // `mixed` field (or an array/iterable field's own nested
        // contents, which JsonTree::convert() may have marked at any
        // depth) actually receives must never leak that marker: unwrap()
        // recursively restores the plain-array tree application code has
        // always seen. A no-op for anything that was never marked at all
        // (a #[Query]/path scalar, or a direct Hydrator::hydrate() call
        // that never went through JsonTree::convert()).
        return [self::castScalar(JsonTree::unwrap($value), $parameter['scalarType']), []];
    }

    /**
     * A form-encoded #[Body] value is a raw string when present, never
     * PHP's real `true`/`false` the way an already-decoded JSON body's
     * own boolean literal is — mirroring `Dispatcher::normalizeQueryOrPathLiteral()`'s
     * own reasoning exactly, just applied to the one other non-JSON source
     * this codebase has. `bool`'s own `"1"`/`"0"` spellings are unaffected
     * — they already pass typeMismatchMessage()'s check as raw strings.
     * Anything else (including a real array a repeated/bracketed form field
     * name produces) passes through unchanged.
     */
    private static function normalizeFormLiteral(?string $scalarType, mixed $value): mixed
    {
        if (!in_array($scalarType, ['bool', 'true', 'false'], true) || !is_string($value)) {
            return $value;
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            default => $value,
        };
    }

    /**
     * One class-typed value — a nested DTO field under its own field name,
     * or one #[ListOf] element under its own "field.index" key. Two shapes
     * are accepted and nothing else: an object-shaped value — a JsonObject
     * marker or a map-shaped PHP array — hydrated into $class against
     * $plan; or a value that is already a $class instance, taken as given
     * (the UploadedFileInterface Dispatcher merges into a multipart field,
     * or a caller hydrating from data it partly built itself). $plan is
     * null exactly when $class cannot be instantiated, so no value could
     * ever be hydrated into it and an instance is the only accepted shape.
     *
     * @param class-string $class
     * @param HydrationPlan|null $plan
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveClassTypedValue(string $key, mixed $value, string $class, ?array $plan, bool $normalizeFormLiterals): array
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
            return [null, [$key => [self::classTypedMismatchMessage($value, $class, $plan !== null)]]];
        }

        try {
            return [self::hydrateFromPlan($plan, $data, $normalizeFormLiterals), []];
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors as $nestedField => $messages) {
                $errors["{$key}.{$nestedField}"] = $messages;
            }

            return [null, $errors];
        }
    }

    /**
     * A value that reaches here as an array is always list-shaped — a
     * map-shaped one has already hydrated — so it names the real problem
     * (a JSON array where the schema declares an object) rather than
     * describeType()'s generic label.
     */
    private static function classTypedMismatchMessage(mixed $value, string $class, bool $hydratable): string
    {
        if (!$hydratable || is_object($value)) {
            return 'must be a ' . $class . ' instance.';
        }

        return is_array($value)
            ? self::NOT_A_JSON_OBJECT
            : 'must be an object, ' . self::describeType($value) . self::GIVEN_SUFFIX;
    }

    /**
     * The listItemClass branch of resolveParameterValue(): the field's own
     * value must be a real JSON array (the shape its JSON Schema claims),
     * and every element is resolved as one class-typed value under its own
     * dotted "field.index" key.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveListValue(string $name, mixed $value, array $parameter, bool $normalizeFormLiterals): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [null, [$name => [self::listShapeMessage($value)]]];
        }

        /** @var class-string $listItemClass */
        $listItemClass = $parameter['listItemClass'];
        $items = [];
        $errors = [];

        foreach ($value as $index => $item) {
            [$hydratedItem, $itemErrors] = self::resolveClassTypedValue(
                "{$name}.{$index}",
                $item,
                $listItemClass,
                $parameter['listItemPlan'],
                $normalizeFormLiterals,
            );

            if ($itemErrors !== []) {
                $errors = [...$errors, ...$itemErrors];

                continue;
            }

            $items[] = $hydratedItem;
        }

        return $errors !== [] ? [null, $errors] : [$items, []];
    }

    /**
     * The declared-type-mismatch check that runs before castScalar() casts
     * anything — see the class docblock for the policy this implements and
     * why. `null` is exempt: a missing value is handled by
     * hydrateFromPlan()'s own "is required" check, and an explicitly-null
     * value for a non-nullable parameter by its "must not be null." check.
     *
     * Public specifically so Kinetis\Http\Dispatcher can apply the identical
     * policy to #[Query]/path parameters — one uniform rule regardless of
     * source, not a second, separately-maintained copy of it.
     */
    public static function typeMismatchMessage(string $scalarType, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($scalarType) {
            'string' => is_string($value) ? null : 'must be a string, ' . self::describeType($value) . self::GIVEN_SUFFIX,
            'int' => self::integerMismatchMessage($value),
            'float' => self::floatMismatchMessage($value),
            'bool' => self::booleanMismatchMessage($value),
            // A plain `array` field (no #[ListOf] — that shape is handled
            // entirely separately, by resolveListValue()) needs this
            // check for the same reason every other builtin type does:
            // without it, a non-array value would reach `new $className(...)`
            // unchecked, surfacing as a raw TypeError instead of the
            // same 422/validation-error contract every other builtin
            // type gets. `iterable` gets the identical check: JSON
            // input can only ever decode into an array, never a real
            // Traversable, and a plain PHP array satisfies
            // `iterable` — so the wire contract and the accepted shape
            // are the same as `array`'s.
            'array', 'iterable' => self::listShapeMismatchMessage($value),
            // A standalone `null` type accepts nothing but JSON null
            // itself — the `$value === null` exemption above already
            // covers that case, so reaching this arm means a non-null
            // value was given for a field that can never legally
            // hold one.
            'null' => 'must be null, ' . self::describeType($value) . self::GIVEN_SUFFIX,
            // PHP 8.2's standalone `true`/`false` types each accept
            // exactly one literal boolean value — narrower than `bool`,
            // which accepts either.
            'true' => $value === true ? null : 'must be true, ' . self::describeType($value) . self::GIVEN_SUFFIX,
            'false' => $value === false ? null : 'must be false, ' . self::describeType($value) . self::GIVEN_SUFFIX,
            // `object` and `callable` have no truthful representation
            // this codebase accepts (see JsonSchema::forType()'s own
            // docblock for the full reasoning): JSON input never decodes
            // into a real PHP object, and a `callable`-typed parameter fed
            // an attacker-controlled string is a real arbitrary-function-
            // name-injection risk if it's ever invoked downstream. Both
            // are rejected unconditionally the moment a real value is
            // actually supplied — this is the guaranteed-to-run boundary
            // that closes the gap regardless of whether OpenAPI/MCP schema
            // generation (which already refuses to describe either type at
            // all) ever runs for this route/tool.
            'object' => 'cannot be provided through JSON input — no request value can construct a plain object.',
            'callable' => 'cannot be provided through JSON input — callable values are not accepted.',
            // `mixed` accepts anything by definition — nothing to check;
            // an explicit arm rather than falling to default below, so
            // the fail-closed guard there only ever catches an
            // unrecognized type name.
            'mixed' => null,
            // Every one of the twelve builtin type names ReflectionNamedType
            // can actually attach to a parameter has its own arm above —
            // reaching here means $scalarType isn't one of them at all.
            // Throwing (fail closed) rather than silently accepting is
            // deliberate: a bare `default => null` here is exactly the
            // fail-open pattern a future builtin type PHP adds, or a
            // caller passing a scalarType this method never derived from
            // reflection, must not get.
            default => throw UnsupportedScalarTypeException::forType($scalarType),
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
     */
    private static function listShapeMismatchMessage(mixed $value): ?string
    {
        return is_array($value) && array_is_list($value) ? null : self::listShapeMessage($value);
    }

    /**
     * The one message owner for a value that had to be a JSON array and
     * wasn't — shared by an `array`/`iterable` field and a `#[ListOf]`
     * one, which make the identical claim in their JSON Schema. A
     * `JsonObject` marker, and a map-shaped PHP array (exactly what a
     * JSON object decodes into outside the marked pipeline), both name
     * the real problem rather than describeType()'s generic label.
     */
    private static function listShapeMessage(mixed $value): string
    {
        return $value instanceof JsonObject || is_array($value)
            ? self::NOT_A_JSON_ARRAY
            : 'must be an array, ' . self::describeType($value) . self::GIVEN_SUFFIX;
    }

    /**
     * `int` accepts three things: a real int; a finite float with no
     * fractional part, inside the range the `(int)` cast below can
     * represent; and a string spelled as a plain base-10 integer whose
     * own value is in that range (`"42"`, `"+42"`, `"-42"`).
     *
     * A string is read as written, never through a float: a float has 53
     * bits of mantissa, so `"1.0000000000000001"` and `"1"` are the same
     * float and only one of them is an integer. A decimal spelling
     * (`"42.0"`), an exponent spelling (`"4.2e1"`) and a whitespace-padded
     * one are all rejected for the same reason `4.5` is — the field
     * declares an integer and gets one, never a value the `(int)` cast
     * has to reinterpret.
     */
    private static function integerMismatchMessage(mixed $value): ?string
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

            return $exactInteger ? null : self::NOT_AN_INTEGER;
        }

        if (is_string($value)) {
            // FILTER_VALIDATE_INT is the base-10 integer-spelling check
            // and the native-range check in one, with no float step in
            // between to round a digit away. It tolerates surrounding
            // whitespace, which a declared integer value does not.
            $spelledAsInteger = $value === trim($value)
                && filter_var($value, FILTER_VALIDATE_INT) !== false;

            return $spelledAsInteger ? null : self::NOT_AN_INTEGER;
        }

        return 'must be an integer, ' . self::describeType($value) . self::GIVEN_SUFFIX;
    }

    /**
     * `float` accepts a real number or a numeric string, and rejects any
     * value that isn't finite — `"1e999"` overflows to INF, which is not a
     * number any consumer of this field can act on.
     */
    private static function floatMismatchMessage(mixed $value): ?string
    {
        if (is_int($value)) {
            return null;
        }

        if (is_float($value)) {
            return is_finite($value) ? null : self::NOT_FINITE;
        }

        if (is_string($value) && is_numeric($value)) {
            return is_finite((float) $value) ? null : self::NOT_FINITE;
        }

        return 'must be a number, ' . self::describeType($value) . self::GIVEN_SUFFIX;
    }

    private static function booleanMismatchMessage(mixed $value): ?string
    {
        if (in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            return null;
        }

        return 'must be a boolean, ' . self::describeType($value) . self::GIVEN_SUFFIX;
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
     * Casts a value that has already passed typeMismatchMessage() to its
     * declared builtin type — every accepted value is exactly
     * representable in it, so no cast here can lose information. Public
     * specifically so Kinetis\Http\Dispatcher casts a #[Query]/path value
     * through the identical rules rather than a second copy of them.
     */
    public static function castScalar(mixed $value, ?string $scalarType): mixed
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
