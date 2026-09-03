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
 * revokeAllForUser() uses the same cutoff-timestamp mechanism
 * RevocationStore already uses for access tokens, not an enumerated
 * list of a user's outstanding tokens: every token already carries its
 * own subject and issuedAt, so redeem() just checks a token's issuedAt
 * against the latest cutoff for its subject, inclusive, the same
 * fail-closed-on-a-tie reasoning RevocationStore's own per-user
 * revocation already documents. $ttlSeconds must cover the longest
 * lifetime any of this user's currently-outstanding refresh tokens
 * could still have; once it elapses, the cutoff itself is forgotten.
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
     * @param array<string, mixed> $claims
     */
    public function issue(string|int $subject, array $claims = [], int $ttlSeconds = 1_209_600): string
    {
        if ($ttlSeconds <= 0) {
            throw RefreshTokenUnavailableException::nonPositiveIssueTtl();
        }

        $token = bin2hex(random_bytes(32));

        $stored = $this->cache->set($this->key($token), [
            'subject' => $subject,
            'claims' => $claims,
            'issuedAt' => time(),
        ], $ttlSeconds);

        if (!$stored) {
            throw RefreshTokenUnavailableException::issueFailed();
        }

        return $token;
    }

    /**
     * @return array{subject: string|int, claims: array<string, mixed>}|null
     */
    public function redeem(string $token): ?array
    {
        $entry = $this->entry($token);

        if ($entry === null) {
            return null;
        }

        if ($this->isRevokedForSubject($entry['subject'], $entry['issuedAt'])) {
            return null;
        }

        return ['subject' => $entry['subject'], 'claims' => $entry['claims']];
    }

    public function revoke(string $token): void
    {
        if (!$this->cache->delete($this->key($token))) {
            throw RefreshTokenUnavailableException::revokeFailed();
        }
    }

    public function revokeAllForUser(string|int $userId, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            throw RefreshTokenUnavailableException::nonPositiveRevokeAllForUserTtl();
        }

        if (!$this->cache->set($this->userKey($userId), time(), $ttlSeconds)) {
            throw RefreshTokenUnavailableException::revokeAllForUserFailed();
        }
    }

    private function isRevokedForSubject(string|int $subject, int $issuedAt): bool
    {
        $cutoff = $this->cache->get($this->userKey($subject));

        return is_int($cutoff) && $issuedAt <= $cutoff;
    }

    /**
     * @return array{subject: string|int, claims: array<string, mixed>, issuedAt: int}|null
     */
    private function entry(string $token): ?array
    {
        // Reads and deletes the token in one atomic operation — see the
        // constructor's AtomicConsumeInterface requirement. A get() then
        // a separate delete() would let two concurrent redeems of the
        // same token both read it before either one deletes it.
        $value = $this->atomicCache->consume($this->key($token));

        if (
            !is_array($value)
            || !isset($value['subject'], $value['claims'], $value['issuedAt'])
            || !(is_string($value['subject']) || is_int($value['subject']))
            || !is_array($value['claims'])
            || !is_int($value['issuedAt'])
        ) {
            return null;
        }

        /** @var array{subject: string|int, claims: array<string, mixed>, issuedAt: int} $value */
        return $value;
    }

    private function key(string $token): string
    {
        return 'jwt-refresh.' . hash('sha256', $token);
    }

    private function userKey(string|int $userId): string
    {
        return 'jwt-refresh-user.' . hash('sha256', (string) $userId);
    }
}
