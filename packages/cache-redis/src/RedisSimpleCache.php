<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Amp\Redis\RedisException;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use DateInterval;
use DateTimeImmutable;
use Kinetis\Config\Config;
use Kinetis\Redis\QueryExecutor;
use Kinetis\Redis\RoutedExecutor;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

use function Kinetis\Async\concurrently;

/**
 * PSR-16 SimpleCache over `Kinetis\Redis\RoutedExecutor`, serving a
 * single node and a Redis Cluster through the same class: the executor
 * routes each key, and the only behaviour that differs is whether one
 * command may name keys from more than one slot.
 *
 * Values are serialized with the `Amp\Serialization\NativeSerializer`
 * `Amp\Redis\RedisCache` itself uses, so any serializable PHP value can
 * be stored as PSR-16 requires.
 *
 * Every physical key is written as `kinetis_cache:<namespace>:<key>`,
 * which is what keeps `clear()` off keys this cache did not write and
 * separates it from queue keys and unrelated data in the same database.
 * The prefix carries no `{}` hash tag, so keys still spread across
 * cluster slots.
 *
 * Not constructed unless Redis is configured — see fromConfig().
 * `AppScope::boot()` binds `NullSimpleCache` otherwise. Construction
 * opens no connection, so a configured but momentarily unreachable
 * server fails at the first cache call rather than at boot.
 */
final class RedisSimpleCache implements CacheInterface, AtomicCounterInterface, AtomicConsumeInterface
{
    /**
     * Enough per round trip to keep a large keyspace scan moving without
     * building an unbounded reply or delete.
     */
    private const int SCAN_COUNT = 512;

    private const int UNLINK_CHUNK = 256;

    private const string DEFAULT_NAMESPACE = 'default';

    private readonly string $prefix;

