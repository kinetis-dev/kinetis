<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache and nothing more — what a consumer brings when they
 * register a third-party implementation of their own. Stands in for the
 * one case RateLimitMiddleware and AttemptThrottle refuse: a cache with
 * no way to count without reading first.
 */
final class NonAtomicCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $entries = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->entries[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $this->entries[$key] = $value;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key, $default);
        }

        return $values;
    }

    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }
}
