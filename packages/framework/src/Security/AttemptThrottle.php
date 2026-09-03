<?php

declare(strict_types=1);

namespace Kinetis\Security;

use Kinetis\Security\Exception\AttemptThrottleUnavailableException;
use Kinetis\Security\Exception\InvalidAttemptThrottleConfigException;
use Kinetis\SimpleCache\AtomicCounterInterface;
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
 * Built against plain Psr\SimpleCache\CacheInterface, but requires the
 * cache to also implement Kinetis\SimpleCache\AtomicCounterInterface —
 * the same "fail at construction, not behind a flag the application has
 * to remember to check" discipline
 * Kinetis\Http\Middleware\RateLimitMiddleware applies. Without it,
 * failures arriving together — how a password list is actually worked
 * through — each read the same count before any of them write, so they
 * register as one and the lockout never arms; measured against a real
 * Redis, 40 parallel wrong passwords recorded a single failure.
 * NullSimpleCache is checked first, for its own clearer message: a
 * throttle that never stores anything enforces no lockout at all while
 * recordFailure() calls appear to succeed.
 *
 * Identifiers are sha256-hashed before use (PSR-16 forbids `{}()/\@:`
 * in a key, and an email address contains `@`).
 *
 * **Every counter is scoped to the policy that owns it, not just the
 * identifier being counted.** The default key folds in $maxAttempts and
 * $decaySeconds alongside the (hashed) identifier, so two policies with
 * different configuration never collide — but two policies with the
 * *identical* configuration guarding different things (a login password
 * check and a 2FA code check, both `AttemptThrottle($cache, 5, 900)`, for
 * the same email) still would, since neither maxAttempts/decaySeconds
 * nor the raw identifier says which purpose a failure belongs to.
 * $namespace is the explicit escape hatch: pass a distinct string per
 * purpose (`new AttemptThrottle($cache, namespace: 'login')`, `new
 * AttemptThrottle($cache, namespace: '2fa')`) and each gets its own
 * record/count/expiry/clear independent of the other, even for the same
 * identifier and the same limits. See {doc}`auth`'s "Preventing
 * brute-force login attempts" section for the deployment consequence of
 * changing this on an already-running system.
 */
final readonly class AttemptThrottle
{
    private Counter $counter;

    public function __construct(
        private CacheInterface $cache,
        private int $maxAttempts = 5,
        private int $decaySeconds = 900,
        private ?string $namespace = null,
    ) {
        if ($cache instanceof NullSimpleCache) {
            throw AttemptThrottleUnavailableException::nullCache();
        }

        if (!$cache instanceof AtomicCounterInterface) {
            throw AttemptThrottleUnavailableException::notAtomic();
        }

        if ($maxAttempts < 1) {
            throw InvalidAttemptThrottleConfigException::nonPositiveMaxAttempts($maxAttempts);
        }

        if ($decaySeconds < 1) {
            throw InvalidAttemptThrottleConfigException::nonPositiveDecay($decaySeconds);
        }

        $this->counter = new Counter($cache);
    }

    public function tooManyAttempts(string $identifier): bool
    {
        return $this->count($identifier) >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier): void
    {
        // The expiry is refreshed on every failure, so the count decays
        // from the last one rather than the first.
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

    /**
     * A stable, unambiguous identity for this exact policy checking this
     * exact identifier — every field that changes what actually gets
     * counted, not just the raw identifier: `$maxAttempts`,
     * `$decaySeconds`, `$namespace`, and `$identifier` itself.
     *
     * Each field is hashed on its own before being joined, then the
     * joined, fixed-width result is hashed once more — not the fields
     * concatenated directly. A plain delimited join of caller-controlled
     * values has no safe delimiter: namespace `a`, maxAttempts 5,
     * decaySeconds 900, identifier `7:x` joins to the exact same string
     * as namespace `a:5`, maxAttempts 900, decaySeconds 7, identifier
     * `x` — two genuinely different policies, one collided bucket.
     * Hashing every field first fixes each one to the same width
     * regardless of its own content, so no field's content can ever be
     * mistaken for a delimiter or shift into a neighboring field.
     */
    private function policyIdentity(string $identifier): string
    {
        $fields = implode('|', [
            hash('sha256', (string) $this->maxAttempts),
            hash('sha256', (string) $this->decaySeconds),
            hash('sha256', $this->namespace ?? ''),
            hash('sha256', $identifier),
        ]);

        return hash('sha256', $fields);
    }

    private function key(string $identifier): string
    {
        return 'attempt-throttle.' . $this->policyIdentity($identifier);
    }

    private function expiryKey(string $identifier): string
    {
        return 'attempt-throttle-expiry.' . $this->policyIdentity($identifier);
    }
}
