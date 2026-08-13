<?php

declare(strict_types=1);

namespace Kinetis\Security;

use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Psr\SimpleCache\CacheInterface;

/**
 * A cache-backed, identifier-keyed failure counter — not login-specific,
 * usable for any failure-prone action (a password check, a 2FA code, an
 * invite redemption). Not middleware: whether an attempt failed is only
 * known once application code actually runs it, so recordFailure()/
 * clear() are called directly from that code, not from a PSR-15
 * pipeline the way Kinetis\Http\Middleware\RateLimitMiddleware is.
 *
 * Each recordFailure() call resets the entry's TTL to $decaySeconds
 * from that failure, so a burst of failures followed by $decaySeconds
 * of inactivity clears the lockout on its own; clear() removes it
 * immediately on a successful attempt.
 *
 * Built against plain Psr\SimpleCache\CacheInterface, the same "don't
 * hard-couple to Redis specifically" reasoning
 * Kinetis\Http\Middleware\RateLimitMiddleware already applies.
 * NullSimpleCache is rejected at construction — a throttle that never
 * stores anything enforces no lockout at all while recordFailure()
 * calls appear to succeed.
 *
 * Identifiers are sha256-hashed before use (PSR-16 forbids `{}()/\@:`
 * in a key, and an email address contains `@`).
 */
final readonly class AttemptThrottle
{
    public function __construct(
        private CacheInterface $cache,
        private int $maxAttempts = 5,
        private int $decaySeconds = 900,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw AttemptThrottleUnavailableException::nullCache();
        }
    }

    public function tooManyAttempts(string $identifier): bool
    {
        $entry = $this->entry($identifier);

        return $entry !== null && $entry['count'] >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier): void
    {
        $entry = $this->entry($identifier);
        $count = ($entry['count'] ?? 0) + 1;

        $this->cache->set($this->key($identifier), [
            'count' => $count,
            'expiresAt' => time() + $this->decaySeconds,
        ], $this->decaySeconds);
    }

    public function clear(string $identifier): void
    {
        $this->cache->delete($this->key($identifier));
    }

    /**
     * Time remaining until $identifier's failure count resets to zero on
     * its own — 0 when there's no active lockout at all, whether because
     * nothing has failed yet or the decay window already elapsed.
     */
    public function availableInSeconds(string $identifier): int
    {
        $entry = $this->entry($identifier);

        return $entry === null ? 0 : max(0, $entry['expiresAt'] - time());
    }

    /**
     * @return array{count: int, expiresAt: int}|null
     */
    private function entry(string $identifier): ?array
    {
        $value = $this->cache->get($this->key($identifier));

        return is_array($value) && is_int($value['count'] ?? null) && is_int($value['expiresAt'] ?? null)
            ? $value
            : null;
    }

    private function key(string $identifier): string
    {
        return 'attempt-throttle.' . hash('sha256', $identifier);
    }
}
