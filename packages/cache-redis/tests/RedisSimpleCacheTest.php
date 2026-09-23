<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Amp\Serialization\SerializationException;
use Kinetis\Config\Config;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Endpoint;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Key policy, configuration selection, executor ownership, and the
 * encode half of the serializer boundary. Construction opens no
 * connection, building a link does not dial it, an invalid key
 * is refused before any command is built, and a value the serializer
 * cannot encode fails before one is sent, so every case here runs with
 * no Redis server reachable. Storage behaviour, and the decode half of
 * that boundary, are proven against a real server in tests/Integration.
 */
final class RedisSimpleCacheTest extends TestCase
{
    public function test_from_config_returns_null_when_redis_is_not_configured(): void
    {
        self::assertNull(RedisSimpleCache::fromConfig(new Config([])));
    }

    public function test_from_config_returns_one_class_for_a_single_node(): void
    {
        self::assertInstanceOf(
            RedisSimpleCache::class,
            RedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost'])),
        );
    }

    public function test_from_config_returns_the_same_class_for_a_cluster(): void
    {
        self::assertInstanceOf(RedisSimpleCache::class, RedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001,node2:7002',
        ])));
    }

    public function test_from_config_carries_tls_settings_through(): void
    {
        self::assertInstanceOf(RedisSimpleCache::class, RedisSimpleCache::fromConfig(new Config([
            'REDIS_HOST' => 'localhost',
            'REDIS_TLS' => 'true',
            'REDIS_TLS_VERIFY_PEER' => 'false',
        ])));
    }

    public function test_a_namespace_outside_the_supported_grammar_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cache namespace');

        new RedisSimpleCache($this->client(), 'tenant:1');
    }

    public function test_an_empty_namespace_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RedisSimpleCache($this->client(), '');
    }

    public function test_get_rejects_an_empty_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache()->get('');
    }

    public function test_set_rejects_a_key_with_a_reserved_character_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache()->set('user{123}', 'value');
    }

    public function test_delete_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache()->delete('a/b');
    }

    public function test_has_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cache()->has('a:b');
    }

    public function test_get_multiple_rejects_an_invalid_key_before_touching_the_network(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // getMultiple() is a plain method, not a generator, so normalizeKeys()
        // validating every key runs eagerly — the exception fires on this
        // call itself, before any network access, not on iteration.
        $this->cache()->getMultiple(['ok', 'bad(key)']);
    }

    public function test_get_multiple_with_no_keys_returns_an_empty_array_without_touching_the_network(): void
    {
        self::assertSame([], $this->cache()->getMultiple([]));
    }

    public function test_delete_multiple_with_no_keys_returns_true_without_touching_the_network(): void
    {
        self::assertTrue($this->cache()->deleteMultiple([]));
    }

    /**
     * A closure is the value `Amp\Serialization\NativeSerializer`
     * refuses, and the serializer contract declares
     * `SerializationException` for it. Callers are promised one failure
     * vocabulary, so it must reach them as the PSR-16 exception naming
     * the operation.
     */
    public function test_set_reports_a_value_it_cannot_encode(): void
    {
        try {
            $this->cache()->set('unencodable', static fn (): int => 1);
            self::fail('set() accepted a value the serializer cannot encode');
        } catch (CacheException $e) {
            self::assertStringContainsString('Redis "set" failed', $e->getMessage());
            self::assertInstanceOf(SerializationException::class, $e->getPrevious());
        }
    }

    public function test_replace_reports_a_value_it_cannot_encode(): void
    {
        try {
            $this->cache()->replace('unencodable', static fn (): int => 1, 60);
            self::fail('replace() accepted a value the serializer cannot encode');
        } catch (CacheException $e) {
            self::assertStringContainsString('Redis "replace" failed', $e->getMessage());
            self::assertInstanceOf(SerializationException::class, $e->getPrevious());
        }
    }

    public function test_a_supplied_disposer_runs_exactly_once(): void
    {
        $closes = 0;
        $cache = new RedisSimpleCache($this->client(), disposer: static function () use (&$closes): void {
            ++$closes;
        });

        $cache->dispose();
        $cache->dispose();

        self::assertSame(1, $closes);
    }

    /**
     * The caller lent the executor and kept its close, so disposal ends
     * nothing: the link the caller's client already built survives.
     */
    public function test_a_directly_constructed_cache_without_a_disposer_closes_nothing(): void
    {
        $client = $this->client();
        $client->link();
        $cache = new RedisSimpleCache($client);

        $cache->dispose();

        self::assertNotNull(self::link($client));
    }

    public function test_from_config_hands_the_cache_its_executors_close(): void
    {
        $cache = RedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost']));
        self::assertNotNull($cache);
        $client = new ReflectionProperty(RedisSimpleCache::class, 'client')->getValue($cache);
        self::assertInstanceOf(Client::class, $client);
        $client->link();

        $cache->dispose();

        self::assertNull(self::link($client), 'disposal closed the executor fromConfig() opened');
    }

    private static function link(Client $client): mixed
    {
        return new ReflectionProperty(Client::class, 'link')->getValue($client);
    }

    private function cache(): RedisSimpleCache
    {
        return new RedisSimpleCache($this->client());
    }

    private function client(): Client
    {
        return Client::create(Endpoint::parse('127.0.0.1:6379'), new ClientOptions());
    }
}
