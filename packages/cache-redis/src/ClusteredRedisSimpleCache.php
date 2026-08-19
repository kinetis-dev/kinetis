<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Kinetis\SimpleCache\AtomicCounterInterface;
use DateInterval;
use DateTimeImmutable;
use Kinetis\Config\Config;
use Kinetis\SimpleCache\Cluster\ClusterTopology;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\Connection\TlsRedisConnector;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

use function Amp\Redis\createRedisClient;
use function Kinetis\Async\concurrently;

/**
 * The cluster-aware counterpart to RedisSimpleCache — same
 * Psr\SimpleCache\CacheInterface, but every key is routed to whichever
 * cluster node actually owns it (Crc16::slotFor()) rather than a single
 * fixed connection. A separate class rather than a mode flag on
 * RedisSimpleCache: the two have genuinely different routing logic for
 * every single method, not a small conditional branch.
 *
 * Redis Cluster only ever supports database 0 — REDIS_DATABASE has no
 * equivalent here, unlike RedisSimpleCache's single-node config.
 */
final class ClusteredRedisSimpleCache implements CacheInterface, AtomicCounterInterface
{
    private readonly Serializer $serializer;

    public function __construct(
        private readonly ClusterTopology $topology,
        ?Serializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
    }

    /**
     * Requires REDIS_CLUSTER=true — returns null otherwise, the same
     * "Redis is optional" contract RedisSimpleCache::fromConfig() has, so
     * AppScope::boot() can try this first and fall back to the single-node
     * class. REDIS_CLUSTER_SEEDS (comma-separated "host:port" entries) is
     * required once REDIS_CLUSTER is set — a cluster needs more than one
     * seed to bootstrap from in case any single one happens to be down,
     * so there's no single-host fallback the way RedisSimpleCache has.
     */
    public static function fromConfig(Config $config, string $connection = 'default'): ?self
    {
        if (!$config->bool(Config::scopedKey('REDIS_CLUSTER', $connection), false)) {
            return null;
        }

        $seeds = array_map('trim', explode(',', $config->required(Config::scopedKey('REDIS_CLUSTER_SEEDS', $connection))));
        $timeout = $config->float(Config::scopedKey('REDIS_TIMEOUT', $connection), RedisConfig::DEFAULT_TIMEOUT);
        $password = $config->get(Config::scopedKey('REDIS_PASSWORD', $connection));

        $clientFactory = static function (string $host, int $port) use ($config, $connection, $timeout, $password): RedisClient {
            $uri = "tcp://{$host}:{$port}";
            $redisConfig = RedisConfig::fromUri($uri, $timeout);

            if ($password !== null) {
                $redisConfig = $redisConfig->withPassword($password);
            }

            return createRedisClient($redisConfig, TlsRedisConnector::fromConfig($config, $uri, $timeout, $connection));
        };

        return new self(new ClusterTopology($seeds, $clientFactory));
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guard('get', $key, fn (): ?string => $this->topology->nodeForSlot(Crc16::slotFor($key))->get($key));

        return $value === null ? $default : $this->serializer->unserialize($value);
    }

    /**
     * One key, so the script runs on whichever node owns that key's
     * slot — the same routing every other operation here uses.
     */
    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        self::assertValidKey($key);

        $value = $this->guard('increment', $key, fn () => $this->topology->nodeForSlot(Crc16::slotFor($key))->eval(
            "local v = redis.call('INCR', KEYS[1]) redis.call('EXPIRE', KEYS[1], ARGV[1]) return v",
            [$key],
            [(string) $ttlSeconds],
        ));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function count(string $key): int
    {
        self::assertValidKey($key);

        $value = $this->guard('count', $key, fn () => $this->topology->nodeForSlot(Crc16::slotFor($key))->get($key));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        self::assertValidKey($key);
        $seconds = self::ttlInSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $this->guard('set', $key, function () use ($key, $value, $seconds): void {
            $options = $seconds !== null ? (new SetOptions())->withTtl($seconds) : null;
            $this->topology->nodeForSlot(Crc16::slotFor($key))->set($key, $this->serializer->serialize($value), $options);
        });

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        self::assertValidKey($key);
        $this->guard('delete', $key, fn (): int => $this->topology->nodeForSlot(Crc16::slotFor($key))->delete($key));

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        self::assertValidKey($key);

        return $this->guard('has', $key, fn (): bool => $this->topology->nodeForSlot(Crc16::slotFor($key))->has($key));
    }

