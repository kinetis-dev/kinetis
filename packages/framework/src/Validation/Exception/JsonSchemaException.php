<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use Kinetis\Validation\Hydrator;
use RuntimeException;

/**
 * A constructor/tool parameter has no truthful JSON Schema
 * representation this class could produce — thrown at schema-generation
 * time (OpenAPI document generation, or MCP tool registration) rather
 * than publishing a document that describes the wrong wire shape to a
 * client or agent.
 *
 * Three declarations reach this. A builtin type outside
 * Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES, which no
 * request value can carry. A class type that cannot be instantiated, for
 * which Hydrator accepts only an already-constructed instance, so no
 * wire value could satisfy an expanded object schema either. And two
 * rules on one parameter claiming the same JSON Schema keyword, where
 * publishing either one alone would understate what the request is
 * checked against.
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

    public static function duplicateKeyword(string $keyword, string $first, string $second): self
    {
        return new self(
            "Constraints \"{$first}\" and \"{$second}\" both contribute the JSON Schema keyword "
            . "\"{$keyword}\", so a schema could state only one of them. Every rule on one parameter "
            . 'must contribute distinct keywords: drop one, or express the combined bound as a single rule.',
        );
    }
}
