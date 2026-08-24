<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Exception;

use RuntimeException;

/**
 * Thrown at {@see \Kinetis\Broadcasting\BroadcastChannelRegistry::register()}
 * time — a malformed `#[BroadcastChannel]` method fails fast at
 * registration, not the first time a client happens to try authorizing
 * against it, the same discipline `EventListenerRegistry` already applies
 * to a malformed `#[Listener]` method.
 */
final class InvalidChannelAuthorizerException extends RuntimeException
{
    public static function wrongParameterCount(string $class, string $method, string $pattern, int $expected, int $actual): self
    {
        return new self(sprintf(
            '%s::%s() is #[BroadcastChannel(\'%s\')] and must take an optional leading CurrentUserInterface '
                . 'parameter plus exactly %d string parameter(s) named after the pattern\'s placeholders, in order; found %d.',
            $class,
            $method,
            $pattern,
            $expected,
            $actual,
        ));
    }

    public static function parameterNameMismatch(string $class, string $method, string $pattern, string $expected, string $actual): self
    {
        return new self(sprintf(
            '%s::%s() is #[BroadcastChannel(\'%s\')] and its parameter named "%s" must be named "%s", matching the pattern\'s own placeholder order.',
            $class,
            $method,
            $pattern,
            $actual,
            $expected,
        ));
    }

    public static function parameterNotString(string $class, string $method, string $parameter): self
    {
        return new self("{$class}::{$method}()'s parameter \${$parameter} must be typed string.");
    }

    public static function duplicatePattern(string $pattern, string $firstClass, string $firstMethod): self
    {
        return new self("The channel pattern \"{$pattern}\" is already registered by {$firstClass}::{$firstMethod}().");
    }
}
