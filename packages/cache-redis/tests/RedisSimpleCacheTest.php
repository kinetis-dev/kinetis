<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Kinetis\Config\Config;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Kinetis\SimpleCache\RedisSimpleCache;
use Amp\Redis\RedisConfig;
use PHPUnit\Framework\TestCase;

final class RedisSimpleCacheTest extends TestCase
{
    public function test_build_redis_config_returns_null_when_neither_url_nor_host_is_set(): void
    {
        self::assertNull(RedisSimpleCache::buildRedisConfig(new Config([])));
    }

    public function test_from_config_returns_null_when_redis_is_not_configured(): void
    {
        self::assertNull(RedisSimpleCache::fromConfig(new Config([])));
    }

    public function test_from_config_returns_an_instance_when_redis_is_configured_via_host(): void
    {
        // createRedisClient() never connects eagerly, so this is safe to
        // construct with no real Redis server reachable — see the class
        // docblock. Actual get/set/delete behavior is verified separately
        // against a real Redis container (not part of this suite).
        self::assertInstanceOf(RedisSimpleCache::class, RedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost'])));
    }

    public function test_from_config_returns_an_instance_when_tls_is_enabled(): void
    {
        // TlsRedisConnector::fromConfig() never connects eagerly either —
        // just builds a ConnectContext/ClientTlsContext — so this is safe
        // with no real TLS-enabled Redis reachable. The actual handshake
        // is verified separately against a real TLS-enabled container.
        self::assertInstanceOf(RedisSimpleCache::class, RedisSimpleCache::fromConfig(new Config([
            'REDIS_HOST' => 'localhost',
            'REDIS_TLS' => 'true',
            'REDIS_TLS_VERIFY_PEER' => 'false',
        ])));
    }

    public function test_build_redis_config_uses_redis_url_directly_when_set(): void
    {
        $config = RedisSimpleCache::buildRedisConfig(new Config([
            'REDIS_URL' => 'redis://:secret@cache.internal:7000/3',
        ]));

        self::assertNotNull($config);
        self::assertSame('tcp://cache.internal:7000', $config->getConnectUri());
        self::assertSame('secret', $config->getPassword());
        self::assertSame(3, $config->getDatabase());
    }

    public function test_build_redis_config_builds_from_discrete_host_parts(): void
    {
        $config = RedisSimpleCache::buildRedisConfig(new Config([
            'REDIS_HOST' => 'cache.internal',
            'REDIS_PORT' => '7000',
            'REDIS_PASSWORD' => 'secret',
            'REDIS_DATABASE' => '2',
        ]));

        self::assertNotNull($config);
        self::assertSame('tcp://cache.internal:7000', $config->getConnectUri());
        self::assertTrue($config->hasPassword());
        self::assertSame('secret', $config->getPassword());
        self::assertSame(2, $config->getDatabase());
    }

    public function test_build_redis_config_uses_sane_defaults_when_only_host_is_given(): void
    {
        $config = RedisSimpleCache::buildRedisConfig(new Config(['REDIS_HOST' => 'cache.internal']));

        self::assertNotNull($config);
        self::assertSame('tcp://cache.internal:' . RedisConfig::DEFAULT_PORT, $config->getConnectUri());
        self::assertFalse($config->hasPassword());
        self::assertSame(0, $config->getDatabase());
    }

    public function test_build_redis_config_prefers_redis_url_over_discrete_parts(): void
    {
        $config = RedisSimpleCache::buildRedisConfig(new Config([
            'REDIS_URL' => 'redis://cache.internal:7000',
            'REDIS_HOST' => 'ignored.internal',
        ]));

        self::assertNotNull($config);
        self::assertSame('tcp://cache.internal:7000', $config->getConnectUri());
    }

    public function test_build_redis_config_reads_the_named_connections_own_keys(): void
    {
        $config = new Config([
            'REDIS_HOST' => 'default.internal',
            'REDIS_CACHE2_HOST' => 'cache2.internal',
            'REDIS_CACHE2_PORT' => '7001',
        ]);

        $default = RedisSimpleCache::buildRedisConfig($config);
        $named = RedisSimpleCache::buildRedisConfig($config, 'cache2');

        self::assertNotNull($default);
        self::assertNotNull($named);
        self::assertSame('tcp://default.internal:' . RedisConfig::DEFAULT_PORT, $default->getConnectUri());
        self::assertSame('tcp://cache2.internal:7001', $named->getConnectUri());
    }

    public function test_build_redis_config_for_a_named_connection_ignores_the_default_ones_keys(): void
    {
        $config = new Config(['REDIS_HOST' => 'default.internal']);

        self::assertNull(RedisSimpleCache::buildRedisConfig($config, 'cache2'));
    }

    private function configuredCache(): RedisSimpleCache
    {
        /** @var RedisSimpleCache */
        return RedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost']));
    }

    public function test_get_rejects_an_empty_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuredCache()->get('');
    }

    public function test_set_rejects_a_key_with_a_reserved_character_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuredCache()->set('user{123}', 'value');
    }

    public function test_delete_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuredCache()->delete('a/b');
    }

    public function test_has_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->configuredCache()->has('a:b');
    }

    public function test_get_multiple_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // getMultiple() is a plain method, not a generator, so normalizeKeys()
        // validating every key runs eagerly — the exception fires on this
        // call itself, before any network access, not on iteration.
        $this->configuredCache()->getMultiple(['ok', 'bad(key)']);
    }

    public function test_get_multiple_with_no_keys_returns_an_empty_array_without_touching_the_network(): void
    {
        self::assertSame([], $this->configuredCache()->getMultiple([]));
    }

    public function test_delete_multiple_with_no_keys_returns_true_without_touching_the_network(): void
    {
        self::assertTrue($this->configuredCache()->deleteMultiple([]));
    }
}
