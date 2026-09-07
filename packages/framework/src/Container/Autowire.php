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
 * A class- or interface-typed parameter's declared default, or its
 * nullable type, stands in only for a dependency the container would not
 * attempt at all. See `docs/container.md` for that rule.
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
     * Whether the container could supply `$id`: something registered it,
     * or a class of that name is declared. Registration is read through
     * isRegistered() on a request scope, whose has() also answers true
     * for any autowirable class. An enum is declared and is never a
     * container's to build, so an unregistered one is absent alongside
     * an unregistered interface — the types an optional dependency is
     * written against. Anything else is resolved, and every failure that
     * resolution meets propagates.
     *
     * @internal Shared with Kinetis\Http\Dispatcher, so a dependency
     *           behaves the same in a constructor and in a controller
     *           method signature.
     */
    public static function isAvailable(ContainerInterface $container, string $id): bool
    {
        $registered = $container instanceof RequestScope
            ? $container->isRegistered($id)
            : $container->has($id);

        return $registered || (class_exists($id) && !enum_exists($id));
    }

    /**
     * One constructor parameter's value: the container's when the
     * parameter is class- or interface-typed and that dependency is
     * available, then the parameter's own declared default, then null if
     * the type allows it. A builtin, union or intersection type is never
     * resolved from the container.
     */
    private static function resolveParameter(
        ReflectionParameter $parameter,
        ContainerInterface $container,
        string $class,
    ): mixed {
        $type = $parameter->getType();
        $id = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if ($id !== null && self::isAvailable($container, $id)) {
            return $container->get($id);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        if ($id !== null) {
            // Nothing to stand in for the dependency, so the container
            // states the absence in its own terms, naming the id.
            return $container->get($id);
        }

        throw new ContainerException(
            "Cannot autowire \"{$class}\": parameter \"\${$parameter->getName()}\" has no class type, "
            . 'union/intersection types are not supported, and no default value is available.'
        );
    }
}
