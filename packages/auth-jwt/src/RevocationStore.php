<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed denylist, supporting two independent revocation
 * mechanisms.
 *
 * Per-token — "log this session out" — keyed by a token's own `jti`
 * claim; JwtIssuer::issue() always includes one, so every token it
 * produces is revocable. Bounded, not ever-growing: revoke()'s
 * $ttlSeconds is the token's own remaining lifetime, not a fixed
 * duration — once the token would have expired naturally anyway, the
 * denylist entry has nothing left to revoke and can be dropped.
 * revokeToken() computes this automatically from the token's own `exp`
 * claim; call revoke() directly if you're revoking by `jti` alone
 * without a decoded token on hand.
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
 * token lifetime.
 *
 * Built against plain Psr\SimpleCache\CacheInterface, the same "don't
 * hard-couple to Redis specifically" reasoning
 * Kinetis\Http\Middleware\RateLimitMiddleware already applies.
 */
final readonly class RevocationStore
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function revoke(string $jti, int $ttlSeconds): void
    {
        $this->cache->set($this->key($jti), true, max(0, $ttlSeconds));
    }

    /**
     * Revokes $user's own token, deriving the denylist entry's TTL from
     * its `exp` claim. A token with no `exp` (JwtIssuer::issue() was
     * called with ttlSeconds: null) has nothing to bound the entry by and
     * is revoked for 0 seconds — effectively a no-op; a token you intend
     * to revoke should carry a real expiry in the first place.
     */
    public function revokeToken(JwtUser $user): void
    {
        $jti = $user->claim('jti');

        if (!is_string($jti) || $jti === '') {
            return;
        }

        $exp = $user->claim('exp');
        $ttlSeconds = is_numeric($exp) ? (int) $exp - time() : 0;

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
        $this->cache->set($this->userKey($userId), time(), max(0, $ttlSeconds));
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
        return 'jwt-revoked-user.' . hash('sha256', (string) $userId);
    }
}
