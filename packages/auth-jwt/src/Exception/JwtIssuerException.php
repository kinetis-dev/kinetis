<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Exception;

use RuntimeException;

/**
 * The arguments JwtIssuer::issue() refuses. Configuration failures —
 * keys, algorithms, kids, issuer/audience — are
 * JwtConfigurationException instead.
 *
 * Neither the subject nor the TTL value is named: both are
 * caller-controlled, and the rule is what makes the message actionable.
 */
final class JwtIssuerException extends RuntimeException
{
    public static function emptySubject(): self
    {
        return new self(
            'JwtIssuer::issue() requires a non-empty $subject: the "sub" claim is the identity every '
            . 'revocation and refresh-token record is keyed by, and an empty one names no user at all.',
        );
    }

    public static function nonPositiveTtl(): self
    {
        return new self(
            'JwtIssuer::issue() requires a positive $ttlSeconds, or null for a token with no expiry at '
            . 'all — a value of zero or less would produce a token that is already expired, or expires '
            . 'before it could ever be used.',
        );
    }

    public static function ttlOverflow(): self
    {
        return new self(
            'JwtIssuer::issue()\'s $ttlSeconds is too large: adding it to the current time would overflow '
            . "this platform's integer range, producing a corrupted \"exp\" claim rather than a real future "
            . 'timestamp. Use a smaller TTL.',
        );
    }
}
