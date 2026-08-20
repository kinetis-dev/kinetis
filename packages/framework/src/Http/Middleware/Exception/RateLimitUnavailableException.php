<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Exception;

use RuntimeException;

final class RateLimitUnavailableException extends RuntimeException
{
    public static function nullCache(): self
    {
        return new self(
            'RateLimitMiddleware requires a real cache: NullSimpleCache never stores anything, so no '
            . 'limit would ever be enforced while the X-RateLimit-* headers kept looking healthy. '
            . 'Configure Redis (REDIS_URL/REDIS_HOST) or pass another PSR-16 CacheInterface implementation.',
        );
    }

    public static function notAtomic(): self
    {
        return new self(
            'RateLimitMiddleware requires a cache implementing Kinetis\SimpleCache\AtomicCounterInterface: '
            . 'counting by reading the value and writing it back is not safe across processes, and lets '
            . 'the limit be exceeded by every request that arrives concurrently rather than sequentially. '
            . 'Kinetis\SimpleCache\RedisSimpleCache and ClusteredRedisSimpleCache (kinetis/cache-redis) both '
            . 'implement it.',
        );
    }
}
