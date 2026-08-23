<?php

declare(strict_types=1);

namespace Kinetis\Reflection;

use Kinetis\Reflection\Exception\AttributeScopeException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Where Kinetis reads attributes from, enforced in one place because five
 * registries depend on the answer being the same: Router, CommandRegistry,
 * McpRegistry, EventListenerRegistry, and GlobalMiddlewareDiscovery.
 *
 * The rule is PHP's own: an attribute applies to the class it is written
 * on and nowhere else. PHP already declines to inherit class attributes —
 * ReflectionClass::getAttributes() on a subclass never returns a parent's.
 * Methods are the half PHP does not settle for you, because
 * ReflectionClass::getMethods() returns inherited methods flattened in
 * with a class's own, each still carrying whatever attributes were written
 * on it further up the hierarchy. Left alone, that registers a parent's
 * #[Get] against a child while the child's own attributes go unread.
 *
 * A trait method is not inherited for this purpose: PHP reports its
 * declaring class as the class that uses the trait, so a shared routed
 * method belongs in a trait rather than a base class.
 */
final class AttributeScope
{
    /**
     * Reflects a class that may carry registrable attributes.
     *
     * @param class-string $class
     * @return ReflectionClass<object>
     * @throws AttributeScopeException when the class cannot be registered
     */
    public static function reflect(string $class): ReflectionClass
    {
        $reflection = new ReflectionClass($class);

        $kind = self::unregistrableKind($reflection);

        if ($kind !== null) {
            throw AttributeScopeException::notRegistrable($class, $kind);
        }

        return $reflection;
    }

    /**
     * Whether a class can be registered at all — the silent counterpart to
     * reflect(), for discovery, which walks whole directories and must skip
     * an abstract base rather than fail the application over it.
     *
     * @phpstan-assert-if-true class-string $class
     */
    public static function isRegistrable(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        return self::unregistrableKind(new ReflectionClass($class)) === null;
    }

    /**
     * Whether $class declares $method itself. A trait method counts as the
     * using class's own; anything reached through a parent does not.
     */
    public static function declares(ReflectionMethod $method, string $class): bool
    {
        return $method->getDeclaringClass()->getName() === $class;
    }

    /**
     * @throws AttributeScopeException when $class inherited $method instead
     *     of declaring it
     */
    public static function assertDeclares(ReflectionMethod $method, string $class): void
    {
        if (self::declares($method, $class)) {
            return;
        }

        throw AttributeScopeException::inheritedMethod(
            $class,
            $method->getName(),
            $method->getDeclaringClass()->getName(),
        );
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return string|null a description of why it cannot be registered, or
     *     null when it can
     */
    private static function unregistrableKind(ReflectionClass $reflection): ?string
    {
        return match (true) {
            $reflection->isInterface() => 'an interface',
            $reflection->isTrait() => 'a trait',
            $reflection->isEnum() => 'an enum',
            $reflection->isAbstract() => 'abstract',
            default => null,
        };
    }
}
