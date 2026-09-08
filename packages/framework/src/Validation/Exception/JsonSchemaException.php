<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use Kinetis\Validation\Hydrator;
use RuntimeException;

/**
 * A constructor/tool parameter's declared type has no truthful JSON
 * Schema representation this class produces — thrown at schema-
 * generation time (OpenAPI document generation, or MCP tool
 * registration) rather than silently mislabeling it `object`, which
 * would describe the wrong wire shape to a client or agent.
 *
 * Two declarations reach this. A builtin type outside
 * Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES, which no
 * request value can carry. And a class type that cannot be
 * instantiated, for which Hydrator accepts only an already-constructed
 * instance, so no wire value could satisfy an expanded object schema
 * either.
 */
final class JsonSchemaException extends RuntimeException
{
    public static function unsupportedBuiltinType(string $type): self
    {
        return new self(
            "Cannot generate a JSON Schema for the builtin type \"{$type}\" — only "
            . implode(', ', Hydrator::SUPPORTED_BUILTIN_TYPES) . ' are supported. Use one of those, '
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
