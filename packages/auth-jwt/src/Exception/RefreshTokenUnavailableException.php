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

    /**
     * The cache reported a failed write (CacheInterface::set() returned
     * false) before issue() ever returns a token — the caller must
     * discard whatever value they were about to receive rather than
     * hand an unredeemable token to a client. Names neither the subject
     * nor any claim.
     */
    public static function issueFailed(): self
    {
        return new self(
            'RefreshTokenStore::issue() failed: the cache reported a failed write, so the token that '
            . 'would have been returned is unredeemable. Do not hand it to a client — it was never stored.',
        );
    }

    /**
     * The cache reported a failed delete (CacheInterface::delete()
     * returned false) — the token this call targeted may still be
     * redeemable. Names neither the token nor its hash.
     */
    public static function revokeFailed(): self
    {
        return new self(
            'RefreshTokenStore::revoke() failed: the cache reported a failed delete, so the token has '
            . 'NOT been revoked and may still be redeemable. Treat this as a hard failure, not a warning.',
        );
    }

    /**
     * Same reasoning as revokeFailed(), for the per-user cutoff write —
     * names neither the user id nor any token.
     */
    public static function revokeAllForUserFailed(): self
    {
        return new self(
            'RefreshTokenStore::revokeAllForUser() failed: the cache reported a failed write, so none of '
            . "this user's outstanding refresh tokens have been revoked. Treat this as a hard failure, "
            . 'not a warning.',
        );
    }

    /**
     * issue()'s $ttlSeconds was zero or negative — unlike
     * RevocationStore::revoke(), there is no indefinite form here: a
     * refresh token always needs a real future expiry.
     */
    public static function nonPositiveIssueTtl(): self
    {
        return new self(
            'RefreshTokenStore::issue() requires a positive $ttlSeconds — a value of zero or less would '
            . 'store a token that is already expired, unredeemable the instant it is issued.',
        );
    }

    /**
     * revokeAllForUser()'s $ttlSeconds was zero or negative — this one
     * has no single token to bound the cutoff by, so the caller must
     * supply their own app's longest outstanding refresh-token lifetime.
     */
    public static function nonPositiveRevokeAllForUserTtl(): self
    {
        return new self(
            'RefreshTokenStore::revokeAllForUser() requires a positive $ttlSeconds — a value of zero or '
            . "less would let the cutoff disappear immediately, leaving every one of the user's outstanding "
            . 'refresh tokens valid.',
        );
    }
}
