<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Kinetis\Config\Config;
use Kinetis\SimpleCache\Connection\TlsRedisConnector;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

use function Amp\Redis\createRedisClient;

/**
 * PSR-16 SimpleCache backed by Amp\Redis\RedisClient. Values are serialized
 * with the same Amp\Serialization\NativeSerializer Amp\Redis\RedisCache
 * itself uses internally — reused rather than reimplemented, since PSR-16
 * allows storing any serializable PHP value, not just strings.
 *
 * Not constructed unless Redis is actually configured — see fromConfig().
 * AppScope::boot() falls back to NullSimpleCache otherwise, so an
 * application that never sets REDIS_URL/REDIS_HOST never touches this class
 * at all. createRedisClient() itself is lazy regardless — the underlying
 * Amp\Redis\Connection\ReconnectingRedisLink only opens a socket on the
 * first command actually executed — so constructing this eagerly (the same
 * discipline every other AppScope service uses) costs nothing when Redis
 * is configured but momentarily unreachable; the first real cache access
 * throws, not construction.
 *
 * Single node only — see ClusteredRedisSimpleCache for a Redis Cluster
 * deployment (REDIS_CLUSTER=true), which routes each key to whichever
 * node actually owns it instead of one fixed connection.
 */
final class RedisSimpleCache implements CacheInterface
{
    public function __construct(
        private readonly RedisClient $client,
        private readonly Serializer $serializer = new NativeSerializer(),
    ) {}

    /**
     * Builds a configured instance from REDIS_URL (a full
     * "redis://[:password@]host[:port][/database]" URI) or, absent that,
     * from discrete REDIS_HOST/REDIS_PORT/REDIS_PASSWORD/REDIS_DATABASE/
     * REDIS_TIMEOUT values — or null when neither is set, the "Redis is
     * optional" case AppScope::boot() falls back to NullSimpleCache for.
     *
     * $connection selects a named connection via Config::scopedKey() —
     * 'default' (the default) reads the plain REDIS_* keys above unchanged;
     * any other name reads REDIS_{NAME}_* instead. A named connection is
     * never autowired by type; retrieve it from the container explicitly
     * (or construct it directly) wherever it's needed.
     */
    public static function fromConfig(Config $config, string $connection = 'default'): ?self
    {
        $redisConfig = self::buildRedisConfig($config, $connection);

        if ($redisConfig === null) {
            return null;
        }

        $timeout = $config->float(Config::scopedKey('REDIS_TIMEOUT', $connection), RedisConfig::DEFAULT_TIMEOUT);
        $connector = TlsRedisConnector::fromConfig($config, $redisConfig->getConnectUri(), $timeout, $connection);

        return new self(createRedisClient($redisConfig, $connector));
    }

    /**
     * Split out from fromConfig() so the configuration-parsing logic is
     * testable without ever constructing a RedisClient — createRedisClient()
     * doesn't connect eagerly either, but keeping this pure and RedisConfig-
     * shaped avoids any question of it.
     */
    public static function buildRedisConfig(Config $config, string $connection = 'default'): ?RedisConfig
    {
        $url = $config->get(Config::scopedKey('REDIS_URL', $connection));

        if ($url !== null) {
            return RedisConfig::fromUri($url, $config->float(Config::scopedKey('REDIS_TIMEOUT', $connection), RedisConfig::DEFAULT_TIMEOUT));
        }

        $host = $config->get(Config::scopedKey('REDIS_HOST', $connection));

        if ($host === null) {
            return null;
        }

        $port = $config->int(Config::scopedKey('REDIS_PORT', $connection), RedisConfig::DEFAULT_PORT);
        $redisConfig = RedisConfig::fromUri(
            "tcp://{$host}:{$port}",
            $config->float(Config::scopedKey('REDIS_TIMEOUT', $connection), RedisConfig::DEFAULT_TIMEOUT),
        );

        $password = $config->get(Config::scopedKey('REDIS_PASSWORD', $connection));

        if ($password !== null) {
            $redisConfig = $redisConfig->withPassword($password);
        }

        return $redisConfig->withDatabase($config->int(Config::scopedKey('REDIS_DATABASE', $connection), 0));
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guard('get', $key, fn () => $this->client->get($key));

        return $value === null ? $default : $this->serializer->unserialize($value);
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
            $this->client->set($key, $this->serializer->serialize($value), $options);
        });

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        self::assertValidKey($key);
        $this->guard('delete', $key, fn () => $this->client->delete($key));

        return true;
    }

    /**
     * Flushes the *entire currently selected database* — not just keys this
     * cache wrote. Correct when, as recommended, Redis is configured with a
     * dedicated REDIS_DATABASE for Kinetis's cache; a database shared with
     * unrelated data would lose it too. Not hidden behind a narrower
     * per-prefix scan-and-delete, since that's neither atomic nor complete
     * (a concurrent writer could add a key between the scan and the
     * deletes) — flushdb is what Redis itself guarantees is exhaustive.
     */
    #[\Override]
    public function clear(): bool
    {
        $this->guard('clear', '*', fn () => $this->client->flushDatabase());

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return [];
        }

        $raw = $this->guard('getMultiple', implode(',', $keys), fn () => $this->client->getMultiple(...$keys));

        $result = [];

        foreach ($raw as $key => $value) {
            $result[$key] = $value === null ? $default : $this->serializer->unserialize($value);
        }

        return $result;
    }

    /**
     * Redis's own MSET has no per-key TTL option, so a bulk set with a
     * shared $ttl is a loop of individual SET...EX calls, not one atomic
     * round trip — the same tradeoff most PSR-16 Redis adapters make, since
     * there's no server-side primitive that does both at once.
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

        $this->guard('deleteMultiple', implode(',', $keys), fn () => $this->client->delete(...$keys));

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        self::assertValidKey($key);

        return $this->guard('has', $key, fn () => $this->client->has($key));
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guard(string $name, string $key, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $e) {
            throw CacheException::forOperation($name, $key, $e);
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
