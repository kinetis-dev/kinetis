<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt;

use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed, opaque refresh token — unlike an access token, this
 * needs storage, but only touched at a dedicated refresh endpoint, not
 * on every request, so it doesn't reopen the no-storage-lookup reasoning
 * JwtIssuer/JwtAuthMiddleware are built around.
 *
 * Single-use: redeem() deletes a token the moment it's looked up,
 * valid or not. A refresh endpoint
 * pairs redeem() with issuing both a fresh access token (JwtIssuer) and
 * a fresh refresh token (issue() again) — rotating both together rather
 * than reusing the old refresh token.
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
 * Built against plain Psr\SimpleCache\CacheInterface. NullSimpleCache is
 * rejected at construction — a store that never stores anything issues
 * unredeemable tokens while appearing to succeed.
 */
final readonly class RefreshTokenStore
{
    public function __construct(
        private CacheInterface $cache,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw RefreshTokenUnavailableException::nullCache();
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function issue(string|int $subject, array $claims = [], int $ttlSeconds = 1_209_600): string
    {
        $token = bin2hex(random_bytes(32));

        $this->cache->set($this->key($token), [
            'subject' => $subject,
            'claims' => $claims,
            'issuedAt' => time(),
        ], max(0, $ttlSeconds));

        return $token;
    }

    /**
     * @return array{subject: string|int, claims: array<string, mixed>}|null
     */
    public function redeem(string $token): ?array
    {
        $entry = $this->entry($token);
        $this->cache->delete($this->key($token));

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
        $this->cache->delete($this->key($token));
    }

    public function revokeAllForUser(string|int $userId, int $ttlSeconds): void
    {
        $this->cache->set($this->userKey($userId), time(), max(0, $ttlSeconds));
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
        $value = $this->cache->get($this->key($token));

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
