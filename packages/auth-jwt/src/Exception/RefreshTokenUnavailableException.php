<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

final class RefreshTokenUnavailableException extends RuntimeException
{
    public static function nullCache(): self
    {
        return new self(
            'RefreshTokenStore requires a real cache: NullSimpleCache never stores anything, so every '
            . 'issued refresh token would be unredeemable and revokeAllForUser() would have nothing to '
            . 'affect. Configure Redis (REDIS_URL/REDIS_HOST) or pass another PSR-16 CacheInterface '
            . 'implementation.',
        );
    }

    public static function notAtomic(): self
    {
        return new self(
            'RefreshTokenStore requires a cache implementing Kinetis\SimpleCache\AtomicConsumeInterface: '
            . 'redeeming a token by reading it and deleting it in two separate calls lets two concurrent '
            . 'redeems of the same token both succeed, defeating single use. Kinetis\SimpleCache\RedisSimpleCache '
            . 'and ClusteredRedisSimpleCache (kinetis/cache-redis) both implement it.',
        );
    }
}
