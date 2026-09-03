<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
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
 * A constructor parameter typed as another class (not a builtin scalar) is
 * treated as a nested DTO: if the corresponding data value is itself an
 * array, it's recursively hydrated the same way, with its own errors
 * surfacing under a dotted "field.nestedField" key. A scalar value (string,
 * int, float, bool) where an object is expected is a validation error, not
 * a silent pass-through — sending `{"address": "hello"}` for a nested
 * `Address` parameter now surfaces `address: must be an object, string
 * given.` instead of reaching `new Address(...)` and throwing a raw
 * `TypeError`. `null`, and anything already an object — most commonly an
 * `UploadedFileInterface` instance `Dispatcher` already merged in for a
 * multipart field — still pass through unchanged, exactly like a non-DTO
 * class-typed value always has; Hydrator never needs to know which specific
 * classes are "special", only whether it was handed an array, a scalar, or
 * something else.
 *
 * A parameter typed `array` and carrying a #[ListOf(SomeClass::class)]
 * attribute is treated as a list of nested DTOs: each array-shaped element
 * is hydrated the same way a single nested DTO is, with errors surfacing
 * under a dotted "field.index.nestedField" key; a non-array *element*
 * passes through unchanged, the same tolerance a single nested DTO
 * parameter already gives a non-array value — but the field's own value
 * being a scalar instead of an array at all is a validation error for the
 * same reason a scalar is rejected for a single nested DTO above. `array`
 * alone (no #[ListOf]) passes through as a raw array.
 *
 * Every builtin-typed constructor parameter is type-checked *before* it's
 * cast, not after — typeMismatchMessage() carries the complete, deliberate
 * policy for every one of the twelve builtin type names PHP can actually
 * attach to a parameter (see JsonSchema::forType()'s own docblock for how
 * this list was audited): `string` rejects anything that isn't an actual
 * PHP string; `int`/`float` accept a real number or a numeric string but
 * reject a non-numeric string, array, object, or bool; `bool` accepts only
 * `true`, `false`, `1`, `0`, `"1"`, `"0"`; `array`/`iterable` both require
 * a real JSON array (the only shape JSON input can ever decode into that
 * genuinely satisfies either type); a standalone `null` type accepts only
 * a literal null; standalone `true`/`false` accept only that one literal
 * boolean; `object`/`callable` are rejected unconditionally — no JSON
 * value can construct a plain object, and a callable-typed parameter fed
 * an attacker-controlled string is a real injection risk if it's ever
 * invoked downstream. `null` itself is exempt from all of this — a missing
 * or explicitly-null *value* is a separate concern from a wrong-shaped
 * one: a missing key on a defaultless parameter is "is required.", and an
 * explicitly-null value for a parameter whose declared type doesn't allow
 * null is "must not be null." — both 422 validation errors, never a raw
 * `TypeError` escaping from the constructor. `mixed` is untouched, since
 * it accepts anything by definition. This is the one boundary shared by
 * every hydration call site — a #[Body] DTO field here, a #[Query]/path
 * parameter via Dispatcher, and an MCP tool argument via McpDispatcher all
 * delegate to this exact method, so an unsupported declaration can never
 * reach a real constructor unchecked regardless of which one dispatched
 * it, or whether OpenAPI/MCP schema generation (which independently
 * refuses to even describe `object`/`callable`) ever ran at all.
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
     * every class-typed constructor parameter, embedding that class's own
     * plan inline as `nestedPlan` — so compiling just the top-level DTO a
     * route/tool binds directly already produces a fully nested-inclusive
     * plan, with zero further discovery needed elsewhere (see
     * Kinetis\Cache\Compiler's own doc comment for why its discovery pass
     * doesn't need to change to stay correct here).
     *
     * Constraint entries capture the attribute's literal constructor
     * arguments via ReflectionAttribute::getArguments() — NOT newInstance()
     * — so each descriptor is plain data (e.g. #[MinLength(5)] ->
     * {class: MinLength::class, args: [5]}), reconstructable later via
     * `new $class(...$args)` with zero reflection.
     *
     * $visiting tracks classes already being compiled in the current
     * recursion chain, so a self-referencing (or mutually referencing) DTO
     * stops nesting the moment a class repeats, instead of recursing
     * forever. This isn't just a defensive stack-overflow guard: a plan
     * containing a genuine PHP array cycle couldn't be compiled ahead of
     * time at all — Kinetis\Cache\Compiler bakes plans into a cache file via
     * var_export(), which has no way to represent a circular array as
     * re-parseable PHP. A self-referencing field one level deep — the
     * common real case — simply receives its raw array unhydrated rather
     * than a nested instance; this is a real, deliberate limitation, not an
     * oversight.
     *
     * @param class-string $class
     * @param array<class-string, true> $visiting
     * @return HydrationPlan
     */
    public static function compilePlan(string $class, array $visiting = []): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return ['className' => $class, 'hasConstructor' => false, 'parameters' => []];
        }

        $visiting[$class] = true;
        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = self::compileParameter($parameter, $visiting);
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
     * Naturally bounded by the data itself: a genuinely self-referencing
     * plan was never producible in the first place (see `compilePlan()`'s
     * own `$visiting` guard, above), so there is no unbounded recursion
     * risk here either.
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
     */
    private static function compileParameter(ReflectionParameter $parameter, array $visiting): array
    {
        $type = $parameter->getType();
        [$dtoClass, $nestedPlan, $listItemClass, $listItemPlan] = self::compileNesting($type, $parameter, $visiting);

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
     * @param array<class-string, true> $visiting
     * @return array{0: ?class-string, 1: ?array<string, mixed>, 2: ?class-string, 3: ?array<string, mixed>}
     */
    private static function compileNesting(?ReflectionType $type, ReflectionParameter $parameter, array $visiting): array
    {
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $dtoClass */
            $dtoClass = $type->getName();
            $nestedPlan = isset($visiting[$dtoClass]) ? null : self::compilePlan($dtoClass, $visiting);

            return [$dtoClass, $nestedPlan, null, null];
        }

        if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
            $listOfAttributes = $parameter->getAttributes(ListOf::class);

            if ($listOfAttributes !== []) {
                $listItemClass = $listOfAttributes[0]->newInstance()->itemClass();
                $listItemPlan = isset($visiting[$listItemClass]) ? null : self::compilePlan($listItemClass, $visiting);

                return [null, null, $listItemClass, $listItemPlan];
            }
        }

        return [null, null, null, null];
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
     * *before* the type-mismatch check, so the check itself still
     * receives a genuinely equivalent value, not a string standing in
     * for one — the identical two-step shape `Dispatcher::resolveScalarFromPlan()`
     * already uses for `#[Query]`/path. Deliberately never applied when a
     * `dtoClass`/`listItemClass` field routes elsewhere below: standard
     * form encoding has no nested-object wire representation at all, so
     * this only ever matters for a genuinely flat scalar field — but the
     * flag itself still threads through both recursive branches, since a
     * form-encoded body reaching a nested/list DTO's own scalar fields
     * (via PHP's bracket-style `field[sub]=value` form-field-name
     * convention) is still exactly as non-JSON a source as the top level.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveParameterValue(string $name, mixed $value, array $parameter, bool $normalizeFormLiterals = false): array
    {
        if ($parameter['dtoClass'] !== null) {
            return self::resolveNestedDtoValue($name, $value, $parameter, $normalizeFormLiterals);
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
     * own reasoning exactly, just applied to the one other genuinely
     * non-JSON source this codebase has. `bool`'s own pre-existing
     * `"1"`/`"0"` spellings are unaffected — they already pass
     * typeMismatchMessage()'s check as raw strings. Anything else
     * (including a real array a repeated/bracketed form field name
     * produces) passes through unchanged.
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
     * The dtoClass branch of resolveParameterValue(), split out on its own
     * once this whole method's cognitive complexity grew past the linter's
     * threshold as list-of-DTO support was added alongside it — a pure move,
     * no behavior change (see the class docblock for what this branch does
     * and why a scalar value here is a validation error, not a pass-through).
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveNestedDtoValue(string $name, mixed $value, array $parameter, bool $normalizeFormLiterals = false): array
    {
        // A nested DTO field's own real wire value — a genuine JSON
        // object — arrives marked as a JsonObject once JsonTree::convert()
        // is in the picture (see decodeJsonBody()'s own docblock);
        // unwrapped here so the existing is_array() recursion below
        // still applies unchanged, exactly as it always has for a plain
        // associative array.
        if ($value instanceof JsonObject) {
            $value = $value->toArray();
        }

        if (is_array($value)) {
            if ($parameter['nestedPlan'] === null) {
                // Self-referencing DTO guard (see class docblock): no
                // plan to hydrate against, so the raw array passes
                // through unhydrated. $value's own elements may still
                // hold a JsonObject at any depth -- the toArray() call
                // above only ever converts the *outer* marker, since it
                // has no plan to recurse against either -- so this still
                // needs its own unwrap() pass before leaving Hydrator's
                // own boundary: the class docblock's "null, or already an
                // object... pass through unchanged" contract, and every
                // other exit point in this class, guarantee application
                // code never sees a JsonObject, and this guard is no
                // exception just because there's no nestedPlan to check
                // its own contents against.
                return [JsonTree::unwrap($value), []];
            }

            /** @var HydrationPlan $nestedPlan */
            $nestedPlan = $parameter['nestedPlan'];

            try {
                return [self::hydrateFromPlan($nestedPlan, $value, $normalizeFormLiterals), []];
            } catch (ValidationException $e) {
                $errors = [];

                foreach ($e->errors as $nestedField => $messages) {
                    $errors["{$name}.{$nestedField}"] = $messages;
                }

                return [null, $errors];
            }
        }

        if (is_scalar($value)) {
            return [null, [$name => ['must be an object, ' . self::describeType($value) . self::GIVEN_SUFFIX]]];
        }

        // null, or already an object (e.g. an UploadedFileInterface
        // Dispatcher merged in directly for a multipart field) — pass
        // through unchanged, exactly as before.
        return [$value, []];
    }

    /**
     * The listItemClass branch of resolveParameterValue() — same extraction
     * reasoning as resolveNestedDtoValue() above, split out alongside it.
     *
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveListValue(string $name, mixed $value, array $parameter, bool $normalizeFormLiterals = false): array
    {
        // #[ListOf]'s own JSON Schema claims {type: 'array'} exactly like
        // a plain array field's — a genuine JSON object (marked as
        // JsonObject once JsonTree::convert() is in the picture) is
        // rejected outright here, the same way listShapeMismatchMessage()
        // rejects one for a plain array/iterable field, regardless of
        // what its own keys happen to look like.
        if ($value instanceof JsonObject) {
            return [null, [$name => [self::NOT_A_JSON_ARRAY]]];
        }

        if (is_array($value)) {
            // A map-shaped PHP array reaching here directly (never
            // marked at all — a direct Hydrator::hydrate() call, or a
            // form-decoded body) gets the identical rejection.
            if (!array_is_list($value)) {
                return [null, [$name => [self::NOT_A_JSON_ARRAY]]];
            }
        } elseif (is_scalar($value)) {
            return [null, [$name => ['must be an array, ' . self::describeType($value) . self::GIVEN_SUFFIX]]];
        } else {
            // null, or already an array-like object — pass through unchanged.
            return [$value, []];
        }

        if ($parameter['listItemPlan'] === null) {
            // Self-referencing list guard: same reasoning as
            // resolveNestedDtoValue()'s own guard above -- $value is
            // confirmed a real list array at this point, but any element
            // (or anything nested inside one) may still carry a
            // JsonObject marker, since there's no listItemPlan to
            // recurse hydration against and therefore no other point in
            // this branch that would ever unwrap it.
            return [JsonTree::unwrap($value), []];
        }

        /** @var HydrationPlan $listItemPlan */
        $listItemPlan = $parameter['listItemPlan'];
        $items = [];
        $errors = [];

        foreach ($value as $index => $item) {
            [$hydratedItem, $itemErrors] = self::hydrateListItem($listItemPlan, $name, $index, $item, $normalizeFormLiterals);

            if ($itemErrors !== []) {
                foreach ($itemErrors as $errorKey => $messages) {
                    $errors[$errorKey] = $messages;
                }

                continue;
            }

            $items[$index] = $hydratedItem;
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [array_values($items), []];
    }

    /**
     * One #[ListOf] element's own hydration, split out of resolveListValue()'s
     * loop body once that whole method's cognitive complexity grew past the
     * linter's threshold — a pure move, no behavior change: a non-array
     * element still passes through unchanged, a hydration failure still
     * surfaces under the identical dotted "field.index.nestedField" key, and
     * a failed element is still simply absent from the returned items rather
     * than added as null.
     *
     * @param HydrationPlan $listItemPlan
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function hydrateListItem(array $listItemPlan, string $name, int|string $index, mixed $item, bool $normalizeFormLiterals = false): array
    {
        // Each #[ListOf] element is expected to be an object (a nested
        // DTO) on the wire — its own real value arrives marked as a
        // JsonObject once JsonTree::convert() is in the picture, unwrapped
        // here so the existing is_array() recursion below still applies
        // unchanged.
        if ($item instanceof JsonObject) {
            $item = $item->toArray();
        }

        if (!is_array($item)) {
            return [$item, []];
        }

        try {
            return [self::hydrateFromPlan($listItemPlan, $item, $normalizeFormLiterals), []];
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors as $nestedField => $messages) {
                $errors["{$name}.{$index}.{$nestedField}"] = $messages;
            }

            return [null, $errors];
        }
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
            'int', 'float' => self::numericMismatchMessage($value),
            'bool' => self::booleanMismatchMessage($value),
            // A plain `array` field (no #[ListOf] — that shape is handled
            // entirely separately, by resolveListValue()) needs this
            // check for the same reason every other builtin type does:
            // without it, a non-array value would reach `new $className(...)`
            // unchecked, surfacing as a raw TypeError instead of the
            // same 422/validation-error contract every other builtin
            // type gets. `iterable` gets the identical check: JSON
            // input can only ever decode into an array, never a real
            // Traversable, and a plain PHP array genuinely satisfies
            // `iterable` — so the wire contract and the accepted shape
            // are the same as `array`'s.
            'array', 'iterable' => self::listShapeMismatchMessage($value),
            // A standalone `null` type accepts nothing but JSON null
            // itself — the `$value === null` exemption above already
            // covers that case, so reaching this arm means a genuinely
            // non-null value was given for a field that can never legally
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
            // the fail-closed guard there only ever catches a genuinely
            // unrecognized type name.
            'mixed' => null,
            // Every one of the twelve builtin type names ReflectionNamedType
            // can actually attach to a parameter has its own arm above —
            // reaching here means $scalarType isn't one of them at all.
            // Throwing (fail closed) rather than silently accepting is
            // deliberate: a bare `default => null` here is exactly the
            // fail-open pattern that let object/callable/iterable/null/
            // true/false all reach a raw constructor unchecked before this
            // class's own audit gave each of them a real policy — a future
            // builtin type PHP adds, or a caller passing a scalarType this
            // method never actually derived from reflection, must not get
            // that same silent treatment.
            default => throw UnsupportedScalarTypeException::forType($scalarType),
        };
    }

    /**
     * `array`/`iterable`'s own truthful wire contract: their JSON Schema
     * representation is `{type: array}`, which means a JSON *array*
     * (`[...]`), not any array-shaped PHP value — including a JSON
     * *object* (`{...}`), and including the empty object `{}`.
     *
     * A JSON object never reaches the `is_array()`/`array_is_list()`
     * checks below at all — see decodeJsonBody()'s own docblock:
     * `Dispatcher`/`McpServer` decode with `associative: false` and run
     * the result through `JsonTree::convert()`, which wraps every JSON
     * object anywhere in the tree — including one with sequential-
     * looking numeric keys (`{"0":"a","1":"b"}`, which would otherwise
     * decode to the identical PHP shape a real JSON array does, and which
     * `array_is_list()` alone cannot tell apart from one), and including
     * `{}` — in a `JsonObject` marker, checked first, below. Only a
     * genuine JSON array (or something that was never JSON-decoded
     * through that pipeline at all — a direct `Hydrator::hydrate()` call
     * with a hand-built PHP array, or a form-decoded body, neither of
     * which carries any JSON-object/array distinction to preserve in the
     * first place) ever reaches `array_is_list()` itself, which remains
     * the correct, precise check for *that* case: true only for
     * sequential integer-keyed arrays, false for a genuinely map-shaped
     * one.
     */
    private static function listShapeMismatchMessage(mixed $value): ?string
    {
        // A genuine JSON object — marked as JsonObject once
        // JsonTree::convert() is in the picture — is rejected outright,
        // regardless of what its own keys happen to look like:
        // {"0":"a","1":"b"} decodes to the exact same PHP shape a real
        // JSON array does once flattened, so array_is_list() alone
        // cannot tell them apart below; this check runs first,
        // specifically so it doesn't have to.
        if ($value instanceof JsonObject) {
            return self::NOT_A_JSON_ARRAY;
        }

        if (is_array($value) && array_is_list($value)) {
            return null;
        }

        if (is_array($value)) {
            // A map-shaped PHP array reaching here directly (never
            // marked at all — a direct Hydrator::hydrate() call, or a
            // form-decoded body) is exactly what a JSON *object* decodes
            // into via associative:true — distinguished from "not an
            // array at all" so the message names the real problem, not
            // describeType()'s own generic 'array' label for either shape.
            return self::NOT_A_JSON_ARRAY;
        }

        return 'must be an array, ' . self::describeType($value) . self::GIVEN_SUFFIX;
    }

    private static function numericMismatchMessage(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return null;
        }

        if (is_string($value) && is_numeric($value)) {
            return null;
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
            is_array($value) => 'array',
            is_bool($value) => 'boolean',
            is_float($value) => 'float',
            is_int($value) => 'integer',
            is_object($value) => 'object',
            default => 'value',
        };
    }

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
