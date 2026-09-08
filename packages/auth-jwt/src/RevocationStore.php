<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\RevocationUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed denylist of individual access tokens — "log this
 * session out" — keyed by a token's own `jti` claim; JwtIssuer::issue()
 * always includes one, so every token it produces is revocable.
 * Bounded when the token itself is: revoke()'s $ttlSeconds is the
 * token's own remaining lifetime, not a fixed duration — once the token
 * would have expired naturally anyway, the denylist entry has nothing
 * left to revoke and can be dropped. A token issued with no expiry at
 * all (JwtIssuer::issue() called with ttlSeconds: null) has no such
 * natural point, so pass null instead — revoke() then writes the entry
 * with no expiry of its own, a genuine indefinite revocation rather
 * than a TTL standing in for "forever." revokeToken() derives this
 * automatically from the token's own `exp` claim (or lack of one); call
 * revoke() directly if you're revoking by `jti` alone without a decoded
 * token on hand. revoke() rejects a zero or negative TTL outright
 * rather than clamping it — a non-positive TTL was never a real
 * revocation, just one that looked like it succeeded.
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
    public function revoke(#[\SensitiveParameter] string $jti, ?int $ttlSeconds): void
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
    public function revokeToken(#[\SensitiveParameter] JwtUser $user): void
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

    public function isRevoked(#[\SensitiveParameter] string $jti): bool
    {
        return (bool) $this->cache->get($this->key($jti), false);
    }

    private function key(string $jti): string
    {
        return 'jwt-revoked.' . hash('sha256', $jti);
    }
}
