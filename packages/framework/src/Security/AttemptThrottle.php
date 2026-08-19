<?php

declare(strict_types=1);

namespace Kinetis\Security;

use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Kinetis\SimpleCache\Counter;
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
    private Counter $counter;

    public function __construct(
        private CacheInterface $cache,
        private int $maxAttempts = 5,
        private int $decaySeconds = 900,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw AttemptThrottleUnavailableException::nullCache();
        }

        $this->counter = new Counter($cache);
    }

    /**
     * Whether failures are counted atomically, which they are only when
     * the cache can — see Kinetis\SimpleCache\Counter. False means
     * attempts arriving together register as one and the lockout can be
     * outrun; check it at boot if that matters, which for a login it
     * does.
     */
    public function countsAtomically(): bool
    {
        return $this->counter->isAtomic();
    }

    public function tooManyAttempts(string $identifier): bool
    {
        return $this->count($identifier) >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier): void
    {
        // The expiry is refreshed on every failure, so the count decays
        // from the last one rather than the first. Whether failures
        // arriving together are each counted depends on the cache —
        // countsAtomically() reports it.
        $this->counter->increment($this->key($identifier), $this->decaySeconds);

        // Only for availableInSeconds(); no decision depends on it, and
        // concurrent writers store the same second either way.
        $this->cache->set($this->expiryKey($identifier), time() + $this->decaySeconds, $this->decaySeconds);
    }

    public function clear(string $identifier): void
    {
        $this->cache->deleteMultiple([$this->key($identifier), $this->expiryKey($identifier)]);
    }

    /**
     * Time remaining until $identifier's failure count resets to zero on
     * its own — 0 when there's no active lockout at all, whether because
     * nothing has failed yet or the decay window already elapsed.
     */
    public function availableInSeconds(string $identifier): int
    {
        $expiresAt = $this->cache->get($this->expiryKey($identifier));

        return is_int($expiresAt) ? max(0, $expiresAt - time()) : 0;
    }

    private function count(string $identifier): int
    {
        // Through the counter, not the cache: a natively-incremented
        // counter is not stored in the form get() reads back.
        return $this->counter->count($this->key($identifier));
    }

    private function key(string $identifier): string
    {
        return 'attempt-throttle.' . hash('sha256', $identifier);
    }

    private function expiryKey(string $identifier): string
    {
        return 'attempt-throttle-expiry.' . hash('sha256', $identifier);
    }
}
