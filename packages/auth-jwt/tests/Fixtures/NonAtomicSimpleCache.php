<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache and nothing more — what a consumer brings when they
 * register a third-party implementation of their own. Stands in for the
 * one case RefreshTokenStore refuses: a cache with no way to consume a
 * token without reading it first. Mirrors core's own
 * Kinetis\Tests\Fixtures\NonAtomicCache, duplicated here for the same
 * reason InMemorySimpleCache is.
 */
final class NonAtomicSimpleCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->entries[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->entries[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }
}