    /**
     * FLUSHDB against one cluster node only clears that node's own shard,
     * not the cluster as a whole, so this fans out to every known master
     * — concurrently, since each is an independent round trip to a
     * different node.
     */
    #[\Override]
    public function clear(): bool
    {
        $this->guard('clear', '*', function (): void {
            $masters = $this->topology->allMasters();

            if ($masters !== []) {
                concurrently(array_map(
                    static fn (RedisClient $client) => static function () use ($client): void {
                        $client->flushDatabase();
                    },
                    $masters,
                ));
            }
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

        // Not a per-node MGET: Redis Cluster rejects *any* multi-key
        // command whose keys don't all share one slot, even when a
        // single node happens to physically own every slot involved —
        // confirmed directly against a real cluster, not assumed from
        // the single-node client's own multi-key methods existing.
        // Dispatched one GET per key instead, concurrently, each
        // independently guarded/retried.
        $rawValues = concurrently(array_map(
            fn (string $key) => fn (): ?string => $this->guard(
                'getMultiple',
                $key,
                fn (): ?string => $this->topology->nodeForSlot(Crc16::slotFor($key))->get($key),
            ),
            $keys,
        ));

        $result = [];

        foreach ($keys as $i => $key) {
            $value = $rawValues[$i];
            $result[$key] = $value === null ? $default : $this->serializer->unserialize($value);
        }

        return $result;
    }

    /**
     * A loop of independent set() calls run concurrently — Redis's MSET
     * has no per-key TTL option, so there was never a batched command to
     * send here even on a single node; concurrently() still saves real
     * time since different keys can land on different cluster nodes.
     *
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $tasks = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $tasks[] = fn (): bool => $this->set($key, $value, $ttl);
        }

        if ($tasks !== []) {
            concurrently($tasks);
        }

        return true;
    }

    /**
     * One DELETE per key, concurrently — same CROSSSLOT reasoning as
     * getMultiple(), confirmed the same way: DEL rejects a multi-key call
     * whose keys don't share a slot exactly like GET/MGET does.
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return true;
        }

        concurrently(array_map(
            fn (string $key) => fn (): int => $this->guard(
                'deleteMultiple',
                $key,
                fn (): int => $this->topology->nodeForSlot(Crc16::slotFor($key))->delete($key),
            ),
            $keys,
        ));

        return true;
    }

    /**
     * Runs $operation; a MOVED reply (the cluster reports a slot has been
     * reassigned since the last topology refresh) triggers exactly one
     * full topology refresh and one retry of the whole operation from
     * scratch — not a targeted patch of the one slot involved, since a
     * single resharding event can move more than that one slot. $operation
     * re-resolves nodes itself on every call (via Crc16::slotFor()/
     * ClusterTopology::nodeForSlot()), so the retry genuinely uses the
     * refreshed topology rather than a stale resolution.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guard(string $operationName, string $contextKey, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $e) {
            if (!str_starts_with($e->getMessage(), 'MOVED ')) {
                throw CacheException::forOperation($operationName, $contextKey, $e);
            }
        } catch (RedisException $e) {
            throw CacheException::forOperation($operationName, $contextKey, $e);
        }

        $this->topology->refresh();

        try {
            return $operation();
        } catch (RedisException $e) {
            throw CacheException::forOperation($operationName, $contextKey, $e);
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
