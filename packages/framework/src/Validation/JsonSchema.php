<?php

declare(strict_types=1);

namespace Kinetis\Validation;

use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;
use Kinetis\Validation\Constraints\LessThan;
use Kinetis\Validation\Constraints\MaxLength;
use Kinetis\Validation\Constraints\MinLength;
use Kinetis\Validation\Constraints\Regex;
use Kinetis\Validation\Constraints\Url;
use Kinetis\Validation\Constraints\Uuid;
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

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                /** @var class-string $class */
                $class = $type->getName();
                $properties[$parameter->getName()] = self::schemaForClassTyped($class, $classSchema);
            } elseif (($listItemClass = self::listItemClassFor($parameter)) !== null) {
                $properties[$parameter->getName()] = self::schemaForListOf($listItemClass, $classSchema);
            } else {
                $properties[$parameter->getName()] = self::schemaForScalar($parameter, $type);
            }

            if (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) {
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
    private static function schemaForClassTyped(string $class, ?callable $classSchema): array
    {
        return $classSchema !== null ? $classSchema($class) : self::forClass($class);
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
    private static function schemaForListOf(string $listItemClass, ?callable $classSchema): array
    {
        return [
            'type' => 'array',
            'items' => $classSchema !== null ? $classSchema($listItemClass) : self::forClass($listItemClass),
        ];
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
     * @return array<string, mixed>
     */
    public static function schemaForScalar(ReflectionParameter $parameter, ?ReflectionType $type): array
    {
        $schema = self::forType($type);

        foreach ($parameter->getAttributes(Constraint::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $schema = [...$schema, ...self::forConstraint($attribute->newInstance())];
        }

        return $schema;
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
     * @return array<string, mixed>
     */
    public static function forType(?ReflectionType $type): array
    {
        if (!$type instanceof ReflectionNamedType) {
            return [];
        }

        return match ($type->getName()) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            default => ['type' => 'object'],
        };
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
