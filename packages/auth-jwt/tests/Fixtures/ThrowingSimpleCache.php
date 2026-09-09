<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use DateInterval;
use Kinetis\SimpleCache\AtomicConsumeInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

/**
 * get() throws rather than answering — a cache backend that is down, the
 * one case a revocation lookup must not turn into "this token is fine".
 * Every other method is a harmless stand-in; the tests this fixture
 * exists for never reach them.
 */
final class ThrowingSimpleCache implements CacheInterface, AtomicConsumeInterface
{
    public const string MESSAGE = 'cache backend unreachable';

    public function get(string $key, mixed $default = null): mixed
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function consume(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $default;
        }

        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return false;
    }
}
