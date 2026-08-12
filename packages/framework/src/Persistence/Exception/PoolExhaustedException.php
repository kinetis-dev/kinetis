<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use RuntimeException;

final class PoolExhaustedException extends RuntimeException
{
    public static function forMaxSize(int $maxSize): self
    {
        return new self("Connection pool exhausted: all {$maxSize} connections are in use.");
    }
}
