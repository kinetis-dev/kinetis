<?php

declare(strict_types=1);

namespace Kinetis\Session\Store;

use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Support\SessionExpiry;
use Kinetis\SimpleCache\RedisSimpleCache;

/**
 * Sessions in Redis, over the cache the container already holds — single
 * node and Redis Cluster alike, TLS included, since that class routes
 * every key. The concrete class rather than PSR-16, because update()
 * must be conditional: `RedisSimpleCache::replace()` is a `SET ... XX`
 * that refuses to recreate a key another request removed, and PSR-16 has
 * no operation that can express it.
 *
 * Values are projected to JSON here rather than handed to the cache as a
 * live PHP graph, so a read returns plain data. Expiry is the key's own
 * TTL, so there is nothing for `session:gc` to collect.
 */
final readonly class RedisSessionStore implements SessionStoreInterface
{
    public function __construct(private RedisSimpleCache $cache) {}

    /**
     * @return ?array<string, mixed>
     */
    #[\Override]
    public function read(string $id): ?array
    {
        $payload = $this->cache->get(self::keyFor($id));

        if (!\is_string($payload)) {
            return null;
        }

        $data = \json_decode($payload, true);

        /** @var ?array<string, mixed> */
        return \is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function create(string $id, array $data, int $lifetimeSeconds): void
    {
        SessionExpiry::timestampFor($lifetimeSeconds);
        $this->cache->set(self::keyFor($id), self::encode($data), $lifetimeSeconds);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function update(string $id, array $data, int $lifetimeSeconds): bool
    {
        SessionExpiry::timestampFor($lifetimeSeconds);

        return $this->cache->replace(self::keyFor($id), self::encode($data), $lifetimeSeconds);
    }

    #[\Override]
    public function destroy(string $id): void
    {
        $this->cache->delete(self::keyFor($id));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }

    private static function keyFor(string $id): string
    {
        return 'session.' . $id;
    }
}
