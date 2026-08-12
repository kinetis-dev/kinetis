<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Exception;

use RuntimeException;

final class SimpleCacheUnavailableException extends RuntimeException
{
    public static function missingDriverPackage(string $package): self
    {
        return new self("Redis is configured (REDIS_HOST/REDIS_URL/REDIS_CLUSTER) but no Redis driver is installed: install \"{$package}\" to enable it.");
    }
}
