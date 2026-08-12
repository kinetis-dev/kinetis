<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * The default CacheInterface binding when Redis isn't configured — the same
 * "sensible default nobody has to opt into" pattern as LoggerInterface's
 * NullLogger. Always misses, never actually stores anything, so a feature
 * built against CacheInterface (a rate limiter, ...) degrades to "not
 * enforced" rather than throwing a NotFoundException the first time
 * something tries to resolve the interface.
 */
final class NullSimpleCache implements CacheInterface
{
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $default;
        }

        return $result;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return false;
    }
}
