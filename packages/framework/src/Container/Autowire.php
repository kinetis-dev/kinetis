<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Kinetis\Container\Exception\ContainerException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Builds a class instance by reflecting on its constructor and resolving
 * each typed parameter through the given container. Used by both AppScope
 * and RequestScope so autowiring behaves identically regardless of which
 * scope triggered it.
 *
 * A class/interface-typed parameter's own default value (or nullability)
 * is honored the same way a builtin-typed one's already was — an
 * independent security/correctness review found this wasn't the case
 * before: `__construct(?OptionalThing $thing = null)` for an unregistered
 * `OptionalThing` threw a `NotFoundException` instead of falling back to
 * `null`, because the class-typed branch resolved through the container
 * unconditionally and never even looked at `isDefaultValueAvailable()`/
 * `allowsNull()` — the standard PHP idiom for "inject this if available,
 * otherwise use a sane default" was unusable for anything but a builtin
 * type. Resolution is still attempted first (an unregistered-but-existing
 * class must still autowire normally, per AppScope's own class_exists()
 * fallback) — only a failure now falls through to the parameter's own
 * default/null, rather than propagating unconditionally.
 */
final class Autowire
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public static function instantiate(string $class, ContainerInterface $container): object
    {
        try {
            $reflection = new ReflectionClass($class);
        // Unreachable through AppScope/RequestScope, which only ever pass an
        // already-verified class-string. Kept because this method is public
        // and PHP does not enforce the class-string<T> phpdoc at runtime for
        // external callers.
        // @phpstan-ignore-next-line catch.neverThrown
        } catch (ReflectionException $e) {
            throw new ContainerException("Cannot autowire \"{$class}\": {$e->getMessage()}", previous: $e);
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(
                "Cannot autowire \"{$class}\": it is not instantiable (interface, abstract class, or enum)."
            );
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = self::resolveParameter($parameter, $container, $class);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * One constructor parameter's value: resolved through the container
     * when class/interface-typed, falling back to a default/null the same
     * way a builtin-typed parameter's own fallback already works (see the
     * class docblock) — extracted out of instantiate()'s loop body once
     * that method's own branching (a nested try/catch plus two near-
     * identical default/null/throw chains) made a pure, behavior-
     * preserving move worth doing on its own, not a redesign.
     */
    private static function resolveParameter(
        ReflectionParameter $parameter,
        ContainerInterface $container,
        string $class,
    ): mixed {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $container->get($type->getName());
            } catch (ContainerException $e) {
                return self::resolveDefaultOrThrow($parameter, $class, $e);
            }
        }

        return self::resolveDefaultOrThrow($parameter, $class);
    }

    /**
     * The one "inject this if available, otherwise use a sane default"
     * check both branches of resolveParameter() need: a parameter's own
     * default value first, then null if the type allows it, then throw —
     * $originalException (when given) is rethrown as-is so a class-typed
     * parameter's real container-resolution failure is preserved verbatim
     * rather than replaced by the generic message below, which only ever
     * applies to a non-class-typed parameter.
     */
    private static function resolveDefaultOrThrow(
        ReflectionParameter $parameter,
        string $class,
        ?ContainerException $originalException = null,
    ): mixed {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        if ($originalException !== null) {
            throw $originalException;
        }

        throw new ContainerException(
            "Cannot autowire \"{$class}\": parameter \"\${$parameter->getName()}\" has no class type, "
            . 'union/intersection types are not supported, and no default value is available.'
        );
    }
}
