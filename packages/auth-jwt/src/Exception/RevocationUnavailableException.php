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

    /**
     * The cache reported a failed write (CacheInterface::set() returned
     * false) — the token this call targeted has NOT been revoked.
     * Deliberately names neither the jti nor the subject: both are
     * caller-controlled security-sensitive identifiers, and this
     * exception is exactly the kind of thing a generic error handler
     * might log verbatim.
     */
    public static function revokeFailed(): self
    {
        return new self(
            'RevocationStore::revoke() failed: the cache reported a failed write, so the token has NOT '
            . 'been revoked. Treat this as a hard failure, not a warning — the caller must not proceed as '
            . 'though the revocation succeeded.',
        );
    }

    /**
     * Same reasoning as revokeFailed(), for the per-user cutoff write —
     * names neither the user id nor any token.
     */
    public static function revokeAllForUserFailed(): self
    {
        return new self(
            'RevocationStore::revokeAllForUser() failed: the cache reported a failed write, so none of '
            . "this user's existing tokens have been revoked. Treat this as a hard failure, not a warning.",
        );
    }

    /**
     * revoke()'s $ttlSeconds was zero or negative — a value with no
     * real meaning: it would either write an entry that's already
     * expired or be rejected by the backend outright. Pass null for
     * revoke()'s own indefinite form, or a genuine positive number of
     * seconds.
     */
    public static function nonPositiveRevokeTtl(): self
    {
        return new self(
            'RevocationStore::revoke() requires a positive $ttlSeconds, or null for its indefinite form — '
            . 'a value of zero or less has no meaning: it would either expire immediately or before it '
            . 'could ever apply. Pass null to revoke without an expiry, or a real positive number of seconds.',
        );
    }

    /**
     * revokeAllForUser()'s $ttlSeconds was zero or negative — unlike
     * revoke(), this one has no indefinite form: it has no single token
     * to bound the cutoff by, so the caller must supply their own app's
     * longest outstanding token lifetime.
     */
    public static function nonPositiveRevokeAllForUserTtl(): self
    {
        return new self(
            'RevocationStore::revokeAllForUser() requires a positive $ttlSeconds — a value of zero or '
            . "less would let the cutoff disappear immediately, leaving every one of the user's existing "
            . 'tokens valid.',
        );
    }

    /**
     * revokeToken() was handed a JwtUser whose token carries no usable
     * `jti` claim — there is nothing to add to the denylist, so the
     * revocation did NOT happen. A caller that swallows this and
     * reports success would be lying to whatever called it.
     */
    public static function missingJti(): self
    {
        return new self(
            'RevocationStore::revokeToken() requires the token to carry a non-empty "jti" claim — this '
            . 'one has none, so there is nothing to revoke. The logout/revocation attempt did NOT succeed; '
            . 'do not report it as though it did.',
        );
    }

    /**
     * revokeToken() found an `exp` claim present but not a plain JSON
     * integer — not silently treated as "no expiry," since that would
     * quietly turn a malformed claim into an indefinite revocation the
     * caller never asked for.
     */
    public static function invalidExp(): self
    {
        return new self(
            'RevocationStore::revokeToken() requires the token\'s "exp" claim, when present, to be a '
            . 'plain integer — this one is a different type. Reissue the token without a malformed exp, '
            . 'or omit it entirely for a token that\'s revocable indefinitely.',
        );
    }
}
