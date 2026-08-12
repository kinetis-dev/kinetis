<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Exception;

use Psr\SimpleCache\CacheException as PsrCacheException;
use RuntimeException;
use Throwable;

final class CacheException extends RuntimeException implements PsrCacheException
{
    public static function forOperation(string $operation, string $key, Throwable $previous): self
    {
        return new self("Redis \"{$operation}\" failed for key \"{$key}\": {$previous->getMessage()}", 0, $previous);
    }
}
