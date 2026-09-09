<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Validation\Exception\JsonSchemaException;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * Maps reflection type/constraint metadata to JSON Schema fragments.
 * Extracted out of OpenApiGenerator once McpRegistry needed the identical
 * mapping for MCP tool input schemas — the same DTO constructor and
 * #[Email]/#[MinLength]/#[GreaterThan] constraint attributes describe
 * both an HTTP request body and an MCP tool call's arguments, so the
 * type-to-schema logic shouldn't live twice.
 *
 * A rule's own keywords come from the rule — {@see Constraint::schema()},
 * asked on an instance built from the same literal `{class, args}`
 * descriptor Hydrator validates against — never from a list of framework
 * classes this file knows about. An application constraint therefore
 * describes itself in a generated OpenAPI document and an MCP tool's
 * `inputSchema` with nothing to register.
 *
 * Every object this produces is closed — `additionalProperties: false`
 * — and every DTO class may state cross-field rules of its own through
 * {@see ObjectConstraint::schema()}, merged the same way a field rule's
 * keywords are and refused on the same collision.
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
 * *explicitly-null* value once present. The `required` array matches
 * this: `!isDefaultValueAvailable()` alone, with no `allowsNull()` term
 * — which is also what leaves a `T|Absent` presence field optional,
 * since such a field always declares a default. `OpenApiGenerator`'s
 * own `#[Query]`/path parameter `required` (computed independently of
 * this class) already followed this same rule and needed no change — it
 * never consulted nullability either.
 */
