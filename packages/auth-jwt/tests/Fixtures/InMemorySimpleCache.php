<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * Array-backed Psr\SimpleCache\CacheInterface with real TTL expiry
 * tracking (checked on read, not swept proactively) — fast, network-free
 * stand-in for RedisSimpleCache. Mirrors Kinetis\Tests\Fixtures\InMemorySimpleCache
 * from core's own test suite; duplicated here since satellite packages'
 * autoload-dev can't reach into core's tests/ directory.
 */
final class InMemorySimpleCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expiresAt: ?int}> */
    private array $entries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->has($key) ? $this->entries[$key]['value'] : $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $ttl instanceof DateInterval
            ? (new DateTimeImmutable())->add($ttl)->getTimestamp() - time()
            : $ttl;

        $this->entries[$key] = [
            'value' => $value,
            'expiresAt' => $seconds === null ? null : time() + $seconds,
        ];

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
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
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
        if (!array_key_exists($key, $this->entries)) {
            return false;
        }

        $expiresAt = $this->entries[$key]['expiresAt'];

        if ($expiresAt !== null && $expiresAt <= time()) {
            unset($this->entries[$key]);

            return false;
        }

        return true;
    }
}
