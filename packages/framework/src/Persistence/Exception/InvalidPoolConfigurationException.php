<?php

declare(strict_types=1);

namespace Kinetis\Persistence\Exception;

use InvalidArgumentException;

final class InvalidPoolConfigurationException extends InvalidArgumentException
{
    public static function maxSizeMustBeAtLeastOne(int $maxSize): self
    {
        return new self("Pool maxSize must be at least 1, {$maxSize} given — a pool that can never hold a single member can never satisfy an acquire() call, and would otherwise fail only later, confusingly, as PoolExhaustedException.");
    }
}
