<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Exception;

use InvalidArgumentException;

/**
 * Rejects rate-limit configuration that cannot enforce a limit, at the
 * point it is constructed rather than on the request that trips over it.
 * Extends InvalidArgumentException so a caller can treat it as the
 * argument error it is.
 */
final class InvalidRateLimitConfigException extends InvalidArgumentException
{
    public static function nonPositiveMaxAttempts(int $maxAttempts): self
    {
        return new self(
            "RateLimitMiddleware needs a maxAttempts of at least 1, got {$maxAttempts}. "
            . 'Zero or fewer rejects every request, including the first, which is a blocked route '
            . 'rather than a rate limit.',
        );
    }

    public static function nonPositiveWindow(int $windowSeconds): self
    {
        return new self(
            "RateLimitMiddleware needs a windowSeconds of at least 1, got {$windowSeconds}. "
            . 'Zero divides by zero when the current window is calculated; a negative window stores '
            . 'the counter with a TTL already in the past, so nothing is ever counted and no limit is '
            . 'enforced while the X-RateLimit-* headers keep looking healthy.',
        );
    }

    public static function malformedTrustedProxy(string $proxy, string $reason): self
    {
        return new self(
            "RateLimitMiddleware trusted proxy \"{$proxy}\" is not a valid IP address or CIDR range: {$reason}. "
            . 'A range that cannot be parsed decides which requests may set X-Forwarded-For, so it fails '
            . 'here rather than at the first request that reaches it.',
        );
    }
}
