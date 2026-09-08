<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Exception;

use Psr\SimpleCache\CacheException as PsrCacheException;
use RuntimeException;
use Throwable;

final class CacheException extends RuntimeException implements PsrCacheException
{
    /**
     * Names the operation and the underlying failure, never the key: a
     * cache key can be a session identifier or a token hash, and this
     * message reaches logs and error pages.
     */
    public static function forOperation(string $operation, Throwable $previous): self
    {
        return new self("Redis \"{$operation}\" failed: {$previous->getMessage()}", 0, $previous);
    }
}
