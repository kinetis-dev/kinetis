<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Exception;

use InvalidArgumentException as SplInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

final class InvalidArgumentException extends SplInvalidArgumentException implements PsrInvalidArgumentException
{
    public static function forKey(string $key, string $reason): self
    {
        return new self("Invalid cache key \"{$key}\": {$reason}");
    }
}
