<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Psr\SimpleCache\CacheInterface;

/**
 * Counts against a PSR-16 cache, atomically where the cache can.
 *
 * A cache implementing AtomicCounterInterface — RedisSimpleCache and
 * ClusteredRedisSimpleCache do — counts through it, and concurrent
 * callers each receive their own value. Any other cache falls back to
 * reading the value and writing it back, which is the best PSR-16 alone
 * allows and is not safe across processes: every caller in flight reads
 * the same number before any of them writes, so each believes it is the
 * first.
 *
 * That difference is not a rounding error. Measured against a real
 * Redis, a limit of five admitted all forty requests that arrived
 * together, and forty parallel failures recorded as one. A counter used
 * to enforce anything wants a cache from the first group; see
 * {doc}`middleware` and {doc}`auth`.
 *
 * isAtomic() reports which of the two is in use, so a caller can warn,
 * refuse, or record it.
 */
final readonly class Counter
{
    private ?AtomicCounterInterface $atomic;

    public function __construct(private CacheInterface $cache)
    {
        $this->atomic = $cache instanceof AtomicCounterInterface ? $cache : null;
    }

    public function isAtomic(): bool
    {
        return $this->atomic !== null;
    }

    /**
     * Increments $key by one, returning its new value, and sets the key
     * to expire $ttlSeconds from now. The expiry is refreshed on every
     * call, so a count decays from the last increment rather than the
     * first.
     */
    public function increment(string $key, int $ttlSeconds): int
    {
        if ($this->atomic !== null) {
            return $this->atomic->increment($key, $ttlSeconds);
        }

        $next = $this->count($key) + 1;
        $this->cache->set($key, $next, $ttlSeconds);

        return $next;
    }

    /**
     * Returns $key's current value without changing it, 0 when absent.
     */
    public function count(string $key): int
    {
        if ($this->atomic !== null) {
            return $this->atomic->count($key);
        }

        $value = $this->cache->get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}
