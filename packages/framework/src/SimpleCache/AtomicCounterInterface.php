<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

/**
 * A counter a PSR-16 cache can increment without the caller reading the
 * value first.
 *
 * PSR-16 has no such operation, and building one from get() then set()
 * is not safe across processes: every request in flight reads the same
 * value before any of them writes, so each one believes it is the first.
 * A limiter built that way does not degrade under load, it stops
 * counting — measured at 40 parallel requests all passing a limit of 5,
 * and 40 parallel failures recording as one.
 *
 * Implemented alongside CacheInterface by a backend that can do this —
 * kinetis/cache-redis does, through a Lua script. Kinetis\Http\Middleware\RateLimitMiddleware
 * and Kinetis\Security\AttemptThrottle require it and refuse a cache
 * without it, rather than enforcing nothing while appearing to work.
 */
interface AtomicCounterInterface
{
    /**
     * Increments $key by one, returning its new value, and sets the key
     * to expire $ttlSeconds from now.
     *
     * A key that does not exist counts as zero, so the first caller
     * receives 1. Concurrent callers each receive a distinct value: no
     * two ever see the same one. The expiry is refreshed on every call,
     * which is what lets a caller decay a count from the last increment
     * rather than the first.
     */
    public function increment(string $key, int $ttlSeconds): int;

    /**
     * Returns $key's current value without changing it, or 0 when it
     * does not exist.
     *
     * A counter is stored in whatever form the backend increments
     * natively, which is not necessarily the form CacheInterface::get()
     * reads — a Redis INCR counter holds a bare integer, where the cache
     * otherwise stores serialized values. Read a counter through here,
     * never through get().
     */
    public function count(string $key): int;
}
