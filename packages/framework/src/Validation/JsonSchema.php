<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Validation\Exception\JsonSchemaException;
use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * Maps reflection type/constraint metadata to JSON Schema fragments.
 * Extracted out of OpenApiGenerator once McpRegistry needed the identical
 * mapping for MCP tool input schemas — the same DTO constructor and
 * #[Email]/#[MinLength]/#[GreaterThan]/#[Regex] constraint attributes
 * describe both an HTTP request body and an MCP tool call's arguments,
 * so the type-to-schema logic shouldn't live twice.
 *
 * Nullability and required presence are deliberately independent: a
 * nullable type (`?string`, a nullable class-typed/#[ListOf] field) is
 * reflected in the property's own schema — `type: ['string', 'null']`,
 * or `anyOf: [<the $ref>, {type: null}]` for a nested DTO that dedupes
 * into a `$ref` — but never removes that property from `required`.
 * `Hydrator::hydrateFromPlan()`/`McpDispatcher::resolveFromPlan()` both
 * key "is this parameter required" purely on whether it has a default,
 * never on nullability — a defaultless nullable field still rejects an
 * *absent* key exactly like a non-nullable one does, only accepting an
 * *explicitly-null* value once present. `forParameters()`'s own
 * `required` array matches this: `!isDefaultValueAvailable()` alone,
 * with no `allowsNull()` term. `OpenApiGenerator`'s own `#[Query]`/path
 * parameter `required` (computed independently of this class, never
 * through `forParameters()`) already followed this same rule and needed
 * no change — it never consulted nullability either.
 */
final class JsonSchema
{
    /**
     * @param class-string $class
     * @param (callable(class-string): array<string, mixed>)|null $classSchema used
     *        instead of inlining a nested class-typed parameter's own schema — see
     *        forParameters(). Passed through recursively so every nesting depth is
     *        resolved the same way, not just the first one.
     * @return array<string, mixed>
     */
    public static function forClass(string $class, ?callable $classSchema = null): array
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null) {
            return ['type' => 'object'];
        }

        return self::forParameters($constructor->getParameters(), classSchema: $classSchema);
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param list<class-string> $excludeTypes parameters whose type matches one of
     *        these are skipped entirely — not added to properties/required. Used to
     *        hide framework-injected parameters (e.g. Kinetis\Mcp\ProgressReporter)
     *        that aren't part of a tool's actual public argument surface.
     * @param (callable(class-string): array<string, mixed>)|null $classSchema called
     *        for a nested class-typed parameter instead of inlining forClass()'s own
     *        result directly — OpenApiGenerator uses this to dedupe repeated DTOs into
     *        `components/schemas` with a $ref rather than inlining the same schema
     *        wherever it's reused. null (the default) keeps every previous call site's
     *        exact inline-everything behavior unchanged — MCP tool input schemas have
     *        no components/$ref mechanism to dedupe into, so they never pass one.
     * @return array<string, mixed>
     */
    public static function forParameters(array $parameters, array $excludeTypes = [], ?callable $classSchema = null): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && in_array($type->getName(), $excludeTypes, true)) {
                continue;
            }

            $nullable = $type instanceof ReflectionNamedType && $type->allowsNull();

            if ($type instanceof ReflectionNamedType && $type->getName() === UploadedFileInterface::class) {
                // An UploadedFileInterface-typed #[Body] field is never a
                // nested DTO — Dispatcher merges it in directly from the
                // request's own uploaded-files bag (see
                // uploadedFilesByFieldName()'s own docblock), so
                // schemaForClassTyped()'s "expand the constructor" logic
                // has nothing to reflect here (the interface has no
                // constructor at all) and would otherwise fall back to a
                // bare, untruthful {type: object}. `{type: string, format:
                // binary}` is OpenAPI's own real convention for a file
                // upload field inside a multipart-serialized schema —
                // truthful for the one content type that can actually
                // carry one; see OpenApiGenerator::describeRequestBody()
                // for how the surrounding requestBody itself is scoped
                // per content type.
                $properties[$parameter->getName()] = self::withNullableSchema(['type' => 'string', 'format' => 'binary'], $nullable);
            } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $class */
                $class = $type->getName();
                $properties[$parameter->getName()] = self::schemaForClassTyped($class, $classSchema, $nullable);
            } elseif (($listItemClass = self::listItemClassFor($parameter)) !== null) {
                $properties[$parameter->getName()] = self::schemaForListOf($listItemClass, $classSchema, $nullable);
            } else {
                $properties[$parameter->getName()] = self::schemaForScalar($parameter, $type);
            }

            // Nullability and required presence are independent axes:
            // null is a permitted *value* (reflected in the schema above),
            // never permission to *omit* the member. Hydrator::hydrateFromPlan()
            // and McpDispatcher::resolveFromPlan() both key "is this
            // parameter required" purely on isDefaultValueAvailable() — a
            // defaultless nullable field is still rejected as missing when
            // the key itself is absent, so the schema has to say the same
            // thing or a client following it would be misled into thinking
            // omission is safe.
            if (!$parameter->isDefaultValueAvailable()) {
                $required[] = $parameter->getName();
            }
        }

        return [
            'type' => 'object',
            // A zero-parameter method has no entries to give properties
            // string keys, and PHP has no native empty-object type — cast
            // to (object) so JSON Schema's required object type encodes
            // as {}, not [].
            'properties' => $properties === [] ? (object) [] : $properties,
            'required' => $required,
        ];
    }

    /**
     * A class-typed parameter (an MCP tool taking a DTO the way an HTTP
     * #[Body] param does) — expand its own constructor's properties
     * recursively instead of collapsing it to a bare {type: object}, or the
     * schema would tell an agent nothing about what fields to send.
     *
     * @param class-string $class
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     */
    private static function schemaForClassTyped(string $class, ?callable $classSchema, bool $nullable): array
    {
        $schema = $classSchema !== null ? $classSchema($class) : self::forClass($class);

        return self::withNullableSchema($schema, $nullable);
    }

    /**
     * array + #[ListOf(SomeClass::class)] — same expand-instead-of-collapse
     * reasoning as schemaForClassTyped(), just one level further out:
     * {type: array, items: <SomeClass's own schema>} instead of a bare
     * {type: object} that would tell an agent nothing about what each
     * element looks like.
     *
     * @param class-string $listItemClass
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     */
    private static function schemaForListOf(string $listItemClass, ?callable $classSchema, bool $nullable): array
    {
        $schema = [
            'type' => 'array',
            'items' => $classSchema !== null ? $classSchema($listItemClass) : self::forClass($listItemClass),
        ];

        return self::withNullableSchema($schema, $nullable);
    }

    /**
     * Adds JSON Schema 2020-12 / OpenAPI 3.1's own null representation
     * when $nullable is true — never OpenAPI 3.0's non-standard
     * `nullable: true` keyword, which nothing in this codebase emits
     * elsewhere either. A schema already carrying a plain `type` (a
     * builtin scalar, or an inline {type: object, ...}/{type: array,
     * ...} class/list schema) gets that value widened into a two-element
     * array (`['string', 'null']`, and so on) — the portable way JSON
     * Schema expresses "this type, or null." A schema built entirely
     * around a `$ref` has no `type` of its own to widen — `$ref` only
     * combines with sibling keywords as an *intersection* in JSON Schema
     * 2020-12, so adding `type: null` alongside it would require a value
     * to satisfy both the ref's own shape and be `null` at once, which
     * nothing can ever do — so this wraps it in `anyOf: [<the ref>,
     * {type: null}]` instead, the correct union representation.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function withNullableSchema(array $schema, bool $nullable): array
    {
        if (!$nullable) {
            return $schema;
        }

        if (isset($schema['$ref'])) {
            return ['anyOf' => [$schema, ['type' => 'null']]];
        }

        if (isset($schema['type'])) {
            $schema['type'] = is_array($schema['type']) ? [...$schema['type'], 'null'] : [$schema['type'], 'null'];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Public specifically so OpenApiGenerator can apply the identical
     * type+constraint-to-schema mapping to a #[Query]/path parameter, not
     * just a #[Body] DTO's own constructor parameters — closing the gap
     * where a #[Query] `#[GreaterThan(0)] int $page` parameter's schema
     * showed only `{type: integer}`, with no hint of the constraint a
     * client would actually need to satisfy.
     *
     * forType() itself keeps returning a genuinely empty PHP array `[]`
     * for `mixed`/an untyped or union parameter — deliberately, so the
     * `[...$schema, ...]` spread two lines below stays safe (PHP's array-
     * spread operator throws for a non-iterable `stdClass`, and a
     * constraint attribute on a `mixed`-typed parameter is legal syntax,
     * so this isn't a hypothetical). The empty-schema-means-"anything"
     * PHP array is only ever cast to a real `stdClass` — so it encodes as
     * JSON `{}`, not the invalid `[]` a bare empty array would produce —
     * once every constraint has already been merged into a genuine plain
     * array, on the way out. A schema left non-empty by a merged
     * constraint (e.g. `#[In(['a', 'b'])] mixed $x`) is untouched.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function schemaForScalar(ReflectionParameter $parameter, ?ReflectionType $type): array|\stdClass
    {
        $schema = self::forType($type);

        foreach ($parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $schema = [...$schema, ...self::forConstraint($attribute->newInstance())];
        }

        return $schema === [] ? (object) [] : $schema;
    }

    /**
     * @return class-string|null null unless $parameter is typed `array` and
     *     carries a #[ListOf(SomeClass::class)] attribute — the same
     *     attribute Kinetis\Validation\Hydrator reads to hydrate a list of
     *     nested DTOs, reused here so the schema and the hydration behavior
     *     it describes can never disagree about which parameters are lists.
     */
    private static function listItemClassFor(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->getName() !== 'array') {
            return null;
        }

        $attributes = $parameter->getAttributes(ListOf::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->itemClass();
    }

    /**
     * The complete, audited policy for every builtin type name PHP can
     * actually attach to a parameter via `ReflectionNamedType` — confirmed
     * empirically against a real PHP 8.4 reflection dump, not assumed from
     * the manual: `int`, `float`, `bool`, `string`, `array`, `iterable`,
     * `callable`, `object`, `mixed`, `null`, `false`, `true`. (`void`/
     * `never` fatal at declaration time on a parameter, so they can never
     * reach here; `self`/`parent`/`static` report `isBuiltin() === false`
     * and are routed through the class-typed branch in forParameters()
     * instead, never this method.) Each of the twelve gets one explicit
     * arm below — deliberately supported or deliberately rejected, never
     * left to an implicit default:
     *
     * - `int`/`float`/`bool`/`string`/`array`/`mixed`: supported, as
     *   before.
     * - `iterable`: supported, identically to `array` — a JSON body can
     *   only ever decode into a PHP array (never a real `Traversable`),
     *   and a plain array genuinely satisfies PHP's `iterable` type, so
     *   the wire contract and the accepted value are the same as `array`'s.
     * - `null`: supported — a genuinely degenerate but truthful type
     *   (`{type: 'null'}`); the only value that can ever satisfy it is a
     *   literal JSON `null`.
     * - `true`/`false`: supported — PHP 8.2's standalone literal-boolean
     *   types, narrower than `bool`. Represented as `{type: 'boolean',
     *   const: true}`/`{type: 'boolean', const: false}`, the standard JSON
     *   Schema way to say "not just any boolean, this exact one."
     * - `object`: rejected. JSON input in this framework always decodes
     *   into arrays and scalars (`json_decode(..., associative: true)`),
     *   never a real PHP object, so no request value can ever truthfully
     *   satisfy a bare `object` parameter — there's nothing correct this
     *   method could describe.
     * - `callable`: rejected, for a security reason as much as a
     *   representational one — a JSON string handed to a `callable`-typed
     *   parameter is exactly the shape of an arbitrary-function-name
     *   injection risk if it's ever invoked downstream, so this is refused
     *   outright rather than described as if it were safe to accept.
     *
     * `object`/`callable` throw here (schema-generation time) as they
     * always have; Kinetis\Validation\Hydrator::typeMismatchMessage()
     * additionally rejects both with a normal 422/MCP validation error at
     * hydrate time — the guaranteed-to-run boundary that fires on every
     * request regardless of whether OpenAPI/MCP schema generation ever
     * runs at all, so a route or tool carrying one of these can never let
     * a real value reach the constructor unchecked.
     *
     * `mixed` and an untyped/union/intersection parameter (never a
     * ReflectionNamedType, so caught by the guard clause immediately
     * below) both mean "any JSON value" — JSON Schema's own way to say
     * that is the empty schema object `{}`, never the empty schema array
     * `[]` a bare PHP `[]` would serialize as. This method still returns
     * a genuine, uncast PHP `[]` for both, deliberately: schemaForScalar()
     * — the one real caller — merges each Constraint attribute's own
     * schema fragment into this return value via array-spread, which
     * throws for a non-iterable `stdClass`; casting here would make that
     * merge unsafe the moment a constraint attribute is legally (if
     * oddly) placed on a `mixed`-typed parameter. schemaForScalar()
     * applies the `(object)` cast itself, once, only on its own final
     * return value, after every constraint has already been merged as a
     * plain array — see its own docblock.
     *
     * @return array<string, mixed>
     */
    public static function forType(?ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        // A standalone `null` type's own allowsNull() is always true, and
        // {type: 'null'} already says everything withNullableSchema()
        // below would otherwise try to additionally widen it into — so
        // it's returned directly rather than risk a duplicated
        // ['null', 'null'].
        if ($type->getName() === 'null') {
            return ['type' => 'null'];
        }

        $schema = match ($type->getName()) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            // A plain `array` (no #[ListOf]) is a real JSON array on the
            // wire — Hydrator::typeMismatchMessage() now rejects anything
            // else for it (see this class's own docblock) — never the
            // `object` this used to fall through to, which described the
            // wrong wire shape entirely. `iterable` shares the exact same
            // wire shape, per this method's own docblock above.
            'array', 'iterable' => ['type' => 'array'],
            'true' => ['type' => 'boolean', 'const' => true],
            'false' => ['type' => 'boolean', 'const' => false],
            // `mixed` genuinely accepts every JSON value, null included —
            // the empty schema (`{}` once schemaForScalar() casts it, see
            // this method's own docblock for why not here) is JSON
            // Schema's own way to say "anything", so withNullableSchema()
            // below correctly leaves it alone (nothing to widen) regardless
            // of allowsNull().
            'mixed' => [],
            default => throw JsonSchemaException::unsupportedBuiltinType($type->getName()),
        };

        return self::withNullableSchema($schema, $type->allowsNull());
    }

    /**
     * @return array<string, mixed>
     */
    public static function forConstraint(Constraint $constraint): array
    {
        return match (true) {
            $constraint instanceof Email => ['format' => 'email'],
            $constraint instanceof MinLength => ['minLength' => $constraint->length()],
            $constraint instanceof MaxLength => ['maxLength' => $constraint->length()],
            $constraint instanceof GreaterThan => ['exclusiveMinimum' => $constraint->threshold()],
            $constraint instanceof LessThan => ['exclusiveMaximum' => $constraint->threshold()],
            $constraint instanceof Regex => ['pattern' => $constraint->pattern()],
            $constraint instanceof In => ['enum' => $constraint->choices()],
            $constraint instanceof Url => ['format' => 'uri'],
            $constraint instanceof Uuid => ['format' => 'uuid'],
            // NotBlank has no distinct JSON Schema keyword — falls through
            // to the default case below.
            default => [],
        };
    }
}
