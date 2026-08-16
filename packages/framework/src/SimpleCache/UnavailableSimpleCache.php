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
 * This exists so the failure lands where the cache is actually used
 * rather than at AppScope::boot(). A leftover REDIS_HOST in a .env
 * nothing reads anymore is common enough that crashing the whole
 * application over it is the wrong trade — an app that never touches
 * CacheInterface has nothing to fail about. An app that does still
 * fails, loudly, at the first real call, and never degrades to
 * {@see NullSimpleCache}, which would enforce no rate limit and revoke
 * no token while looking perfectly healthy.
 *
 * The same usage-time-over-configuration-time choice
 * {@see \Kinetis\Http\Middleware\RateLimitMiddleware} and
 * `Kinetis\AuthJwt\RevocationStore` already make by rejecting
 * NullSimpleCache at construction rather than having the container
 * refuse to boot without Redis.
 */
final class UnavailableSimpleCache implements CacheInterface
{
    public function __construct(private readonly string $package = 'kinetis/cache-redis') {}

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
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
