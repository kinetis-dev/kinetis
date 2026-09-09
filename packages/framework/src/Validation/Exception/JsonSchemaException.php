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
 * client or agent. Kinetis\Mcp\McpDispatcher raises compositeType()
 * from its own binding-plan derivation for the same declaration, so a
 * method the published schema could not describe is never bound either.
 *
 * Five declarations reach this. A composite type — an intersection, or
 * any union other than the `Kinetis\Validation\Absent` presence union a
 * DTO field may declare — which has no single wire shape to publish and
 * which neither a controller nor an MCP tool method may declare at all.
 * A builtin type outside
 * Kinetis\Validation\Hydrator::SUPPORTED_BUILTIN_TYPES, which no
 * request value can carry. A class type that cannot be instantiated, for
 * which Hydrator accepts only an already-constructed instance, so no
 * wire value could satisfy an expanded object schema either. Two rules
 * on one parameter claiming the same JSON Schema keyword, where
 * publishing either one alone would understate what the request is
 * checked against. And a rule claiming a keyword the parameter's own PHP
 * type already states, which would publish a shape Hydrator does not
 * check the request against at all.
 */
final class JsonSchemaException extends RuntimeException
{
    /**
     * A parameter whose type is an intersection, or a union with no
     * truthful description: an MCP tool or resource method parameter,
     * which may declare neither — in a published schema or in a binding
     * plan — or a DTO field declaring an intersection. A DTO's own
     * `T|Absent` presence union never reaches here (it publishes T), and
     * any other union on a DTO field is already refused by
     * `Kinetis\Validation\Hydrator::absentUnion()` in the hydration
     * vocabulary, where the definition is compiled.
     */
    public static function compositeType(string $parameter): self
    {
        return new self(
            "Cannot generate a JSON Schema for parameter \"\${$parameter}\": a union or intersection type "
            . 'has no single wire shape to publish. Declare a single named type.',
        );
    }

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

    public static function declaredShapeKeyword(string $keyword, string $constraint): self
    {
        return new self(
            "Constraint \"{$constraint}\" contributes the JSON Schema keyword \"{$keyword}\", which the "
            . 'parameter\'s own PHP type already states. A rule refines the declared shape and cannot '
            . 'replace it: the declared type is what the request is checked against. Change the '
            . 'parameter\'s type, or drop that keyword from the rule.',
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
