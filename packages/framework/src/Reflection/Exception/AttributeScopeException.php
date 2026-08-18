<?php

declare(strict_types=1);

namespace Kinetis\Reflection\Exception;

use RuntimeException;

/**
 * An attribute was written somewhere Kinetis will not read it from: on a
 * class that cannot be registered at all, or on a method the registered
 * class inherited rather than declared.
 */
final class AttributeScopeException extends RuntimeException
{
    public static function notRegistrable(string $class, string $kind): self
    {
        return new self("\"{$class}\" is {$kind} and cannot be registered. Attributes are read from the class they are written on, so register a concrete class instead.");
    }

    public static function inheritedMethod(string $class, string $method, string $declaredBy): self
    {
        return new self("\"{$class}::{$method}()\" carries an attribute but is declared by \"{$declaredBy}\", not by \"{$class}\". Attributes from a parent class do not apply: declare the method on \"{$class}\", or move it to a trait that \"{$class}\" uses.");
    }
}