    public function __construct(
        private readonly RoutedExecutor $client,
        string $namespace = self::DEFAULT_NAMESPACE,
        private readonly Serializer $serializer = new NativeSerializer(),
    ) {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $namespace) !== 1) {
            throw new InvalidArgumentException(
                "Invalid cache namespace \"{$namespace}\": use letters, digits, underscores and dashes only.",
            );
        }

        $this->prefix = "kinetis_cache:{$namespace}:";
    }

    /**
     * Builds a configured instance, or null when Redis is not configured
     * at all — the case `AppScope::boot()` falls back to
     * `NullSimpleCache` for.
     *
     * `REDIS_CLUSTER=true` selects the cluster client and requires
     * `REDIS_CLUSTER_SEEDS`; otherwise `REDIS_URL` or
     * `REDIS_HOST` selects a single node. `$connection` selects a named
     * connection via `Config::scopedKey()`, and
     * `REDIS_CACHE_NAMESPACE` names the key namespace this instance
     * owns.
     */
    public static function fromConfig(Config $config, string $connection = 'default'): ?self
    {
        $client = RedisConnectionFactory::fromConfig($config, $connection);

        if ($client === null) {
            return null;
        }

        return new self(
            $client,
            $config->string(Config::scopedKey('REDIS_CACHE_NAMESPACE', $connection), self::DEFAULT_NAMESPACE),
        );
    }

    /**
     * INCR and EXPIRE in one script, so the value a caller receives is
     * its own and never one another caller also received. The counter
     * holds a bare integer rather than a serialized value, which is why
     * count() exists and get() must not be used to read it.
     */
    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        $physical = $this->physical($key);

        $value = $this->guard('increment', fn (): mixed => $this->client->script(
            $physical,
            "local v = redis.call('INCR', KEYS[1]) redis.call('EXPIRE', KEYS[1], ARGV[1]) return v",
            [$physical],
            [(string) $ttlSeconds],
        ));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function count(string $key): int
    {
        $physical = $this->physical($key);
        $value = $this->guard('count', fn (): mixed => $this->client->executeKeyed($physical, 'GET', $physical));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        $physical = $this->physical($key);
        $value = $this->guard('get', fn (): mixed => $this->client->executeKeyed($physical, 'GET', $physical));

        return is_string($value) ? $this->serializer->unserialize($value) : $default;
    }

    /**
     * GET and DEL in one script, the same shape increment()'s INCR+EXPIRE
     * already uses — so a value two callers both try to consume is
     * returned to at most one of them, never both.
     */
    #[\Override]
    public function consume(string $key, mixed $default = null): mixed
    {
        $physical = $this->physical($key);

        $value = $this->guard('consume', fn (): mixed => $this->client->script(
            $physical,
            "local v = redis.call('GET', KEYS[1]) if v then redis.call('DEL', KEYS[1]) end return v",
            [$physical],
        ));

        return is_string($value) ? $this->serializer->unserialize($value) : $default;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $physical = $this->physical($key);
        $seconds = self::ttlInSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $payload = $this->serializer->serialize($value);

        $this->guard('set', function () use ($physical, $payload, $seconds): void {
            $seconds !== null
                ? $this->client->executeKeyed($physical, 'SET', $physical, $payload, 'EX', $seconds)
                : $this->client->executeKeyed($physical, 'SET', $physical, $payload);
        });

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        $physical = $this->physical($key);
        $this->guard('delete', fn (): mixed => $this->client->executeKeyed($physical, 'DEL', $physical));

        return true;
    }

    /**
     * Removes this cache's own keys, and nothing else, by scanning each
     * current master for the namespace prefix and unlinking what it
     * finds.
     *
     * This is not atomic and not a snapshot: a key written after its
     * node's scan has passed survives, and a key migrating between two
     * nodes can be missed. It also costs one pass over each node's whole
     * keyspace, since `SCAN MATCH` filters server-side after reading.
     * Use it to reset a cache, not as part of request handling.
     */
    #[\Override]
    public function clear(): bool
    {
        $this->guard('clear', function (): void {
            concurrently(array_map(
                fn (QueryExecutor $node): \Closure => fn (): null => $this->clearNode($node),
                $this->client->nodes(),
            ));
        });

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return [];
        }

        $physical = array_map($this->physical(...), $keys);

        if ($this->client->allowsCrossSlotKeys()) {
            $raw = $this->guard('getMultiple', fn (): mixed => $this->client->executeKeyed($physical[0], 'MGET', ...$physical));
            $values = is_array($raw) ? array_values($raw) : [];
        } else {
            // Redis Cluster rejects any multi-key command whose keys do
            // not all share one slot, so each key is its own command,
            // dispatched concurrently.
            $values = concurrently(array_map(
                fn (string $one): \Closure => fn (): mixed => $this->guard(
                    'getMultiple',
                    fn (): mixed => $this->client->executeKeyed($one, 'GET', $one),
                ),
                $physical,
            ));
        }

        $result = [];

        foreach ($keys as $index => $key) {
            $value = $values[$index] ?? null;
            $result[$key] = is_string($value) ? $this->serializer->unserialize($value) : $default;
        }

        return $result;
    }

    /**
     * Redis's own MSET has no per-key TTL option, so a bulk set with a
     * shared $ttl is a loop of individual SET...EX calls, not one atomic
     * round trip.
     *
     * @param iterable<string, mixed> $values
     */
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
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return true;
        }

        $physical = array_map($this->physical(...), $keys);

        if ($this->client->allowsCrossSlotKeys()) {
            $this->guard('deleteMultiple', fn (): mixed => $this->client->executeKeyed($physical[0], 'DEL', ...$physical));

            return true;
        }

        concurrently(array_map(
            fn (string $one): \Closure => fn (): mixed => $this->guard(
                'deleteMultiple',
                fn (): mixed => $this->client->executeKeyed($one, 'DEL', $one),
            ),
            $physical,
        ));

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        $physical = $this->physical($key);

        return $this->guard('has', fn (): mixed => $this->client->executeKeyed($physical, 'EXISTS', $physical)) === 1;
    }

    private function clearNode(QueryExecutor $node): null
    {
        $pattern = addcslashes($this->prefix, '\\*?[]') . '*';
        $cursor = '0';

        do {
            $page = $node->execute('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', self::SCAN_COUNT);

            if (!is_array($page) || !is_array($page[1] ?? null)) {
                return null;
            }

            $cursor = (string) $page[0];
            $keys = array_map(strval(...), $page[1]);

            // One node still spans many slots, and a cluster rejects a
            // multi-key command whose keys do not all share one, so only
            // a single node deletes a page in batches.
            $batches = $this->client->allowsCrossSlotKeys()
                ? array_chunk($keys, self::UNLINK_CHUNK)
                : array_map(static fn (string $key): array => [$key], $keys);

            foreach ($batches as $batch) {
                $node->execute('UNLINK', ...$batch);
            }
        } while ($cursor !== '0');

        return null;
    }

    private function physical(string $key): string
    {
        self::assertValidKey($key);

        return $this->prefix . $key;
    }

    /**
     * A cache key can be a session identifier or a token hash, so the
     * failure names the operation and never the key.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guard(string $name, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $e) {
            throw CacheException::forOperation($name, $e);
        }
    }

    private static function ttlInSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }

        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    /**
     * @param iterable<mixed, string> $keys
     * @return list<string>
     */
    private static function normalizeKeys(iterable $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            self::assertValidKey($key);
            $normalized[] = $key;
        }

        return $normalized;
    }

    /**
     * Per the PSR-16 spec: a key must be a non-empty string and must not
     * contain any of the characters reserved for future extensions.
     */
    private static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw InvalidArgumentException::forKey($key, 'must not be empty.');
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw InvalidArgumentException::forKey($key, 'must not contain any of the reserved characters {}()/\@:.');
        }
    }
}
