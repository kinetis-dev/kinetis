<?php

declare(strict_types=1);

namespace Kinetis\Console\Exception;

use RuntimeException;

final class InvalidCommandException extends RuntimeException
{
    public static function forMethod(string $class, string $method): self
    {
        return new self("\"{$class}::{$method}()\" is not a valid #[Command]: it must declare zero parameters, or exactly one parameter typed Kinetis\\Console\\CommandArguments.");
    }

    public static function duplicateName(string $name, string $class, string $method): self
    {
        return new self("Command \"{$name}\" is already registered; \"{$class}::{$method}()\" cannot reuse the same name.");
    }
}
