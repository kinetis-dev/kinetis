<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Validation\Exception\JsonSchemaException;
use BackedEnum;
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
     * The JSON type each scalar spelling publishes. forType() answers
     * the same question for a declared type; a #[ListOf] element and a
     * backed enum's backing value arrive as a plain type name instead,
     * with no ReflectionType behind them.
     *
     * @var array<string, string>
     */
    private const array WIRE_TYPES = ['string' => 'string', 'int' => 'integer', 'float' => 'number', 'bool' => 'boolean'];

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
     * may declare any other composite type. Rejecting one — in
     * {@see propertySchema()}, where a parameter's effective type is
     * settled — means a tool whose schema cannot be stated truthfully
     * fails at registration, before an agent is ever shown it.
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

            $properties[$parameter->getName()] = self::propertySchema($parameter, $declared, $classSchema, $dtoClass);

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
     * One parameter's published schema: the branch its type selects,
     * widened for null wherever the declaration admits it.
     *
     * A presence union is described by the type a supplied value has;
     * every other parameter by what it declares. Hydrator owns which
     * unions are legal and what they mean, so the schema cannot drift
     * from what hydration accepts.
     *
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @param class-string|null $dtoClass as objectSchema() passes it.
     * @return array<string, mixed>|\stdClass
     * @throws JsonSchemaException
     */
    private static function propertySchema(
        ReflectionParameter $parameter,
        ?ReflectionType $declared,
        ?callable $classSchema,
        ?string $dtoClass,
    ): array|\stdClass {
        $absent = $dtoClass !== null ? Hydrator::absentUnion($parameter, $dtoClass) : null;
        $type = $absent !== null ? $absent[0] : $declared;

        // Every branch below reads $type as a named type or as nothing
        // at all; this refusal is what establishes that.
        if ($type !== null && !$type instanceof ReflectionNamedType) {
            throw JsonSchemaException::compositeType($parameter->getName());
        }

        $nullable = $absent !== null ? $absent[1] : ($type !== null && $type->allowsNull());
        // Asked of every parameter, not only the ones that reach the
        // list branch below, so a #[ListOf]/#[Each] declaration Hydrator
        // would refuse is refused here too — whatever else the parameter
        // declares.
        $listItem = Hydrator::listItem($parameter, $type);

        if ($type !== null && $type->getName() === UploadedFileInterface::class) {
            // An UploadedFileInterface-typed #[Body] field is never a
            // nested DTO — Dispatcher merges it in directly from the
            // request's own normalized uploaded files (see
            // mergeUploads()'s own docblock), so
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
            $schema = self::withNullableSchema(['type' => 'string', 'format' => 'binary'], $nullable);
        } elseif ($type !== null && !$type->isBuiltin()) {
            $schema = self::schemaForNonBuiltin($parameter, $type, $classSchema, $dtoClass, $nullable);
        } elseif (self::isObjectMap($parameter, $type)) {
            // #[ObjectMap] is the one `array`-typed property whose
            // wire shape is a JSON object, so it is the one that
            // must not fall through to forType()'s `{type: array}`.
            // `additionalProperties: true` is JSON Schema's own
            // spelling for "any keys, any values", which is exactly
            // what Hydrator admits — no per-key schema is declared
            // because none is checked.
            $schema = self::withNullableSchema(['type' => 'object', 'additionalProperties' => true], $nullable);
        } elseif ($listItem !== null) {
            $schema = self::schemaForListOf($parameter, $listItem, $classSchema, $nullable);
        } else {
            $schema = self::scalarPropertySchema($parameter, $type, $absent);
        }

        return $schema;
    }

    /**
     * A class-typed parameter's schema: the scalar its backed-enum cases
     * are written as, or the class's own expanded object schema.
     *
     * A backed enum is a DTO field's own shape: its wire value is a
     * scalar, and Hydrator turns that into the case. A transport
     * method's parameter list stays where it was — an enum there is a
     * class no request value can construct, so schemaForClassTyped()
     * refuses it and the tool fails registration rather than advertising
     * a schema McpDispatcher could not bind.
     *
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @param class-string|null $dtoClass
     * @return array<string, mixed>
     */
    private static function schemaForNonBuiltin(
        ReflectionParameter $parameter,
        ReflectionNamedType $type,
        ?callable $classSchema,
        ?string $dtoClass,
        bool $nullable,
    ): array {
        /** @var class-string $class */
        $class = $type->getName();
        $backingType = $dtoClass !== null ? Hydrator::backedEnumScalarType($class, $parameter) : null;

        return $backingType !== null
            ? self::schemaForEnum($parameter, $class, $backingType, $nullable)
            : self::schemaForClassTyped($class, $classSchema, $nullable);
    }

    /**
     * A scalar (or untyped) parameter's schema.
     *
     * T inside a presence union is never itself nullable — `null` is a
     * sibling member of the union, not part of T — so forType() had
     * nothing to widen and the declared `null` is added here instead.
     * (`mixed` cannot appear in a PHP union, so the stdClass "anything"
     * schema never reaches this branch.)
     *
     * @param array{0: ReflectionNamedType, 1: bool}|null $absent
     * @return array<string, mixed>|\stdClass
     */
    private static function scalarPropertySchema(
        ReflectionParameter $parameter,
        ?ReflectionNamedType $type,
        ?array $absent,
    ): array|\stdClass {
        $schema = self::schemaForScalar($parameter, $type);

        return $absent !== null && is_array($schema)
            ? self::withNullableSchema($schema, $absent[1])
            : $schema;
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
        return self::withRuleKeywords($schema, Hydrator::collectObjectRules($class));
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
     * array + #[ListOf] — same expand-instead-of-collapse reasoning as
     * schemaForClassTyped(), one level further out: {type: array,
     * items: <the element's own schema>} instead of a bare {type:
     * array} that would tell an agent nothing about what each element
     * looks like.
     *
     * The two levels own different keywords, and each is stated once.
     * The field's own Constraint attributes describe the list —
     * `#[MinItems(1)]` is a bound the request has to satisfy — and
     * merge onto the array; its #[Each] rules describe one element and
     * merge onto `items`, which is exactly where Hydrator applies them.
     *
     * @param array{scalarType: ?string, enumClass: ?class-string, dtoClass: ?class-string, constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>} $item
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     */
    private static function schemaForListOf(ReflectionParameter $parameter, array $item, ?callable $classSchema, bool $nullable): array
    {
        return self::withNullableSchema(self::withConstraintSchema([
            'type' => 'array',
            'items' => self::itemSchema($item, $classSchema),
        ], $parameter), $nullable);
    }

    /**
     * One #[ListOf] element's own schema, from the same classification
     * Hydrator resolves it with: a DTO's expanded object schema, an
     * uploaded file's binary string, a backed enum's type-and-cases
     * pair, or the JSON type of the scalar it declares — with every
     * #[Each] rule's keywords merged in.
     *
     * @param array{scalarType: ?string, enumClass: ?class-string, dtoClass: ?class-string, constraints: list<array{class: class-string<Constraint>, args: array<int|string, mixed>}>} $item
     * @param (callable(class-string): array<string, mixed>)|null $classSchema
     * @return array<string, mixed>
     * @throws JsonSchemaException
     */
    private static function itemSchema(array $item, ?callable $classSchema): array
    {
        if ($item['dtoClass'] === UploadedFileInterface::class) {
            // The same {type: string, format: binary} an
            // UploadedFileInterface-typed field publishes, for the same
            // reason — see objectSchema() — with the element's own
            // #[Each] rules merged in through the one keyword merge
            // every other element already uses.
            return self::withRuleKeywords(['type' => 'string', 'format' => 'binary'], $item['constraints']);
        }

        if ($item['dtoClass'] !== null) {
            // A DTO list carries no #[Each] rules — Hydrator refuses
            // them there — so nothing merges onto an element that
            // already states its own fields' rules.
            return self::objectSchemaFor($item['dtoClass'], $classSchema);
        }

        /** @var string $scalarType a non-DTO element always declares one */
        $scalarType = $item['scalarType'];

        $base = $item['enumClass'] !== null
            ? self::enumSchema($item['enumClass'], $scalarType)
            : ['type' => self::WIRE_TYPES[$scalarType]];

        return self::withRuleKeywords($base, $item['constraints']);
    }

    /**
     * A backed-enum field: the enum's own domain, plus every rule
     * declared on the field, merged through the one keyword merge a
     * scalar field and a list element already use — so a rule the
     * runtime applies to the resolved case is stated in the document
     * too, and a rule claiming `type` or `enum` is refused here as it
     * is anywhere else.
     *
     * A rule's keywords describe the wire value, which for an enum is
     * its backing scalar; the rule itself sees the case. Nullability is
     * widened last, over both domains the merged schema now carries.
     *
     * @param class-string $enum
     * @return array<string, mixed>
     * @throws JsonSchemaException
     */
    private static function schemaForEnum(ReflectionParameter $parameter, string $enum, string $backingType, bool $nullable): array
    {
        return self::withNullableSchema(
            self::withConstraintSchema(self::enumSchema($enum, $backingType), $parameter),
            $nullable,
        );
    }

    /**
     * A backed enum's own two keywords: the JSON type its backing
     * values carry, and the exact set of them. Both are the domain
     * Hydrator binds — it resolves the wire value as that scalar and
     * then asks the enum for the case naming it — so a client
     * generating requests from this document can send nothing the
     * field rejects.
     *
     * The cases are read here rather than carried anywhere: a schema is
     * built where it is published, and an enum's cases are fixed for
     * the process's lifetime.
     *
     * @param class-string $enum
     * @return array<string, mixed>
     */
    private static function enumSchema(string $enum, string $backingType): array
    {
        /** @var class-string<BackedEnum> $enum */
        return [
            'type' => self::WIRE_TYPES[$backingType],
            'enum' => array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases()),
        ];
    }

    /**
     * One class's own object schema — expanded from its constructor, or
     * whatever $classSchema substitutes for it (OpenApiGenerator's `$ref`).
     *
     * A class that cannot be instantiated (an interface, an abstract class,
     * a unit enum) is rejected rather than described:
     * `Kinetis\Validation\Hydrator` accepts only an already-constructed
     * instance for such a field, and refuses every other one outright as a
     * #[ListOf] element type — see its own docblock — so no wire value
     * could satisfy the {type: object} schema expanding it would produce.
     * Two class types never reach here: `UploadedFileInterface`, which
     * objectSchema() and itemSchema() both describe as
     * `{type: string, format: binary}`, and a backed enum on a DTO field,
     * which objectSchema() describes as that enum's own domain.
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

        // A closed set of values is a second domain the same value has
        // to satisfy, so widening only `type` would publish a schema
        // that still rejects the null the declaration accepts.
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $schema['enum'] = [...$schema['enum'], null];
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
     * This reads baseTypeSchema() rather than forType() so nullability is
     * widened once, last, over the completed schema — the same ordering
     * schemaForEnum() uses — rather than by forType() before a rule's
     * keywords are merged in. Widening first would leave a rule
     * contributing a second closed domain (#[In]'s `enum`) rejecting the
     * `null` the widened `type` already admits.
     *
     * baseTypeSchema() itself keeps returning a genuinely empty PHP array
     * `[]` for `mixed` and an untyped parameter — deliberately, so the
     * merge below stays safe (withConstraintSchema() reads keys from its
     * base and adds to it, neither of which a `stdClass` does, and a
     * constraint attribute on a `mixed`-typed parameter is legal syntax,
     * so this isn't a hypothetical). The empty-schema-means-"anything"
     * PHP array is only
     * ever cast to a real `stdClass` — so it encodes as JSON `{}`, not
     * the invalid `[]` a bare empty array would produce — once every
     * constraint has already been merged into a genuine plain array, on
     * the way out. A schema left non-empty by a merged constraint still
     * widens for null: `#[In(['a', 'b'])] mixed $x` has no `type`
     * keyword for withNullableSchema() to touch, but its merged `enum`
     * grows to admit `null` exactly like a nullable scalar's does —
     * `resolveScalar()` returns an explicit `null` for `mixed` without
     * ever running the rule against it, the same short-circuit an
     * untyped or nullable-scalar field gets.
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function schemaForScalar(ReflectionParameter $parameter, ?ReflectionType $type): array|\stdClass
    {
        $base = $type instanceof ReflectionNamedType ? self::baseTypeSchema($type) : [];
        // Hydrator::compileParameter() sets its own compiled plan's
        // `allowsNull` the identical way: an untyped parameter has no
        // ReflectionNamedType to ask, but resolveScalar() still accepts
        // an explicit null for it before running its constraints, so the
        // published schema has to widen for null here too, not only when
        // $type carries its own answer.
        $nullable = $type === null || $type->allowsNull();
        $schema = self::withNullableSchema(self::withConstraintSchema($base, $parameter), $nullable);

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
        return self::withRuleKeywords($base, Hydrator::collectConstraints($parameter));
    }

    /**
     * The one keyword merge, for the three things that own rules: a
     * field or parameter, a DTO class, and one #[ListOf] element. Each
     * builds every rule from its literal {class, args} descriptor, asks
     * it for keywords, and discards it.
     *
     * A keyword may be claimed once, by exactly one owner, and $base is
     * the declaration's own — refusals are stated on
     * withConstraintSchema() above, and they hold identically wherever
     * this is called from.
     *
     * @param array<string, mixed> $base
     * @param list<array{class: class-string<Constraint>|class-string<ObjectConstraint>, args: array<int|string, mixed>}> $rules
     * @return array<string, mixed>
     * @throws JsonSchemaException
     */
    private static function withRuleKeywords(array $base, array $rules): array
    {
        $schema = $base;
        $declaredBy = [];

        foreach ($rules as $descriptor) {
            $ruleClass = $descriptor['class'];

            foreach (new $ruleClass(...$descriptor['args'])->schema() as $keyword => $value) {
                if (array_key_exists($keyword, $declaredBy)) {
                    throw JsonSchemaException::duplicateKeyword($keyword, $declaredBy[$keyword], $ruleClass);
                }

                if (array_key_exists($keyword, $base)) {
                    throw JsonSchemaException::declaredShapeKeyword($keyword, $ruleClass);
                }

                $declaredBy[$keyword] = $ruleClass;
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
     * The JSON Schema fragment for one builtin type, widened for
     * nullability. Only `Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES`
     * is describable: every other builtin — `null`, `true`, `false`,
     * `object`, `callable` — has no request value that could satisfy it,
     * and is refused here rather than published as a schema no client
     * could ever meet. `iterable` shares `array`'s fragment: decoded
     * JSON input only ever produces a PHP array, never a real
     * `Traversable`, and a plain array satisfies PHP's `iterable`.
     * (`void`/`never` fatal at declaration time on a parameter;
     * `self`/`parent`/`static` report `isBuiltin() === false` and are
     * routed through objectSchema()'s class-typed branch instead.)
     *
     * An untyped parameter is never a ReflectionNamedType, so it is
     * caught by the guard clause below rather than reaching
     * baseTypeSchema() at all; `mixed` reaches it and comes back `[]`,
     * which withNullableSchema() correctly leaves alone (nothing to
     * widen) regardless of allowsNull() — "any JSON value" already
     * includes `null`.
     *
     * schemaForScalar() does not call this directly: it reads
     * baseTypeSchema() itself and widens nullability only after a
     * parameter's constraint keywords are merged in, so a rule
     * contributing a second closed domain (#[In]'s `enum`) is widened
     * too — see that method's own docblock. This method keeps widening
     * immediately because it is also a direct, public probe of one
     * declared type's own schema, with no constraint to merge.
     *
     * @return array<string, mixed>
     */
    public static function forType(?ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        return self::withNullableSchema(self::baseTypeSchema($type), $type->allowsNull());
    }

    /**
     * One declared builtin type's own schema, before any nullability
     * widening or constraint merge — the non-null declaration domain
     * schemaForScalar() and forType() both widen or merge onto, each in
     * its own order.
     *
     * @return array<string, mixed>
     */
    private static function baseTypeSchema(ReflectionNamedType $type): array
    {
        return match ($type->getName()) {
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
            // its own docblock for why not here) is JSON Schema's own way
            // to say "anything".
            'mixed' => [],
            default => throw JsonSchemaException::unsupportedBuiltinType($type->getName()),
        };
    }
}
