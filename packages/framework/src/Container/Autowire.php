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
 * A class/interface-typed parameter's own default value or nullability
 * stands for one thing: the dependency is absent. The container is asked
 * whether it can resolve the id at all — a binding, or a concrete class
 * the scope would autowire — before a default is considered, and once
 * the answer is yes the dependency is resolved with every failure
 * propagating: a factory that throws, a nested dependency that cannot be
 * built, a cycle, a request-scoped id reached from the application
 * scope, a disposed scope. That is the same decision
 * Kinetis\Http\Dispatcher makes for a controller method parameter, so
 * moving a dependency between a constructor and a method signature never
 * changes what happens when it is broken.
 *
 * An absent dependency with neither a default nor a nullable type is
 * resolved anyway, so the container reports its own absence failure
 * rather than this class paraphrasing it.
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
     * One constructor parameter's value: the container's, when the
     * parameter is class/interface-typed and the container can resolve
     * that id; otherwise the parameter's own declared default, then null
     * when the type allows it. A builtin, union or intersection type is
     * never resolved from the container and reaches the same default/null
     * chain directly.
     */
    private static function resolveParameter(
        ReflectionParameter $parameter,
        ContainerInterface $container,
        string $class,
    ): mixed {
        $type = $parameter->getType();
        $id = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;

        if ($id !== null && ResolutionAvailability::canResolve($container, $id)) {
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
