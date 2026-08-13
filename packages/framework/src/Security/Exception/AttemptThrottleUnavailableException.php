<?php

declare(strict_types=1);

namespace Kinetis\Security\Exception;

use RuntimeException;

final class AttemptThrottleUnavailableException extends RuntimeException
{
    public static function nullCache(): self
    {
        return new self(
            'AttemptThrottle requires a real cache: NullSimpleCache never stores anything, so recordFailure() '
            . 'calls would appear to succeed while no lockout is ever actually enforced. Configure Redis '
            . '(REDIS_URL/REDIS_HOST) or pass another PSR-16 CacheInterface implementation.',
        );
    }
}