final class JsonSchema
{
    /**
     * One DTO class's own object schema, built from its constructor.
     *
     * This is the DTO half of the two schema entry points, and the only
     * one where a `T|Absent` presence union is a legal declaration: the
     * published property describes T, widened with `null` where the union
     * names it, and the member is optional because the declaration
     * carries a default. Absent itself never appears — no client can send
     * it, so no schema may ask for it.
     *
     * A class with no constructor has no members at all, which is a
     * closed object with empty `properties`, not an unconstrained one.
     *
     * @param class-string $class
     * @param (callable(class-string): array<string, mixed>)|null $classSchema used
     *        instead of inlining a nested class-typed parameter's own schema — see
     *        forParameters(). Passed through recursively so every nesting depth is
     *        resolved the same way, not just the first one.
     * @return array<string, mixed>
     */
    public static function forClass(string $class, ?callable $classSchema = null): array
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        return self::withObjectRuleSchema(
            self::objectSchema(
                $constructor === null ? [] : $constructor->getParameters(),
                [],
                $classSchema,
                $class,
            ),
            $reflection,
        );
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
        return self::objectSchema($parameters, $excludeTypes, $classSchema, null);
    }

    /**
     * The one object-schema builder both entry points share: properties,
     * required, and the closure every Kinetis-described object carries.
     *
     * $dtoClass names the DTO whose constructor these parameters are, and
     * is null for a method's own parameter list. That is the whole
     * difference between the two: a DTO field may declare the `T|Absent`
     * presence union, a transport method parameter may not, and neither
     * may declare any other composite type. Rejecting one here means a
     * tool whose schema cannot be stated truthfully fails at
     * registration, before an agent is ever shown it.
     *
     * `additionalProperties: false` is on every object this produces,
     * including one with no properties at all. It is what
     * `Kinetis\Validation\Hydrator` enforces for JSON input and
     * `Kinetis\Mcp\McpDispatcher` for a tool's arguments; a form-encoded
     * body is deliberately more tolerant at runtime (see
     * Hydrator::unknownMemberViolations()), so a client that sends only
     * what the document describes is always accepted, whichever source it
     * writes in.
     *
     * @param list<ReflectionParameter> $parameters
     * @param list<class-string> $excludeTypes
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @param class-string|null $dtoClass
     * @return array<string, mixed>
     */
    private static function objectSchema(array $parameters, array $excludeTypes, ?callable $classSchema, ?string $dtoClass): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $parameter) {
            $declared = $parameter->getType();

            if ($declared instanceof ReflectionNamedType && in_array($declared->getName(), $excludeTypes, true)) {
                continue;
            }

            // A presence union is described by the type a supplied value
            // has; every other parameter by what it declares. Hydrator
            // owns which unions are legal and what they mean, so the
            // schema cannot drift from what hydration accepts.
            $absent = $dtoClass !== null ? Hydrator::absentUnion($parameter, $dtoClass) : null;
            $type = $absent !== null ? $absent[0] : $declared;

            if ($type !== null && !$type instanceof ReflectionNamedType) {
                throw JsonSchemaException::compositeType($parameter->getName());
            }

            $nullable = $absent !== null ? $absent[1] : ($type instanceof ReflectionNamedType && $type->allowsNull());

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
            } elseif (self::isObjectMap($parameter, $type)) {
                // #[ObjectMap] is the one `array`-typed property whose
                // wire shape is a JSON object, so it is the one that
                // must not fall through to forType()'s `{type: array}`.
                // `additionalProperties: true` is JSON Schema's own
                // spelling for "any keys, any values", which is exactly
                // what Hydrator admits — no per-key schema is declared
                // because none is checked.
                $properties[$parameter->getName()] = self::withNullableSchema(
                    ['type' => 'object', 'additionalProperties' => true],
                    $nullable,
                );
            } elseif (($listItemClass = self::listItemClassFor($parameter, $type)) !== null) {
                $properties[$parameter->getName()] = self::schemaForListOf($parameter, $listItemClass, $classSchema, $nullable);
            } else {
                $schema = self::schemaForScalar($parameter, $type);
                // T inside a presence union is never itself nullable —
                // `null` is a sibling member of the union, not part of T —
                // so forType() had nothing to widen and the declared
                // `null` is added here instead. (`mixed` cannot appear in
                // a PHP union, so the stdClass "anything" schema never
                // reaches this branch.)
                $properties[$parameter->getName()] = $absent !== null && is_array($schema)
                    ? self::withNullableSchema($schema, $absent[1])
                    : $schema;
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
            'additionalProperties' => false,
        ];
    }

    /**
     * The class's own ObjectConstraint attributes, asked for the
     * object-level keywords that state them, merged onto the schema its
     * PHP declaration owns.
     *
     * The collision rule is the field rules' own, one level out: `type`,
     * `properties`, `required` and `additionalProperties` are the
     * declaration's — the first three state what the constructor is, the
     * last what Hydrator enforces — and a rule refines that without
     * replacing any of it. Two rules claiming one keyword are refused for the same
     * reason a `#[MinLength(3)] #[MinLength(5)]` pair is: a schema could
     * state only one of them, and letting declaration order pick would
     * publish a bound the request is not checked against.
     *
     * A cross-field rule that no keyword expresses contributes `[]` and
     * leaves the schema exactly as truthful as it was — narrower at
     * runtime than the document says, which is documented rather than
     * approximated.
     *
     * @param array<string, mixed> $schema
     * @param ReflectionClass<object> $class
     * @return array<string, mixed>
     * @throws JsonSchemaException
     */
    private static function withObjectRuleSchema(array $schema, ReflectionClass $class): array
    {
        $phpOwned = $schema;
        $declaredBy = [];

        foreach (Hydrator::collectObjectRules($class) as $descriptor) {
            $ruleClass = $descriptor['class'];

            foreach (new $ruleClass(...$descriptor['args'])->schema() as $keyword => $value) {
                if (array_key_exists($keyword, $declaredBy)) {
                    throw JsonSchemaException::duplicateKeyword($keyword, $declaredBy[$keyword], $ruleClass);
                }

                if (array_key_exists($keyword, $phpOwned)) {
                    throw JsonSchemaException::declaredShapeKeyword($keyword, $ruleClass);
                }

                $declaredBy[$keyword] = $ruleClass;
                $schema[$keyword] = $value;
            }
        }

        return $schema;
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
        return self::withNullableSchema(self::objectSchemaFor($class, $classSchema), $nullable);
    }

    /**
     * array + #[ListOf(SomeClass::class)] — same expand-instead-of-collapse
     * reasoning as schemaForClassTyped(), just one level further out:
     * {type: array, items: <SomeClass's own schema>} instead of a bare
     * {type: object} that would tell an agent nothing about what each
     * element looks like.
     *
     * Constraint attributes merge here for the same reason they merge
     * into schemaForScalar()'s plain-array schema: Hydrator runs every
     * Constraint on a #[ListOf] property once its elements are hydrated,
     * so a `#[MinItems(1)]` the request actually has to satisfy belongs
     * in the schema describing it.
     *
     * @param class-string $listItemClass
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     */
    private static function schemaForListOf(ReflectionParameter $parameter, string $listItemClass, ?callable $classSchema, bool $nullable): array
    {
        return self::withNullableSchema(self::withConstraintSchema([
            'type' => 'array',
            'items' => self::objectSchemaFor($listItemClass, $classSchema),
        ], $parameter), $nullable);
    }

    /**
     * One class's own object schema — expanded from its constructor, or
     * whatever $classSchema substitutes for it (OpenApiGenerator's `$ref`).
     *
     * A class that cannot be instantiated (an interface, an abstract class,
     * an enum) is rejected rather than described: `Kinetis\Validation\Hydrator`
     * accepts only an already-constructed instance for such a field, and
     * refuses it outright as a #[ListOf] item class — see its own docblock —
     * so no wire value could satisfy the {type: object} schema expanding it
     * would produce. The one interface a request can carry,
     * `UploadedFileInterface`, never reaches here; objectSchema()
     * describes it as `{type: string, format: binary}` directly.
     *
     * @param class-string $class
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     */
    private static function objectSchemaFor(string $class, ?callable $classSchema): array
    {
        if (!new ReflectionClass($class)->isInstantiable()) {
            throw JsonSchemaException::unsupportedClassType($class);
        }

        return $classSchema !== null ? $classSchema($class) : self::forClass($class);
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
     * for `mixed` and an untyped parameter — deliberately, so the
     * merge below stays safe (withConstraintSchema() reads keys from its
     * base and adds to it, neither of which a `stdClass` does, and a
     * constraint attribute on a `mixed`-typed parameter is legal syntax,
     * so this isn't a hypothetical). The empty-schema-means-"anything"
     * PHP array is only
     * ever cast to a real `stdClass` — so it encodes as JSON `{}`, not
     * the invalid `[]` a bare empty array would produce — once every
     * constraint has already been merged into a genuine plain array, on
     * the way out. A schema left non-empty by a merged constraint (e.g.
     * `#[In(['a', 'b'])] mixed $x`) is untouched.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function schemaForScalar(ReflectionParameter $parameter, ?ReflectionType $type): array|\stdClass
    {
        $schema = self::withConstraintSchema(self::forType($type), $parameter);

        return $schema === [] ? (object) [] : $schema;
    }

    /**
     * $base — the keywords the parameter's own PHP declaration owns —
     * plus every rule declared on it, merged into a single schema
     * fragment. The rules are read through
     * Hydrator::collectConstraints(), from the same literal descriptors
     * Hydrator validates against, so the published schema and the
     * enforced check can never describe different rule sets. Each rule
     * is constructed here, asked for its keywords, and discarded.
     *
     * A keyword may be claimed once, by exactly one owner. The PHP type
     * states the shape — `type`, and a #[ListOf]'s `items` — and a rule
     * refines it: `#[MinLength(3)] string` narrows which strings the
     * field takes, and cannot make it something other than a string,
     * because Hydrator's own type check is not reading the rule. A rule
     * contributing `type` back would publish a shape the request is not
     * checked against, so it is refused rather than merged. Two rules
     * claiming one keyword are refused for the same reason: a
     * `#[MinLength(3)] #[MinLength(5)]` pair states two different
     * minimum lengths, and letting declaration order silently pick one
     * would publish a bound the request is not checked against.
     *
     * A rule's own keyword is its own: whatever it nests inside that
     * keyword — an `enum`'s members, a `format`'s name — is the rule
     * speaking about its own subject and is never inspected here.
     *
     * @param array<string, mixed> $base
     * @return array<string, mixed>
     * @throws JsonSchemaException
     */
    private static function withConstraintSchema(array $base, ReflectionParameter $parameter): array
    {
        $schema = $base;
        $declaredBy = [];

        foreach (Hydrator::collectConstraints($parameter) as $descriptor) {
            $class = $descriptor['class'];

            foreach (new $class(...$descriptor['args'])->schema() as $keyword => $value) {
                if (array_key_exists($keyword, $declaredBy)) {
                    throw JsonSchemaException::duplicateKeyword($keyword, $declaredBy[$keyword], $class);
                }

                if (array_key_exists($keyword, $base)) {
                    throw JsonSchemaException::declaredShapeKeyword($keyword, $class);
                }

                $declaredBy[$keyword] = $class;
                $schema[$keyword] = $value;
            }
        }

        return $schema;
    }

    /**
     * Whether $parameter's value type is `array` and it carries
     * #[ObjectMap] — the same attribute Kinetis\Validation\Hydrator reads
     * to admit a JSON object there, read here so the schema and the
     * hydration behavior it describes can never disagree about which
     * properties are object maps. $type is the parameter's value type,
     * already resolved through any presence union, for the same reason
     * Hydrator resolves it before asking the same question.
     */
    private static function isObjectMap(ReflectionParameter $parameter, ?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType
            && $type->getName() === 'array'
            && $parameter->getAttributes(ObjectMap::class) !== [];
    }

    /**
     * @return class-string|null null unless $parameter's value type ($type,
     *     already resolved through any presence union) is `array` and it
     *     carries a #[ListOf(SomeClass::class)] attribute — the same
     *     attribute Kinetis\Validation\Hydrator reads to hydrate a list of
     *     nested DTOs, reused here so the schema and the hydration behavior
     *     it describes can never disagree about which parameters are lists.
     */
    private static function listItemClassFor(ReflectionParameter $parameter, ?ReflectionType $type): ?string
    {
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
     * The JSON Schema fragment for one builtin type. Only
     * `Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES` is
     * describable: every other builtin — `null`, `true`, `false`,
     * `object`, `callable` — has no request value that could satisfy it,
     * and is refused here rather than published as a schema no client
     * could ever meet. `iterable` shares `array`'s fragment: decoded
     * JSON input only ever produces a PHP array, never a real
     * `Traversable`, and a plain array satisfies PHP's `iterable`.
     * (`void`/`never` fatal at declaration time on a parameter;
     * `self`/`parent`/`static` report `isBuiltin() === false` and are
     * routed through objectSchema()'s class-typed branch instead.)
     *
     * `mixed` and an untyped parameter (the latter never a
     * ReflectionNamedType, so caught by the guard clause immediately
     * below) both mean "any JSON value" — JSON Schema's own way to say
     * that is the empty schema object `{}`, never the empty schema array
     * `[]` a bare PHP `[]` would serialize as. This method still returns
     * a genuine, uncast PHP `[]` for both, deliberately: schemaForScalar()
     * — the one real caller — hands this return value to
     * withConstraintSchema() as the base each Constraint attribute's own
     * keywords merge onto, an array operation a `stdClass` cannot stand
     * in for; casting here would break that merge the moment a
     * constraint attribute is legally (if oddly) placed on a
     * `mixed`-typed parameter. A composite type never reaches here at
     * all: objectSchema() refuses one before describing the parameter,
     * and the guard clause below is what answers an untyped one.
     * schemaForScalar()
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

        $schema = match ($type->getName()) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            // A plain `array` (no #[ListOf]) is a real JSON array on the
            // wire, which is what Hydrator's own check enforces — never
            // an `object`, which would describe the wrong wire shape
            // entirely.
            'array', 'iterable' => ['type' => 'array'],
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
}
