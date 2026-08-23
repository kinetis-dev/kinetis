<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Integration;

use Kinetis\Config\Config;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;
use PHPUnit\Framework\TestCase;

/**
 * ClusteredRedisSimpleCache against a real Redis Cluster.
 *
 * Everything here needs more than one node to mean anything: a key
 * landing on the right shard, a multi-key operation that must never
 * become a cross-slot command, and a clear() that has to reach every
 * master rather than the one it happens to be connected to. None of it
 * can be faked, which is why this class has no unit tests beyond its
 * slot arithmetic and config parsing.
 *
 * Environment-gated on REDIS_CLUSTER_SEEDS.
 */
final class ClusteredRedisSimpleCacheIntegrationTest extends TestCase
{
    private ClusteredRedisSimpleCache $cache;

    protected function setUp(): void
    {
        $seeds = \getenv('REDIS_CLUSTER_SEEDS');

        if ($seeds === false || $seeds === '') {
            self::markTestSkipped('REDIS_CLUSTER_SEEDS is not set — real-cluster tests are environment-gated.');
        }

        $cache = ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => $seeds,
        ]));

        self::assertNotNull($cache, 'fromConfig() returned null despite REDIS_CLUSTER_SEEDS being set');

        $this->cache = $cache;
        $this->cache->clear();
    }

    /**
     * @return list<string>
     */
    private function spreadKeys(int $count = 50): array
    {
        return array_map(static fn (int $i): string => 'cluster-key-' . $i, range(0, $count - 1));
    }

    /**
     * The precondition for everything else: keys that all landed on one
     * slot would make the rest of this file pass without testing
     * anything about clustering.
     */
    public function test_the_keys_used_here_really_do_span_more_than_one_slot(): void
    {
        $slots = [];

        foreach ($this->spreadKeys() as $key) {
            $slots[Crc16::slotFor($key)] = true;
        }

        self::assertGreaterThan(1, count($slots), 'every key hashed to one slot; the cluster tests would be vacuous');
    }

    public function test_every_key_round_trips_across_the_nodes_that_own_it(): void
    {
        foreach ($this->spreadKeys() as $i => $key) {
            $this->cache->set($key, 'value-' . $i);
        }

        foreach ($this->spreadKeys() as $i => $key) {
            self::assertSame('value-' . $i, $this->cache->get($key), "{$key} did not round-trip");
        }
    }

    public function test_has_reflects_what_is_stored(): void
    {
        $this->cache->set('cluster-key-0', 'x');

        self::assertTrue($this->cache->has('cluster-key-0'));
        self::assertFalse($this->cache->has('cluster-key-missing'));
    }

    /**
     * Redis Cluster rejects any multi-key command whose keys do not share
     * a slot, so these dispatch one command per key. Reading keys that
     * demonstrably span slots is what proves no batched MGET/DEL slipped
     * back in — a CROSSSLOT error would surface here as a failure.
     */
    public function test_multiple_operations_span_slots_without_a_cross_slot_command(): void
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

    public function test_a_ttl_expires_on_its_own(): void
    {
        $this->cache->set('ttl-key', 'x', 1);
        self::assertTrue($this->cache->has('ttl-key'));

        sleep(2);

        self::assertFalse($this->cache->has('ttl-key'));
    }

    /**
     * A single node's FLUSHDB clears only its own shard, so this fails
     * unless clear() fans out to every master. It uses the full key
     * spread rather than a handful: a few keys can miss a shard's slot
     * range entirely, and a shard holding nothing proves nothing about
     * whether clear() reached it.
     */
    public function test_clear_wipes_every_shard(): void
    {
        $keys = $this->spreadKeys();

        foreach ($keys as $key) {
            $this->cache->set($key, 'x');
        }

        self::assertTrue($this->cache->clear());

        foreach ($keys as $key) {
            self::assertFalse($this->cache->has($key), "{$key} survived clear() — a shard was missed");
        }
    }

    public function test_a_missing_key_returns_the_given_default(): void
    {
        self::assertSame('fallback', $this->cache->get('cluster-key-absent', 'fallback'));
    }

    /**
     * One key, so the script must run on whichever node owns that key's
     * slot. Sending it anywhere else is a MOVED, not a wrong answer, so
     * this fails loudly if the routing is wrong.
     */
    public function test_a_counter_is_incremented_on_the_node_that_owns_its_slot(): void
    {
        $key = 'counter-' . \bin2hex(\random_bytes(6));

        self::assertSame(0, $this->cache->count($key));
        self::assertSame(1, $this->cache->increment($key, 60));
        self::assertSame(2, $this->cache->increment($key, 60));
        self::assertSame(2, $this->cache->count($key));
    }

    public function test_counters_on_different_slots_are_independent(): void
    {
        $keys = [];

        // Deliberately spread: without slot-aware routing these would
        // collide on whichever node the client happened to hold.
        for ($i = 0; $i < 12; $i++) {
            $keys[] = 'counter-spread-' . $i . '-' . \bin2hex(\random_bytes(4));
        }

        foreach ($keys as $index => $key) {
            for ($n = 0; $n <= $index; $n++) {
                $this->cache->increment($key, 60);
            }
        }

        foreach ($keys as $index => $key) {
            self::assertSame($index + 1, $this->cache->count($key), $key);
        }
    }

    /**
     * One key, so the script must run on whichever node owns that key's
     * slot — the same routing the counter test above proves.
     */
    public function test_a_key_is_consumed_on_the_node_that_owns_its_slot(): void
    {
        $key = 'consume-' . \bin2hex(\random_bytes(6));
        $this->cache->set($key, 'the-only-copy');

        self::assertSame('the-only-copy', $this->cache->consume($key, 'missed-it'));
        self::assertSame('missed-it', $this->cache->consume($key, 'missed-it'), 'the key must already be gone');
    }
}
