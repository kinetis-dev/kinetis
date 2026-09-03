<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use DateInterval;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * A cache whose reads succeed (always a clean miss) but whose writes
 * throw — isolates DocumentationController::store()'s own catch from
 * cached()'s, unlike UnavailableSimpleCache, which fails both and so
 * cannot tell a test that regenerating-after-a-failed-write is what
 * actually happened, rather than regenerating-after-a-failed-read.
 */
final class WriteFailingSimpleCache implements CacheInterface
{
    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        throw new RuntimeException('write failed');
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
        throw new RuntimeException('write failed');
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
