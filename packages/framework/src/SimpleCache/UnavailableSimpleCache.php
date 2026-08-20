<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use DateInterval;
use Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException;
use Psr\SimpleCache\CacheInterface;

/**
 * The CacheInterface binding when Redis is configured but no driver
 * package is installed: every operation throws, nothing is silently
 * discarded.
 *
 * The failure lands where the cache is used rather than at
 * AppScope::boot(), so a stale REDIS_HOST in a .env costs an
 * application that never touches CacheInterface nothing. One that does
 * use it fails at the first call, and never degrades to
 * {@see NullSimpleCache}, which would enforce no rate limit and revoke
 * no token while looking healthy.
 *
 * This is the same usage-time-over-configuration-time choice
 * {@see \Kinetis\Http\Middleware\RateLimitMiddleware} and
 * `Kinetis\AuthJwt\RevocationStore` make by rejecting NullSimpleCache
 * at construction rather than having the container refuse to boot
 * without Redis.
 */
final class UnavailableSimpleCache implements CacheInterface, AtomicCounterInterface, AtomicConsumeInterface
{
    public function __construct(private readonly string $package = 'kinetis/cache-redis') {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        throw $this->unavailable();
    }

    /**
     * Implemented so RateLimitMiddleware and AttemptThrottle accept this
     * cache at construction and fail on first use, naming the package to
     * install rather than complaining that the cache cannot count.
     */
    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function count(string $key): int
    {
        throw $this->unavailable();
    }

    /**
     * Implemented so RefreshTokenStore accepts this cache at construction
     * and fails on first use, naming the package to install rather than
     * complaining that the cache cannot consume atomically.
     */
    #[\Override]
    public function consume(string $key, mixed $default = null): mixed
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function delete(string $key): bool
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function clear(): bool
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        throw $this->unavailable();
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        throw $this->unavailable();
    }

    #[\Override]
    public function has(string $key): bool
    {
        throw $this->unavailable();
    }

    private function unavailable(): SimpleCacheUnavailableException
    {
        return SimpleCacheUnavailableException::missingDriverPackage($this->package);
    }
}
