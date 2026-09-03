<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use DateInterval;
use Kinetis\SimpleCache\AtomicConsumeInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * set() and delete() always report failure (return false, per PSR-16's
 * own "false if there was an error" contract) without throwing — the
 * exact shape a conforming-but-struggling cache backend can produce,
 * which RevocationStore/RefreshTokenStore must not silently accept as
 * success. Every other method is a harmless stand-in; none of the
 * adversarial tests this fixture exists for ever reaches them.
 */
final class FailingSimpleCache implements CacheInterface, AtomicConsumeInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function consume(string $key, mixed $default = null): mixed
    {
        return $default;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function clear(): bool
    {
        return false;
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
        return false;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return false;
    }

    public function has(string $key): bool
    {
        return false;
    }
}
