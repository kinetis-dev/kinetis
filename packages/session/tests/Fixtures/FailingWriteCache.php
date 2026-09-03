<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache whose set()/delete() can be made to report failure two
 * different ways — returning false (the documented, non-exceptional
 * failure signal PSR-16 allows) or throwing directly — for proving
 * CacheSessionStore reacts correctly to both: a false return becomes a
 * SessionException naming the operation, while a thrown exception is
 * left to propagate completely unmodified, never wrapped.
 *
 * @internal test fixture only
 */
final class FailingWriteCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public bool $failSet = false;

    public bool $failDelete = false;

    public ?\Throwable $throwOnSet = null;

    public ?\Throwable $throwOnDelete = null;

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, DateInterval|int|null $ttl = null): bool
    {
        if ($this->throwOnSet !== null) {
            throw $this->throwOnSet;
        }

        if ($this->failSet) {
            return false;
        }

        $this->items[$key] = $value;

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        if ($this->throwOnDelete !== null) {
            throw $this->throwOnDelete;
        }

        if ($this->failDelete) {
            return false;
        }

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
        return \array_key_exists($key, $this->items);
    }
}
