<?php

declare(strict_types=1);

namespace Kinetis\Validation;

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
 * Every builtin-typed constructor parameter (`string`/`int`/`float`/`bool`)
 * is type-checked *before* it's cast, not after: a `string` field rejects
 * anything that isn't an actual PHP string; an `int`/`float` field accepts
 * a real number or a numeric string but rejects a non-numeric string,
 * array, object, or bool; a `bool` field accepts only `true`, `false`,
 * `1`, `0`, `"1"`, `"0"`. `null` is exempt from this check — a missing or
 * explicitly-null value is a separate concern from a wrong-shaped one:
 * a missing key on a defaultless parameter is "is required.", and an
 * explicitly-null value for a parameter whose declared type doesn't allow
 * null is "must not be null." — both 422 validation errors, never a raw
 * `TypeError` escaping from the constructor.
 * `array`/`mixed`/any other builtin type is untouched, since nothing casts
 * those today either. This check applies identically to `#[Query]`/path
 * parameters via `Dispatcher`, not just `#[Body]` DTO fields — the same
 * rules apply regardless of whether a value came from a query string or a
 * JSON body.
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

    /**
     * Memoized compilePlan() output — see the class docblock for why this
     * static property is exempt from NoStaticPropertiesRule.
     *
     * @var array<class-string, HydrationPlan>
     */
    private static array $planCache = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $data
     * @param HydrationPlan|null $compiledPlan
     * @return T
     * @throws ValidationException
     */
    public static function hydrate(string $class, array $data, ?array $compiledPlan = null): object
    {
        /** @var T */
        return self::hydrateFromPlan(
            $compiledPlan ?? self::$planCache[$class] ??= self::compilePlan($class),
            $data,
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
    private static function hydrateFromPlan(array $plan, array $data): object
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

            [$value, $valueErrors] = self::resolveParameterValue($name, $data[$name], $parameter);

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
     * @param HydrationPlanParameter $parameter
     * @return array{0: mixed, 1: array<string, list<string>>}
     */
    private static function resolveParameterValue(string $name, mixed $value, array $parameter): array
    {
        if ($parameter['dtoClass'] !== null) {
            return self::resolveNestedDtoValue($name, $value, $parameter);
        }

        if ($parameter['listItemClass'] !== null) {
            return self::resolveListValue($name, $value, $parameter);
        }

        if ($parameter['scalarType'] !== null) {
            $message = self::typeMismatchMessage($parameter['scalarType'], $value);

            if ($message !== null) {
                return [null, [$name => [$message]]];
            }
        }

        return [self::castScalar($value, $parameter['scalarType']), []];
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
    private static function resolveNestedDtoValue(string $name, mixed $value, array $parameter): array
    {
        if (is_array($value)) {
            if ($parameter['nestedPlan'] === null) {
                // Self-referencing DTO guard (see class docblock): no
                // plan to hydrate against, so the raw array passes
                // through unhydrated.
                return [$value, []];
            }

            /** @var HydrationPlan $nestedPlan */
            $nestedPlan = $parameter['nestedPlan'];

            try {
                return [self::hydrateFromPlan($nestedPlan, $value), []];
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
    private static function resolveListValue(string $name, mixed $value, array $parameter): array
    {
        if (!is_array($value)) {
            if (is_scalar($value)) {
                return [null, [$name => ['must be an array, ' . self::describeType($value) . self::GIVEN_SUFFIX]]];
            }

            // null, or already an array-like object — pass through unchanged.
            return [$value, []];
        }

        if ($parameter['listItemPlan'] === null) {
            // Self-referencing list guard: same reasoning as above.
            return [$value, []];
        }

        /** @var HydrationPlan $listItemPlan */
        $listItemPlan = $parameter['listItemPlan'];
        $items = [];
        $errors = [];

        foreach ($value as $index => $item) {
            [$hydratedItem, $itemErrors] = self::hydrateListItem($listItemPlan, $name, $index, $item);

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
    private static function hydrateListItem(array $listItemPlan, string $name, int|string $index, mixed $item): array
    {
        if (!is_array($item)) {
            return [$item, []];
        }

        try {
            return [self::hydrateFromPlan($listItemPlan, $item), []];
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
            default => null,
        };
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
