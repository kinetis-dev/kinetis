<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\RevocationUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed denylist, supporting two independent revocation
 * mechanisms.
 *
 * Per-token — "log this session out" — keyed by a token's own `jti`
 * claim; JwtIssuer::issue() always includes one, so every token it
 * produces is revocable. Bounded when the token itself is: revoke()'s
 * $ttlSeconds is the token's own remaining lifetime, not a fixed
 * duration — once the token would have expired naturally anyway, the
 * denylist entry has nothing left to revoke and can be dropped. A
 * token issued with no expiry at all (JwtIssuer::issue() called with
 * ttlSeconds: null) has no such natural point, so pass null instead —
 * revoke() then writes the entry with no expiry of its own, a genuine
 * indefinite revocation rather than a TTL standing in for "forever."
 * revokeToken() derives this automatically from the token's own `exp`
 * claim (or lack of one); call revoke() directly if you're revoking by
 * `jti` alone without a decoded token on hand. Every TTL-accepting
 * method here rejects zero or a negative value outright rather than
 * clamping it — a non-positive TTL was never a real revocation, just
 * one that looked like it succeeded.
 *
 * Per-user — "log out everywhere" — keyed by the user's own id, storing
 * a cutoff timestamp rather than any specific token. A token issued at
 * or before that cutoff (per its own `iat` claim) is rejected; anything
 * issued strictly after is unaffected. The cutoff itself is inclusive
 * deliberately, not off-by-one: this is a security action, and failing
 * closed on the rare same-second tie (a token that happened to be
 * minted in the exact wall-clock second as the revocation call) is the
 * correct tradeoff against failing open on it — the cost of the false
 * positive is "log in again," the cost of the alternative is a
 * revoked session that isn't actually revoked. A fresh login one full
 * second after the call, the overwhelmingly common case, is unaffected
 * either way. Unlike revokeToken(), $ttlSeconds on revokeAllForUser()
 * has no single token to derive from — pass your own app's longest
 * token lifetime. A subject id's type is part of the identity being
 * revoked, so `42` and `'42'` are two separate users here — see
 * SubjectKey for the framing that holds them apart.
 *
 * Built against plain Psr\SimpleCache\CacheInterface, the same "don't
 * hard-couple to Redis specifically" reasoning
 * Kinetis\Http\Middleware\RateLimitMiddleware already applies — with one
 * exception, enforced at construction: NullSimpleCache is rejected
 * outright. A denylist that never stores anything would let every
 * revoked token stay valid until natural expiry while revoke() calls
 * appear to succeed — a security control that silently doesn't run.
 */
final readonly class RevocationStore
{
    public function __construct(
        private CacheInterface $cache,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw RevocationUnavailableException::nullCache();
        }
    }

    /**
     * $ttlSeconds is the entry's own remaining lifetime in seconds, or
     * null to revoke with no expiry at all — routed straight through to
     * the underlying cache's own null-TTL semantics (a genuinely
     * permanent write against Kinetis\SimpleCache\RedisSimpleCache, for
     * instance; see its own set()). Zero or negative is rejected
     * outright rather than clamped — see this class's own docblock.
     */
    public function revoke(string $jti, ?int $ttlSeconds): void
    {
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            throw RevocationUnavailableException::nonPositiveRevokeTtl();
        }

        if (!$this->cache->set($this->key($jti), true, $ttlSeconds)) {
            throw RevocationUnavailableException::revokeFailed();
        }
    }

    /**
     * Revokes $user's own token, deriving the denylist entry's TTL from
     * its `exp` claim. A token with no `exp` at all (JwtIssuer::issue()
     * was called with ttlSeconds: null) has no natural point at which
     * the denylist entry could ever be safely dropped, so it's revoked
     * indefinitely — see revoke()'s own $ttlSeconds: null. An `exp`
     * that's already in the past needs no write at all: the token is
     * already unusable on its own, and revoke() itself would reject the
     * resulting non-positive TTL regardless.
     *
     * Throws when the token carries no usable `jti`, or an `exp` that's
     * present but not a plain integer — never silently does nothing. A
     * caller reporting a logout as successful while the token in hand
     * remains fully valid is exactly the failure mode this store exists
     * to prevent.
     */
    public function revokeToken(JwtUser $user): void
    {
        $jti = $user->claim('jti');

        if (!is_string($jti) || $jti === '') {
            throw RevocationUnavailableException::missingJti();
        }

        $exp = $user->claim('exp');

        if ($exp === null) {
            $this->revoke($jti, null);

            return;
        }

        if (!is_int($exp)) {
            throw RevocationUnavailableException::invalidExp();
        }

        $ttlSeconds = $exp - time();

        if ($ttlSeconds <= 0) {
            // Already expired — nothing left to protect against, and
            // revoke() would reject this exact TTL anyway.
            return;
        }

        $this->revoke($jti, $ttlSeconds);
    }

    public function isRevoked(string $jti): bool
    {
        return (bool) $this->cache->get($this->key($jti), false);
    }

    /**
     * Invalidates every token already issued to $userId — any token
     * whose `iat` predates this call. $ttlSeconds must cover the longest
     * lifetime any of your app's currently-outstanding tokens could
     * still have; once it elapses, this cutoff itself is forgotten.
     */
    public function revokeAllForUser(string|int $userId, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            throw RevocationUnavailableException::nonPositiveRevokeAllForUserTtl();
        }

        if (!$this->cache->set($this->userKey($userId), time(), $ttlSeconds)) {
            throw RevocationUnavailableException::revokeAllForUserFailed();
        }
    }

    public function isRevokedForUser(string|int $userId, int $issuedAt): bool
    {
        $cutoff = $this->cache->get($this->userKey($userId));

        return is_int($cutoff) && $issuedAt <= $cutoff;
    }

    private function key(string $jti): string
    {
        return 'jwt-revoked.' . hash('sha256', $jti);
    }

    private function userKey(string|int $userId): string
    {
        return 'jwt-revoked-user.' . SubjectKey::digest($userId);
    }
}
