<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use DateInterval;
use Kinetis\SimpleCache\AtomicConsumeInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Wraps a real InMemorySimpleCache and records every key passed to
 * get() — used to prove JwtAuthMiddleware's strict claim gate rejects a
 * malformed token before either revocation lookup ever reaches the
 * cache, not just that the response happens to come back 401.
 */
final class RecordingSimpleCache implements CacheInterface, AtomicConsumeInterface
{
    /** @var list<string> */
    public array $getCalls = [];

    private readonly InMemorySimpleCache $inner;

    public function __construct()
    {
        $this->inner = new InMemorySimpleCache();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->getCalls[] = $key;

        return $this->inner->get($key, $default);
    }

    public function consume(string $key, mixed $default = null): mixed
    {
        return $this->inner->consume($key, $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->inner->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->inner->delete($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->inner->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return $this->inner->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->inner->deleteMultiple($keys);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }
}
