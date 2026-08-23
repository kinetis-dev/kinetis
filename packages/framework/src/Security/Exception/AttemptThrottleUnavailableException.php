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

    public static function notAtomic(): self
    {
        return new self(
            'AttemptThrottle requires a cache implementing Kinetis\SimpleCache\AtomicCounterInterface: '
            . 'counting by reading the value and writing it back is not safe across processes, so failures '
            . 'arriving together — how a password list is actually worked through — register as one and '
            . 'the lockout never arms. Kinetis\SimpleCache\RedisSimpleCache and ClusteredRedisSimpleCache '
            . '(kinetis/cache-redis) both implement it.',
        );
    }
}
