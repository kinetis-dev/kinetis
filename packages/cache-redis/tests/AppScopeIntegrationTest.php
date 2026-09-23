<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClusterClient;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;

/**
 * The "kinetis/cache-redis is actually installed" half of
 * AppScope::boot()'s CacheInterface wiring — the counterpart to core's
 * own AppScopeTest tests proving the class_exists()-gated
 * SimpleCacheUnavailableException fires correctly when this package is
 * *not* installed. Only this package has both AppScope and
 * RedisSimpleCache simultaneously available (it depends on
 * kinetis/framework; core never depends the other way), so this is the
 * one place the real "configured -> concrete class bound" path, and who
 * disposes the bound cache, can be proven end-to-end without a real
 * Redis server: fromConfig() never connects eagerly.
 */
final class AppScopeIntegrationTest extends TestCase
{
    public function test_boot_registers_a_redis_backed_simple_cache_when_redis_is_configured(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'localhost']));
        $app->boot();

        self::assertInstanceOf(RedisSimpleCache::class, $app->get(CacheInterface::class));
    }

    public function test_boot_registers_a_cluster_backed_simple_cache_when_redis_cluster_is_configured(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001,node2:7002',
        ]));
        $app->boot();

        self::assertInstanceOf(RedisSimpleCache::class, $app->get(CacheInterface::class));
    }

    public function test_disposing_the_scope_closes_the_default_caches_executor(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'localhost']));
        $app->boot();
        $client = self::executor($app->get(CacheInterface::class));
        self::assertInstanceOf(Client::class, $client);
        $client->link();

        $app->dispose();

        self::assertNull(new ReflectionProperty(Client::class, 'link')->getValue($client));
    }

    /**
     * The application built and bound this cache, so the scope leaves
     * its executor open when it ends.
     */
    public function test_disposing_the_scope_leaves_a_pre_bound_cache_open(): void
    {
        $cache = RedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost']));
        self::assertNotNull($cache);
        $client = self::executor($cache);
        self::assertInstanceOf(Client::class, $client);
        $client->link();

        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'localhost']));
        $app->instance(CacheInterface::class, $cache);
        $app->boot();
        $app->dispose();

        self::assertNotNull(new ReflectionProperty(Client::class, 'link')->getValue($client));
        self::assertNotNull(new ReflectionProperty(RedisSimpleCache::class, 'disposer')->getValue($cache));

        $cache->dispose();
    }

    /**
     * `.invalid` never resolves, so any dial during disposal would throw.
     * The disposer has run, and the client still holds no link.
     */
    public function test_disposing_an_unused_cache_on_an_unreachable_host_neither_connects_nor_throws(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['REDIS_HOST' => 'redis.invalid']));
        $app->boot();
        $cache = $app->get(CacheInterface::class);
        $client = self::executor($cache);
        self::assertInstanceOf(Client::class, $client);

        $app->dispose();

        self::assertNull(new ReflectionProperty(RedisSimpleCache::class, 'disposer')->getValue($cache));
        self::assertNull(new ReflectionProperty(Client::class, 'link')->getValue($client));
    }

    public function test_disposing_an_unused_cache_on_an_unreachable_cluster_seed_neither_connects_nor_throws(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'redis.invalid:7001',
        ]));
        $app->boot();
        $cache = $app->get(CacheInterface::class);
        $cluster = self::executor($cache);
        self::assertInstanceOf(ClusterClient::class, $cluster);

        $app->dispose();

        self::assertNull(new ReflectionProperty(RedisSimpleCache::class, 'disposer')->getValue($cache));
        self::assertSame([], new ReflectionProperty(ClusterClient::class, 'clients')->getValue($cluster));
    }

    private static function executor(mixed $cache): mixed
    {
        self::assertInstanceOf(RedisSimpleCache::class, $cache);

        return new ReflectionProperty(RedisSimpleCache::class, 'client')->getValue($cache);
    }
}
