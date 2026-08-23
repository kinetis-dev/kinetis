<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisException;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;
use Kinetis\SimpleCache\Exception\CacheException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ClusteredRedisSimpleCacheTest extends TestCase
{
    public function test_from_config_returns_null_when_cluster_mode_is_not_enabled(): void
    {
        self::assertNull(ClusteredRedisSimpleCache::fromConfig(new Config([])));
    }

    public function test_from_config_returns_null_when_only_single_node_redis_is_configured(): void
    {
        self::assertNull(ClusteredRedisSimpleCache::fromConfig(new Config(['REDIS_HOST' => 'localhost'])));
    }

    public function test_from_config_throws_when_cluster_mode_is_enabled_with_no_seeds(): void
    {
        $this->expectException(MissingConfigException::class);

        ClusteredRedisSimpleCache::fromConfig(new Config(['REDIS_CLUSTER' => 'true']));
    }

    public function test_from_config_returns_an_instance_when_seeds_are_given(): void
    {
        // Building the topology's client factory never connects eagerly —
        // the same laziness RedisSimpleCache::fromConfig() relies on — so
        // this is safe with no real cluster reachable. Actual slot
        // routing/MOVED handling is verified separately against a real
        // 6-node cluster (not part of this suite).
        $cache = ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001, node2:7002 ,node3:7003',
        ]));

        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
    }

    public function test_from_config_respects_a_named_connection(): void
    {
        self::assertNull(ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001',
        ]), connection: 'other'));

        $cache = ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_OTHER_CLUSTER' => 'true',
            'REDIS_OTHER_CLUSTER_SEEDS' => 'node1:7001',
        ]), connection: 'other');

        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
    }

    /**
     * guard()'s MOVED-then-refresh-then-retry happy path needs a real
     * cluster to genuinely exercise (ClusterTopology::refresh() can't be
     * faked — it's a real network call and both classes involved are
     * final) — that path is verified by hand against a live 6-node
     * cluster, not part of this suite. What's directly testable here,
     * with no network at all, is guard()'s two exception-wrapping
     * branches, neither of which ever touches the topology.
     */
    public function test_guard_wraps_a_non_moved_query_exception_as_a_cache_exception(): void
    {
        $cache = $this->cacheWithUnreachableSeed();
        $guard = new ReflectionMethod($cache, 'guard');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            'Redis "get" failed for key "some-key": WRONGTYPE Operation against a key holding the wrong kind of value',
        );

        $guard->invoke($cache, 'get', 'some-key', function (): never {
            throw new QueryException('WRONGTYPE Operation against a key holding the wrong kind of value');
        });
    }

    public function test_guard_wraps_a_generic_redis_exception_as_a_cache_exception(): void
    {
        $cache = $this->cacheWithUnreachableSeed();
        $guard = new ReflectionMethod($cache, 'guard');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis "get" failed for key "some-key": Connection lost');

        $guard->invoke($cache, 'get', 'some-key', function (): never {
            throw new RedisException('Connection lost');
        });
    }

    private function cacheWithUnreachableSeed(): ClusteredRedisSimpleCache
    {
        $cache = ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001',
        ]));

        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);

        return $cache;
    }
}
