<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

final class RevocationUnavailableException extends RuntimeException
{
    public static function nullCache(): self
    {
        return new self(
            'RevocationStore requires a real cache: NullSimpleCache never stores anything, so every '
            . 'revoked token would silently stay valid until it expires on its own. Configure Redis '
            . '(REDIS_URL/REDIS_HOST) or pass another PSR-16 CacheInterface implementation.',
        );
    }
}
