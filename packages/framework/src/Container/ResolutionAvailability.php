<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;

/**
 * The one availability rule constructor autowiring and controller method
 * injection both ask, so the two make the same absent-versus-broken
 * decision for the same id.
 *
 * A declared default or a nullable type stands in only for a dependency
 * that is unavailable. Every failure raised while resolving an available
 * id — a factory that throws, a missing nested dependency, a cycle, a
 * scope violation — belongs to the dependency itself and propagates.
 *
 * The two Kinetis scopes answer structurally, from the id alone. A
 * container outside Kinetis is asked `has()`, the single question PSR-11
 * defines, and nothing else: an id it reports absent gets the default,
 * and one it reports present is resolved through its own `get()`.
 *
 * @internal
 */
final class ResolutionAvailability
{
    public static function canResolve(ContainerInterface $container, string $id): bool
    {
        if ($container instanceof AppScope || $container instanceof RequestScope) {
            return $container->canResolve($id);
        }

        return $container->has($id);
    }

    /**
     * Whether an unregistered id is a class a Kinetis scope would build
     * for itself: it exists, and it is concrete with a callable
     * constructor. An interface, an abstract class, an enum and a class
     * with a non-public constructor are all unavailable without a
     * binding, which is what makes them the types an optional dependency
     * is written against.
     */
    public static function isAutowireable(string $id): bool
    {
        return class_exists($id) && new ReflectionClass($id)->isInstantiable();
    }
}
