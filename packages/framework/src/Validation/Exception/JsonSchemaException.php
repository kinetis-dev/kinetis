<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use RuntimeException;

/**
 * A constructor/tool parameter's declared type has no truthful JSON
 * Schema representation this class produces — thrown at schema-
 * generation time (OpenAPI document generation, or MCP tool
 * registration) rather than silently mislabeling it `object`, which
 * would describe the wrong wire shape to a client or agent.
 *
 * Two declarations reach this. The builtin types `object` and
 * `callable`: every other builtin type name `ReflectionNamedType` can
 * produce on a parameter has an explicit, supported policy in
 * Kinetis\Validation\JsonSchema::forType()'s own docblock, and both of
 * these are also rejected at hydrate time, unconditionally, by
 * Kinetis\Validation\Hydrator::typeMismatchMessage() — a boundary that
 * runs on every request regardless of whether this exception's own call
 * site (schema generation) ever executes at all. And a class type that
 * cannot be instantiated, for which Hydrator accepts only an
 * already-constructed instance, so no wire value could satisfy an
 * expanded object schema either.
 */
final class JsonSchemaException extends RuntimeException
{
    public static function unsupportedBuiltinType(string $type): self
    {
        return new self(
            "Cannot generate a JSON Schema for the builtin type \"{$type}\" — only int, float, bool, "
            . 'string, array, iterable, mixed, null, true, and false are supported. Use one of those, '
            . 'or a class-typed parameter.',
        );
    }

    public static function unsupportedClassType(string $class): self
    {
        return new self(
            "Cannot generate a JSON Schema for \"{$class}\": it cannot be instantiated, so no request "
            . 'value can be hydrated into it. Use an instantiable class.',
        );
    }
}
