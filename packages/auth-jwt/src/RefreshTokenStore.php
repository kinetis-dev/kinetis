<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\SimpleCache\AtomicConsumeInterface;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed, opaque refresh token — unlike an access token, this
 * needs storage, but only touched at a dedicated refresh endpoint, not
 * on every request, so it doesn't reopen the no-storage-lookup reasoning
 * JwtIssuer/JwtAuthMiddleware are built around.
 *
 * Single-use: redeem() consumes a token the moment it's looked up, valid
 * or not, in one atomic operation — reading it and deleting it in two
 * separate calls would let two concurrent redeems of the same token both
 * succeed, which is why the cache is required to implement
 * Kinetis\SimpleCache\AtomicConsumeInterface (see the constructor). A
 * refresh endpoint pairs redeem() with issuing both a fresh access token
 * (JwtIssuer) and a fresh refresh token (issue() again) — rotating both
 * together rather than reusing the old refresh token.
 *
 * A subject is the same canonical non-empty string an access token
 * carries in its `sub` claim: issue() accepts `string|int` and converts
 * it once, exactly as JwtIssuer::issue() does, so one application id
 * produces one identity across both token kinds. redeem() hands that
 * string back, ready to reissue with — JwtUser::id() on an
 * authenticated access token names the same subject its refresh token
 * was stored under. A stored record whose subject is anything else is
 * treated as unredeemable.
 *
 * Built against plain Psr\SimpleCache\CacheInterface, but requires the
 * cache to also implement AtomicConsumeInterface — NullSimpleCache is
 * rejected for issuing unredeemable tokens while appearing to succeed,
 * and any other cache lacking atomic consume is rejected for advertising
 * single use while not actually enforcing it under concurrent redeems.
 */
final readonly class RefreshTokenStore
{
    private CacheInterface&AtomicConsumeInterface $atomicCache;

    public function __construct(
        private CacheInterface $cache,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw RefreshTokenUnavailableException::nullCache();
        }

        if (!$cache instanceof AtomicConsumeInterface) {
            throw RefreshTokenUnavailableException::notAtomic();
        }

        $this->atomicCache = $cache;
    }

    /**
     * $subject is canonicalized to a non-empty string before it is
     * stored — see this class's own docblock.
     *
     * @param array<string, mixed> $claims
     */
    public function issue(
        string|int $subject,
        #[\SensitiveParameter] array $claims = [],
        int $ttlSeconds = 1_209_600,
    ): string {
        $storedSubject = (string) $subject;

        if ($storedSubject === '') {
            throw RefreshTokenUnavailableException::emptySubject();
        }

        if ($ttlSeconds <= 0) {
            throw RefreshTokenUnavailableException::nonPositiveIssueTtl();
        }

        $token = bin2hex(random_bytes(32));

        $stored = $this->cache->set($this->key($token), [
            'subject' => $storedSubject,
            'claims' => $claims,
        ], $ttlSeconds);

        if (!$stored) {
            throw RefreshTokenUnavailableException::issueFailed();
        }

        return $token;
    }

    /**
     * A record whose subject is not the canonical non-empty string
     * issue() stores — a tampered or foreign cache entry — is
     * unredeemable rather than reinterpreted, the same null this returns
     * for a token that never existed.
     *
     * @return array{subject: string, claims: array<string, mixed>}|null
     */
    public function redeem(#[\SensitiveParameter] string $token): ?array
    {
        // Reads and deletes the token in one atomic operation — see the
        // constructor's AtomicConsumeInterface requirement. A get() then
        // a separate delete() would let two concurrent redeems of the
        // same token both read it before either one deletes it.
        $value = $this->atomicCache->consume($this->key($token));

        if (
            !is_array($value)
            || !isset($value['subject'], $value['claims'])
            || !is_string($value['subject'])
            || $value['subject'] === ''
            || !is_array($value['claims'])
        ) {
            return null;
        }

        /** @var array{subject: string, claims: array<string, mixed>} $value */
        return ['subject' => $value['subject'], 'claims' => $value['claims']];
    }

    public function revoke(#[\SensitiveParameter] string $token): void
    {
        if (!$this->cache->delete($this->key($token))) {
            throw RefreshTokenUnavailableException::revokeFailed();
        }
    }

    private function key(string $token): string
    {
        return 'jwt-refresh.' . hash('sha256', $token);
    }
}
