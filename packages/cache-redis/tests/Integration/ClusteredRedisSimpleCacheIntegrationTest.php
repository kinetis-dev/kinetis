<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Integration;

use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Amp\Serialization\NativeSerializer;
use Kinetis\Config\Config;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;
use Kinetis\SimpleCache\Exception\CacheException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function Amp\Redis\createRedisClient;
use function Kinetis\Async\concurrently;

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
    /** Reserved slots are spaced across one master's original range. */
    private const int TEST_SLOT_OFFSET_A = 100;
    private const int TEST_SLOT_OFFSET_B = 700;
    private const int TEST_SLOT_OFFSET_C = 1300;
    private const int TEST_SLOT_OFFSET_D = 1900;
    private const int TEST_SLOT_OFFSET_E = 2500;
    private const int TEST_SLOT_OFFSET_F = 3100;
    private const int TEST_SLOT_OFFSET_G = 3700;
    private const int TEST_SLOT_OFFSET_H = 4300;

    /** @var array<string, int> original contiguous-range start by master id */
    private static array $originalMasterRangeStarts = [];

    private ClusteredRedisSimpleCache $cache;

    /**
     * @var array<int, array{
     *     original: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>},
     *     current: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}
     * }>
     */
    private array $changedSlots = [];

    public static function setUpBeforeClass(): void
    {
        $seeds = \getenv('REDIS_CLUSTER_SEEDS');

        if ($seeds === false || $seeds === '') {
            return;
        }

        $masters = self::discoverMasters();

        foreach ($masters as $master) {
            self::$originalMasterRangeStarts[$master['id']] = $master['ranges'][0][0];
        }
    }

    /**
     * Resolve a reserved empty slot from the master's initial range.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $owner
     */
    private function reservedSlotFor(array $owner, int $offset): int
    {
        self::assertArrayHasKey(
            $owner['id'],
            self::$originalMasterRangeStarts,
            'The master was not present when the test class initialized.',
        );

        return self::$originalMasterRangeStarts[$owner['id']] + $offset;
    }

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

    protected function tearDown(): void
    {
        if ($this->changedSlots === []) {
            return;
        }

        $masters = self::discoverMasters();

        foreach ($masters as $master) {
            $this->adminClientFor($master)->execute('FLUSHDB');
        }

        foreach ($this->changedSlots as $slot => $owners) {
            foreach ($masters as $master) {
                $this->adminClientFor($master)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'STABLE');
            }

            if ($owners['current']['id'] !== $owners['original']['id']) {
                $this->moveEmptySlot($owners['current'], $owners['original'], $slot, $masters);
            }
        }

        $this->changedSlots = [];
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

    /** A migrated key produces a real ASK reply while ownership stays with the source. */
    public function test_a_real_ask_redirect_is_followed_to_the_correct_value(): void
    {
        [$source, $target] = $this->requireTwoDistinctMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->findKeyInSlot($slot);

        $this->cache->set($key, 'ask-redirect-value');

        $this->beginMigration($source, $target, $slot, $key);

        self::assertSame(
            'ASK',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($source)->get($key)),
            'the source node must reply ASK for the migrated key',
        );

        self::assertSame('ask-redirect-value', $this->cache->get($key, 'MISSING'));
    }

    /** A cache with a stale slot map follows MOVED and refreshes its topology. */
    public function test_a_real_moved_redirect_triggers_a_topology_refresh_and_succeeds(): void
    {
        [$oldOwner, $newOwner] = $this->requireTwoDistinctMasters();
        // A widely-spaced offset — see the TEST_SLOT_OFFSET_* docblock
        // for why reservations are kept 600 slots apart.
        $slot = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_A);
        $key = $this->findKeyInSlot($slot);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // force discovery of the current (about-to-be-stale) topology

        $this->assignSlot($oldOwner, $newOwner, $slot);

        self::assertSame(
            'MOVED',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
            'the old owner must reply MOVED before the cache retries',
        );

        $start = microtime(true);
        $staleCache->set($key, 'moved-redirect-value');
        $elapsed = microtime(true) - $start;

        $raw = $this->adminClientFor($newOwner)->get($key);
        self::assertNotNull($raw, 'the value must be stored on the new owner');
        self::assertSame('moved-redirect-value', (new NativeSerializer())->unserialize($raw));
        self::assertLessThan(2.0, $elapsed, 'a MOVED refresh and retry should complete well under two seconds');
    }

    /** The MOVED target is used directly even when the cache's seed snapshot is stale. */
    public function test_a_real_moved_redirect_succeeds_from_a_stale_cached_topology(): void
    {
        [$oldOwner, $newOwner, $staleSeed] = $this->requireThreeDistinctMasters();
        $slot = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_B);
        $key = $this->findKeyInSlot($slot);

        $config = new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => "{$staleSeed['host']}:{$staleSeed['port']},{$oldOwner['host']}:{$oldOwner['port']},{$newOwner['host']}:{$newOwner['port']}",
        ]);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($config);
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // discovers via seed[0] = $staleSeed specifically: $oldOwner owns $slot

        $this->assignSlot($oldOwner, $newOwner, $slot);

        self::assertSame(
            'MOVED',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
            'the old owner must reply MOVED before the cache retries',
        );

        $start = microtime(true);
        $staleCache->set($key, 'immediate-moved-value');
        $elapsed = microtime(true) - $start;

        $raw = $this->adminClientFor($newOwner)->get($key);
        self::assertNotNull($raw);
        self::assertSame('immediate-moved-value', (new NativeSerializer())->unserialize($raw));
        self::assertLessThan(2.0, $elapsed, 'a direct MOVED retry should complete well under two seconds');
    }

    /** clear() includes a target learned from a MOVED reply. */
    public function test_clear_after_a_moved_routed_write_does_not_leave_data_behind(): void
    {
        [$oldOwner, $newOwner, $staleSeed] = $this->requireThreeDistinctMasters();
        $slot = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_C);
        $key = $this->findKeyInSlot($slot);

        $config = new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => "{$staleSeed['host']}:{$staleSeed['port']},{$oldOwner['host']}:{$oldOwner['port']},{$newOwner['host']}:{$newOwner['port']}",
        ]);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($config);
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // discovers via seed[0] = $staleSeed: $oldOwner owns $slot

        $this->assignSlot($oldOwner, $newOwner, $slot);

        self::assertSame(
            'MOVED',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
            'the old owner must reply MOVED before the cache retries',
        );

        $staleCache->set($key, 'must-not-survive-clear');
        self::assertNotNull($this->adminClientFor($newOwner)->get($key));

        self::assertTrue($staleCache->clear());
        self::assertNull(
            $this->adminClientFor($newOwner)->get($key),
            'clear() left a value on a target learned through MOVED',
        );
    }

    /** Two independent MOVED targets remain usable until clear() reaches both. */
    public function test_two_slots_moved_to_different_targets_remain_usable_until_clear(): void
    {
        [$oldOwner, $target1, $target2] = $this->requireThreeDistinctMasters();
        $slot1 = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_D);
        $slot2 = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_E);
        $key1 = $this->findKeyInSlot($slot1);
        $key2 = $this->findKeyInSlot($slot2);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key1);

        $this->assignSlot($oldOwner, $target1, $slot1);
        $this->assignSlot($oldOwner, $target2, $slot2);

        self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key1)));
        self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key2)));

        $staleCache->set($key1, 'slot1-value');
        $staleCache->set($key2, 'slot2-value');

        self::assertSame('slot1-value', $staleCache->get($key1));
        self::assertSame('slot2-value', $staleCache->get($key2));
        self::assertTrue($staleCache->clear());
        self::assertNull($this->adminClientFor($target1)->get($key1), 'clear() must reach target1');
        self::assertNull($this->adminClientFor($target2)->get($key2), 'clear() must reach target2');
    }

    /** One operation follows MOVED to a new owner and ASK to its migration target. */
    public function test_a_real_moved_then_ask_sequence_is_followed_correctly_in_one_call(): void
    {
        [$original, $intermediate, $final] = $this->requireThreeDistinctMasters();
        $slot = $this->reservedSlotFor($original, self::TEST_SLOT_OFFSET_F);
        $key = $this->findKeyInSlot($slot);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key);

        $this->assignSlot($original, $intermediate, $slot);

        $freshCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $freshCache);
        $freshCache->set($key, 'sequence-final-value');

        $this->beginMigration($intermediate, $final, $slot, $key);

        self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($original)->get($key)));
        self::assertSame('ASK', $this->firstWordOfReplyError(fn () => $this->adminClientFor($intermediate)->get($key)));
        self::assertSame('sequence-final-value', $staleCache->get($key, 'MISSING'));
    }

    /** A failed ASK retry does not remove a durable MOVED override for another slot. */
    public function test_a_generic_failure_on_an_ask_targets_retry_does_not_erase_a_separate_slots_durable_overlay(): void
    {
        [$stableOldOwner, $stableNewOwner, $migrationSource] = $this->requireThreeDistinctMasters();
        $stableSlot = $this->reservedSlotFor($stableOldOwner, self::TEST_SLOT_OFFSET_G);
        $stableKey = $this->findKeyInSlot($stableSlot);

        $cache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
        $cache->has($stableKey);
        $this->assignSlot($stableOldOwner, $stableNewOwner, $stableSlot);

        $migrationSlot = $this->reservedSlotFor($migrationSource, self::TEST_SLOT_OFFSET_H);
        $migrationKey = $this->findKeyInSlot($migrationSlot);
        $this->adminClientFor($migrationSource)->set($migrationKey, 'irrelevant-migrating-value');

        self::assertSame(
            'MOVED',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($stableOldOwner)->get($stableKey)),
        );

        $cache->set($stableKey, 'stable-value');
        self::assertSame('stable-value', $cache->get($stableKey));

        $this->beginMigration($migrationSource, $stableOldOwner, $migrationSlot, $migrationKey);
        self::assertSame(
            'ASK',
            $this->firstWordOfReplyError(fn () => $this->adminClientFor($migrationSource)->get($migrationKey)),
        );

        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $attempts = 0;

        try {
            $guardKeyed->invoke($cache, 'get', $migrationKey, function (RedisClient $client) use (&$attempts, $migrationKey): mixed {
                $attempts++;

                if ($attempts === 1) {
                    return $client->get($migrationKey);
                }

                throw new RedisException('Simulated connection loss right after ASKING');
            });

            self::fail('Expected a CacheException from the simulated post-ASKING failure.');
        } catch (CacheException $e) {
            self::assertStringContainsString('Simulated connection loss right after ASKING', $e->getMessage());
        }

        self::assertSame(2, $attempts);
        self::assertSame('stable-value', $cache->get($stableKey));
        self::assertTrue($cache->clear());
        self::assertNull($this->adminClientFor($stableNewOwner)->get($stableKey));
    }

    /** ASKING and its retry remain isolated from concurrent commands. */
    public function test_a_real_ask_redirect_survives_concurrent_unrelated_traffic_on_the_same_nodes(): void
    {
        [$source, $target] = $this->requireTwoDistinctMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->findKeyInSlot($slot);

        $this->cache->set($key, 'concurrent-ask-value');

        $unrelatedOnSource = [];
        $unrelatedOnTarget = [];

        for ($i = 0; $i < 15; $i++) {
            $sourceKey = $this->findKeyOwnedBy($source, excluding: $slot);
            $targetKey = $this->findKeyOwnedBy($target);
            $this->cache->set($sourceKey, "source-value-{$i}");
            $this->cache->set($targetKey, "target-value-{$i}");
            $unrelatedOnSource[$sourceKey] = "source-value-{$i}";
            $unrelatedOnTarget[$targetKey] = "target-value-{$i}";
        }

        $this->beginMigration($source, $target, $slot, $key);

        $tasks = ['ask-target' => fn () => $this->cache->get($key, 'MISSING')];

        foreach ($unrelatedOnSource as $k => $expected) {
            $tasks["source:{$k}"] = fn () => $this->cache->get($k);
        }
        foreach ($unrelatedOnTarget as $k => $expected) {
            $tasks["target:{$k}"] = fn () => $this->cache->get($k);
        }

        $names = array_keys($tasks);
        $results = concurrently(array_values($tasks));
        $byName = array_combine($names, $results);

        self::assertSame('concurrent-ask-value', $byName['ask-target']);

        foreach ($unrelatedOnSource as $k => $expected) {
            self::assertSame($expected, $byName["source:{$k}"], "unrelated source key {$k} was corrupted by concurrent ASK handling");
        }
        foreach ($unrelatedOnTarget as $k => $expected) {
            self::assertSame($expected, $byName["target:{$k}"], "unrelated target key {$k} was corrupted by concurrent ASK handling");
        }
    }

    /** A repeated ASK target is rejected after the second redirect. */
    public function test_a_repeated_redirect_to_a_reachable_target_is_caught_as_a_loop(): void
    {
        [$source, $target] = $this->requireTwoDistinctMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->findKeyInSlot($slot);
        $calls = 0;

        $this->cache->set($key, 'never-reached');

        $this->beginMigration($source, $target, $slot, $key);

        self::assertSame('ASK', $this->firstWordOfReplyError(fn () => $this->adminClientFor($source)->get($key)));

        $guardKeyed = new ReflectionMethod($this->cache, 'guardKeyed');
        $askMessage = "ASK {$slot} {$target['host']}:{$target['port']}";

        try {
            $guardKeyed->invoke($this->cache, 'get', $key, function (RedisClient $client) use (&$calls, $askMessage): never {
                $calls++;

                throw new QueryException($askMessage);
            });

            self::fail('Expected a CacheException for the repeated redirect.');
        } catch (CacheException $e) {
            self::assertStringContainsString(
                "Redirect loop detected: ASK to {$target['host']}:{$target['port']}",
                $e->getMessage(),
            );
        }

        self::assertSame(2, $calls);
    }

    /**
     * @return list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}>
     */
    private static function discoverMasters(): array
    {
        $seeds = explode(',', (string) getenv('REDIS_CLUSTER_SEEDS'));
        $admin = createRedisClient(RedisConfig::fromUri('tcp://' . trim($seeds[0])));
        $masters = [];

        foreach ($admin->execute('CLUSTER', 'SHARDS') as $shard) {
            $shardMap = self::pairsToAssoc($shard);
            $ranges = [];

            for ($i = 0, $c = count($shardMap['slots']); $i < $c; $i += 2) {
                $ranges[] = [$shardMap['slots'][$i], $shardMap['slots'][$i + 1]];
            }

            foreach ($shardMap['nodes'] as $node) {
                $nodeMap = self::pairsToAssoc($node);

                if ($nodeMap['role'] !== 'master') {
                    continue;
                }

                $masters[] = [
                    'id' => $nodeMap['id'],
                    'host' => $nodeMap['ip'],
                    'port' => $nodeMap['port'],
                    'ranges' => $ranges,
                ];
            }
        }

        usort($masters, static fn (array $a, array $b): int => $a['ranges'][0][0] <=> $b['ranges'][0][0]);

        return $masters;
    }

    /**
     * @param list<mixed> $pairs
     * @return array<string, mixed>
     */
    private static function pairsToAssoc(array $pairs): array
    {
        $map = [];

        for ($i = 0, $c = count($pairs); $i < $c; $i += 2) {
            $map[$pairs[$i]] = $pairs[$i + 1];
        }

        return $map;
    }

    /**
     * @return array{0: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}, 1: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}}
     */
    private function requireTwoDistinctMasters(): array
    {
        $masters = self::discoverMasters();

        if (count($masters) < 2) {
            self::markTestSkipped('Needs at least 2 real master nodes to exercise a real slot redirect.');
        }

        return [$masters[0], $masters[1]];
    }

    /**
     * @return array{0: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}, 1: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}, 2: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}}
     */
    private function requireThreeDistinctMasters(): array
    {
        $masters = self::discoverMasters();

        if (count($masters) < 3) {
            self::markTestSkipped('Needs at least 3 real master nodes to exercise a real MOVED-then-ASK sequence.');
        }

        return [$masters[0], $masters[1], $masters[2]];
    }

    private function findKeyInSlot(int $slot): string
    {
        return $this->findKeyInRange($slot, $slot);
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $master
     */
    private function findKeyOwnedBy(array $master, ?int $excluding = null): string
    {
        foreach ($master['ranges'] as [$start, $end]) {
            if ($start === $end && $start === $excluding) {
                continue;
            }

            try {
                return $this->findKeyInRange($start, $end, $excluding);
            } catch (RuntimeException) {
                continue;
            }
        }

        throw new RuntimeException(
            "Could not find any key owned by master {$master['id']}" . ($excluding === null ? '' : " excluding slot {$excluding}") . '.',
        );
    }

    private function findKeyInRange(int $start, int $end, ?int $excluding = null): string
    {
        // A single-slot search has a 1-in-16384 match probability per attempt.
        for ($i = 0; $i < 300000; $i++) {
            $key = 'redirect-test-' . bin2hex(random_bytes(6));
            $candidateSlot = Crc16::slotFor($key);

            if ($candidateSlot >= $start && $candidateSlot <= $end && $candidateSlot !== $excluding) {
                return $key;
            }
        }

        throw new RuntimeException("Could not find a key hashing into slots {$start}-{$end}.");
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $master
     */
    private function adminClientFor(array $master): RedisClient
    {
        return createRedisClient(RedisConfig::fromUri("tcp://{$master['host']}:{$master['port']}"));
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $source
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $target
     */
    private function beginMigration(array $source, array $target, int $slot, string $key): void
    {
        $this->rememberSlot($source, $slot);

        $this->adminClientFor($target)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'IMPORTING', $source['id']);
        $this->adminClientFor($source)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'MIGRATING', $target['id']);
        $this->adminClientFor($source)->execute(
            'MIGRATE',
            $target['host'],
            (string) $target['port'],
            $key,
            '0',
            '5000',
            'REPLACE',
        );
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $oldOwner
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $newOwner
     */
    private function assignSlot(array $oldOwner, array $newOwner, int $slot): void
    {
        $this->rememberSlot($oldOwner, $slot);
        $this->moveEmptySlot($oldOwner, $newOwner, $slot);
        $this->changedSlots[$slot]['current'] = $newOwner;
    }

    /**
     * Apply Redis's documented live-resharding sequence and notify every master.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $source
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $target
     * @param list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}>|null $masters
     */
    private function moveEmptySlot(
        array $source,
        array $target,
        int $slot,
        ?array $masters = null,
    ): void {
        $masters ??= self::discoverMasters();
        $targetClient = $this->adminClientFor($target);
        $sourceClient = $this->adminClientFor($source);

        $targetClient->execute('CLUSTER', 'SETSLOT', (string) $slot, 'IMPORTING', $source['id']);
        $sourceClient->execute('CLUSTER', 'SETSLOT', (string) $slot, 'MIGRATING', $target['id']);
        $targetClient->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);

        foreach ($masters as $master) {
            if ($master['id'] === $target['id']) {
                continue;
            }

            $this->adminClientFor($master)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);
        }
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $owner
     */
    private function rememberSlot(array $owner, int $slot): void
    {
        $this->changedSlots[$slot] ??= [
            'original' => $owner,
            'current' => $owner,
        ];
    }

    private function firstWordOfReplyError(callable $operation): string
    {
        try {
            $operation();

            throw new RuntimeException('Expected a redirect reply, got a successful response.');
        } catch (QueryException $e) {
            $firstSpace = strpos($e->getMessage(), ' ');

            return $firstSpace === false ? $e->getMessage() : substr($e->getMessage(), 0, $firstSpace);
        }
    }

    private function clusterConfig(): Config
    {
        return new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => (string) getenv('REDIS_CLUSTER_SEEDS'),
        ]);
    }
}
