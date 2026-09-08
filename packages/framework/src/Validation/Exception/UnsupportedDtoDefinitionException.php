<?php

declare(strict_types=1);

namespace Kinetis\Validation\Exception;

use Kinetis\Validation\Hydrator;
use RuntimeException;

/**
 * A DTO's constructor declares a parameter shape
 * Kinetis\Validation\Hydrator does not hydrate. Thrown while the
 * hydration plan is compiled — at build time for an AOT-compiled plan,
 * or on the first hydrate() call for a live one — so the definition
 * fails as a definition, never as a raw TypeError on a real request.
 *
 * See Hydrator's own docblock for the complete set of parameter shapes
 * a plan accepts.
 */
final class UnsupportedDtoDefinitionException extends RuntimeException
{
    public static function compositeType(string $class, string $parameter): self
    {
        return self::forParameter(
            $class,
            $parameter,
            'Kinetis hydrates neither union nor intersection types. Declare a single named type.',
        );
    }

    public static function recursiveDefinition(string $class, string $parameter, string $repeated): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "it references {$repeated}, which is already being compiled in this chain. A hydration plan "
            . 'embeds every nested class inline, so a recursive definition has no finite plan.',
        );
    }

    public static function unresolvableClass(string $class, string $parameter, string $type): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "\"{$type}\" does not resolve to a class or interface. Name the class explicitly rather than "
            . 'self, parent or static.',
        );
    }

    public static function listOfOnNonArrayParameter(string $class, string $parameter): self
    {
        return self::forParameter($class, $parameter, '#[ListOf] only applies to a parameter typed array.');
    }

    public static function listItemNotInstantiable(string $class, string $parameter, string $itemClass): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "#[ListOf({$itemClass}::class)] names a class that cannot be instantiated, so no element could "
            . 'ever be hydrated into it.',
        );
    }

    public static function unsupportedBuiltinType(string $class, string $parameter, string $type): self
    {
        return self::forParameter(
            $class,
            $parameter,
            "\"{$type}\" is not a builtin type a request value can be bound to. Kinetis accepts "
            . implode(', ', Hydrator::SUPPORTED_BUILTIN_TYPES) . ', or a class type.',
        );
    }

    public static function notInstantiable(string $class): self
    {
        return new self(
            "Cannot compile a hydration plan for {$class}: it cannot be instantiated. Hydration builds the "
            . 'class itself, so an interface, abstract class or enum can only be supplied as an existing '
            . 'instance, never hydrated as a DTO.',
        );
    }

    private static function forParameter(string $class, string $parameter, string $reason): self
    {
        return new self("Cannot hydrate {$class}::\${$parameter}: {$reason}");
    }
}
