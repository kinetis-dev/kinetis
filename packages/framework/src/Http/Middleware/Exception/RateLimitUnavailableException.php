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
}
