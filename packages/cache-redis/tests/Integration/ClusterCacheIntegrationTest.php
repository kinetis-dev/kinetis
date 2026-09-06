<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\Endpoint;
use Kinetis\SimpleCache\RedisSimpleCache;
use PHPUnit\Framework\TestCase;

/**
 * The cache against a real Redis Cluster.
 *
 * What needs more than one node: keys landing on the shard that owns
 * them, a multi-key operation that must never become one cross-slot
 * command, and a clear() that has to scan every current master while
 * leaving keys this cache did not write alone. Routing itself is proven
 * in kinetis/redis's own cluster suite.
 *
 * Environment-gated on REDIS_CLUSTER_SEEDS.
 */
final class ClusterCacheIntegrationTest extends TestCase
{
    private RedisSimpleCache $cache;

    protected function setUp(): void
    {
        $seeds = (string) getenv('REDIS_CLUSTER_SEEDS');

        if ($seeds === '') {
            self::markTestSkipped('REDIS_CLUSTER_SEEDS is not set — real-cluster tests are environment-gated.');
        }

        $cache = RedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => $seeds,
        ]));

        self::assertNotNull($cache, 'fromConfig() returned null despite REDIS_CLUSTER_SEEDS being set');

        $this->cache = $cache;
        $this->cache->clear();
    }

    public function test_keys_spanning_slots_round_trip(): void
    {
        foreach ($this->spreadKeys() as $i => $key) {
            self::assertTrue($this->cache->set($key, "value-{$i}"));
        }

        foreach ($this->spreadKeys() as $i => $key) {
            self::assertSame("value-{$i}", $this->cache->get($key), "{$key} did not round-trip");
        }
    }

    /**
     * Redis Cluster rejects a multi-key command whose keys do not share a
     * slot, so a batched MGET or DEL slipping back in surfaces here as a
     * CROSSSLOT failure.
     */
    public function test_bulk_operations_span_slots_without_one_cross_slot_command(): void
    {
        $keys = $this->spreadKeys(20);

        foreach ($keys as $i => $key) {
            $this->cache->set($key, $i);
        }

        $read = iterator_to_array($this->cache->getMultiple($keys));

        foreach ($keys as $i => $key) {
            self::assertSame($i, $read[$key]);
        }

        self::assertTrue($this->cache->deleteMultiple($keys));

        foreach ($keys as $key) {
            self::assertFalse($this->cache->has($key), "{$key} survived deleteMultiple()");
        }
    }

    public function test_counters_and_consumes_run_on_the_node_that_owns_their_key(): void
    {
        $counter = 'counter-' . bin2hex(random_bytes(6));

        self::assertSame(1, $this->cache->increment($counter, 60));
        self::assertSame(2, $this->cache->increment($counter, 60));
        self::assertSame(2, $this->cache->count($counter));

        $once = 'consume-' . bin2hex(random_bytes(6));
        $this->cache->set($once, 'the-only-copy');

        self::assertSame('the-only-copy', $this->cache->consume($once, 'missed-it'));
        self::assertSame('missed-it', $this->cache->consume($once, 'missed-it'));
    }

    /**
     * A scan of one master would leave the other shards' keys behind, and
     * a FLUSHDB would take the foreign key with it.
     */
    public function test_clear_scans_every_master_and_leaves_other_keys_alone(): void
    {
        $keys = $this->spreadKeys();

        foreach ($keys as $key) {
            $this->cache->set($key, 'x');
        }

        $cluster = ClusterClient::create(
            array_map(Endpoint::parse(...), array_map(trim(...), explode(',', (string) getenv('REDIS_CLUSTER_SEEDS')))),
            new ClientOptions(timeout: 10.0),
        );
        $foreign = 'not-the-caches-' . bin2hex(random_bytes(6));
        $cluster->executeKeyed($foreign, 'SET', $foreign, 'survives');

        self::assertTrue($this->cache->clear());

        foreach ($keys as $key) {
            self::assertFalse($this->cache->has($key), "{$key} survived clear() — a master was missed");
        }

        self::assertSame('survives', $cluster->executeKeyed($foreign, 'GET', $foreign));

        $cluster->executeKeyed($foreign, 'DEL', $foreign);
        $cluster->close();
    }

    /** @return list<string> */
    private function spreadKeys(int $count = 50): array
    {
        return array_map(static fn (int $i): string => "cluster-key-{$i}", range(0, $count - 1));
    }
}
