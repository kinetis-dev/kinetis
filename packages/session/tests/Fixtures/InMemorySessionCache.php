<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/** A real, TTL-aware PSR-16 array cache for the cache-store tests. */
final class InMemorySessionCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: ?float}> */
    private array $items = [];

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->items[$key] ?? null;

        if ($item === null || ($item['expiresAt'] !== null && $item['expiresAt'] < \microtime(true))) {
            unset($this->items[$key]);

            return $default;
        }

        return $item['value'];
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        $seconds = $ttl instanceof DateInterval ? (int) $ttl->format('%s') : $ttl;
        $this->items[$key] = ['value' => $value, 'expiresAt' => $seconds !== null ? \microtime(true) + $seconds : null];

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        unset($this->items[$key]);

        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->items = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     * @return iterable<string, mixed>
     */
    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
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
        return $this->get($key, $this) !== $this;
    }
}
