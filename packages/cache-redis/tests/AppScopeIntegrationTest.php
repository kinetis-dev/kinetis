<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * The "kinetis/cache-redis is actually installed" half of
 * AppScope::boot()'s CacheInterface wiring — the counterpart to core's
 * own AppScopeTest tests proving the class_exists()-gated
 * SimpleCacheUnavailableException fires correctly when this package is
 * *not* installed. Only this package has both AppScope and
 * RedisSimpleCache/ClusteredRedisSimpleCache simultaneously available
 * (it depends on kinetis/kinetis; core never depends the other way), so
 * this is the one place the real "configured -> concrete class bound"
 * path can be proven end-to-end without a real Redis server (both
 * classes' own fromConfig() never connects eagerly).
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

        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $app->get(CacheInterface::class));
    }
}
