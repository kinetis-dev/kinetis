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
use Kinetis\SimpleCache\AtomicCounterInterface;
use Kinetis\SimpleCache\AtomicConsumeInterface;
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
final class RedisSimpleCache implements CacheInterface, AtomicCounterInterface, AtomicConsumeInterface
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
            return RedisConfig::fromUri($url, self::timeoutFromConfig($config, $connection));
        }

        $host = $config->get(Config::scopedKey('REDIS_HOST', $connection));

        if ($host === null) {
            return null;
        }

        $portKey = Config::scopedKey('REDIS_PORT', $connection);
        $port = $config->int($portKey, RedisConfig::DEFAULT_PORT);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("{$portKey} must be a valid TCP port (1-65535), got {$port}.");
        }

        $redisConfig = RedisConfig::fromUri("tcp://{$host}:{$port}", self::timeoutFromConfig($config, $connection));

        $password = $config->get(Config::scopedKey('REDIS_PASSWORD', $connection));

        if ($password !== null) {
            $redisConfig = $redisConfig->withPassword($password);
        }

        $databaseKey = Config::scopedKey('REDIS_DATABASE', $connection);
        $database = $config->int($databaseKey, 0);

        if ($database < 0) {
            throw new InvalidArgumentException("{$databaseKey} must not be negative, got {$database}.");
        }

        return $redisConfig->withDatabase($database);
    }

    /**
     * Shared by both the REDIS_URL and discrete REDIS_HOST branches of
     * {@see buildRedisConfig()}, so the connect-timeout bound is enforced
     * identically regardless of which form a deployment uses to configure
     * Redis.
     */
    private static function timeoutFromConfig(Config $config, string $connection): float
    {
        $timeoutKey = Config::scopedKey('REDIS_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, RedisConfig::DEFAULT_TIMEOUT);

        if ($timeout <= 0.0) {
            throw new InvalidArgumentException("{$timeoutKey} must be a positive number of seconds, got {$timeout}.");
        }

        return $timeout;
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
        self::assertValidKey($key);

        $value = $this->guard('increment', $key, fn () => $this->client->eval(
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

        $value = $this->guard('count', $key, fn () => $this->client->get($key));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guard('get', $key, fn () => $this->client->get($key));

        return $value === null ? $default : $this->serializer->unserialize($value);
    }

    /**
     * GET and DEL in one script, the same shape increment()'s INCR+EXPIRE
     * already uses — so a value two callers both try to consume is
     * returned to at most one of them, never both.
     */
    #[\Override]
    public function consume(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guard('consume', $key, fn () => $this->client->eval(
            "local v = redis.call('GET', KEYS[1]) if v then redis.call('DEL', KEYS[1]) end return v",
            [$key],
        ));

        return $value === null ? $default : $this->serializer->unserialize((string) $value);
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
