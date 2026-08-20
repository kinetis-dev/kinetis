<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

/**
 * A read-and-delete a PSR-16 cache can perform in one operation, without
 * a caller reading the value first and deleting it in a second call.
 *
 * PSR-16 has no such operation, and building one from get() then
 * delete() is not safe across processes: two callers can both read the
 * same value before either one deletes it, so both believe they are the
 * one to consume it. A single-use token checked this way is not
 * single-use, it is first-two-readers-use — measured against a real
 * Redis, two concurrent callers both successfully redeemed the same
 * token in 100 of 100 rounds.
 *
 * Implemented alongside CacheInterface by a backend that can do this —
 * kinetis/cache-redis does, through a Lua script. Kinetis\AuthJwt\RefreshTokenStore
 * requires it and refuses a cache without it, rather than advertising
 * single use while not enforcing it.
 */
interface AtomicConsumeInterface
{
    /**
     * Atomically reads $key's current value and deletes it, returning
     * that value, or $default when the key does not exist.
     *
     * Concurrent callers each see a distinct outcome: at most one
     * receives the real value, every other one receives $default —
     * never two callers receiving the same value.
     */
    public function consume(string $key, mixed $default = null): mixed;
}
