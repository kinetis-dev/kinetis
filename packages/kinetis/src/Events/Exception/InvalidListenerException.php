<?php

declare(strict_types=1);

namespace Kinetis\Events\Exception;

use RuntimeException;

/**
 * A #[Listener] method doesn't have exactly one class-typed parameter —
 * the event class can't be inferred from anything else, since the
 * attribute itself carries no arguments.
 */
final class InvalidListenerException extends RuntimeException
{
    public static function forMethod(string $class, string $method): self
    {
        return new self("\"{$class}::{$method}()\" is not a valid #[Listener]: it must declare exactly one class-typed parameter, the event it listens for.");
    }
}
