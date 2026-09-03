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

    /**
     * A real ASK reply — a specific key already migrated to another
     * node while its slot's *stable* owner (per CLUSTER SHARDS) hasn't
     * changed yet — is what guardKeyed() must never treat as an
     * ordinary failure. Forced here via the real, documented live-
     * migration sequence (IMPORTING on the target, MIGRATING on the
     * source, then MIGRATE moving the key's actual bytes), not
     * simulated: the source genuinely replies ASK for this exact key
     * once it's gone, which is confirmed directly before ever calling
     * back into the cache.
     */
    public function test_a_real_ask_redirect_is_followed_to_the_correct_value(): void
    {
        [$source, $target] = $this->requireTwoDistinctMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->findKeyInSlot($slot);

        $this->cache->set($key, 'ask-redirect-value');

        $this->beginMigration($source, $target, $slot, $key);

        try {
            self::assertSame(
                'ASK',
                $this->firstWordOfReplyError(fn () => $this->adminClientFor($source)->get($key)),
                'the source node must genuinely reply ASK for this key before the assertion below means anything',
            );

            self::assertSame('ask-redirect-value', $this->cache->get($key, 'MISSING'));
        } finally {
            $this->finalizeMigration($source, $target, $slot);
        }
    }

    /**
     * A real MOVED reply — the slot's stable owner has genuinely
     * changed since the cache's own topology was last discovered — is
     * distinct from ASK: the whole topology refreshes, not just this
     * one key. Forced by abruptly reassigning an empty slot's ownership
     * (CLUSTER SETSLOT ... NODE, which Redis only permits when the slot
     * holds no keys) after a fresh cache instance has already locked in
     * the *old* ownership, so the very next operation on that instance
     * genuinely hits the old owner and receives a real MOVED reply.
     */
    public function test_a_real_moved_redirect_triggers_a_topology_refresh_and_succeeds(): void
    {
        [$oldOwner, $newOwner] = $this->requireTwoDistinctMasters();
        $slot = $this->emptySlotIn($oldOwner);
        $key = $this->findKeyInSlot($slot);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // force discovery of the current (about-to-be-stale) topology

        $this->reassignEmptySlot($oldOwner, $newOwner, $slot);

        try {
            self::assertSame(
                'MOVED',
                $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
                'the old owner must genuinely reply MOVED before the assertion below means anything',
            );

            $start = microtime(true);
            $staleCache->set($key, 'moved-redirect-value');
            $elapsed = microtime(true) - $start;

            // Read through a raw admin client pinned directly to
            // $newOwner (not $staleCache, which would resolve wherever
            // its own — by now refreshed — topology says) to prove the
            // value is genuinely, physically stored there; unserialized
            // with the same serializer the library itself uses, since a
            // raw admin client bypasses ClusteredRedisSimpleCache's own
            // unserialize() step.
            $raw = $this->adminClientFor($newOwner)->get($key);
            self::assertNotNull($raw, 'the value must have landed on the new owner, not merely be readable through the cache\'s own (now-refreshed) routing');
            self::assertSame('moved-redirect-value', (new NativeSerializer())->unserialize($raw));
            self::assertLessThan(2.0, $elapsed, 'a single MOVED-refresh-retry should complete well under two seconds');
        } finally {
            $this->adminClientFor($newOwner)->delete($key);
        }
    }

    /**
     * guardKeyed()'s MOVED retry must succeed by going directly to the
     * redirect's own reported target, not solely by trusting whatever
     * refresh() manages to discover — refresh() re-reads CLUSTER SHARDS
     * from the first *reachable* seed, and during real ownership
     * propagation that seed can still be reporting the old (but
     * internally self-consistent) topology even though the node the
     * stale client actually contacted has already authoritatively
     * transitioned. Reproduced for real: seed[0] is deliberately a
     * *third*, uninvolved master, kept genuinely unaware of the
     * reassignment (confirmed directly against its own CLUSTER SHARDS
     * reply before the operation runs, not assumed) by touching only
     * $oldOwner and $newOwner directly — never calling
     * waitForGossipConvergence(), unlike every other test in this file.
     * A cache instance that discovered its topology through that exact
     * stale seed still succeeds immediately.
     */
    public function test_a_real_moved_redirect_succeeds_immediately_without_waiting_for_gossip_convergence(): void
    {
        [$oldOwner, $newOwner, $staleSeed] = $this->requireThreeDistinctMasters();
        $slot = $this->emptySlotIn($oldOwner);
        $key = $this->findKeyInSlot($slot);

        $config = new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => "{$staleSeed['host']}:{$staleSeed['port']},{$oldOwner['host']}:{$oldOwner['port']},{$newOwner['host']}:{$newOwner['port']}",
        ]);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($config);
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // discovers via seed[0] = $staleSeed specifically: $oldOwner owns $slot

        // Reassign ownership on $oldOwner and $newOwner only — deliberately
        // NOT via reassignEmptySlot(), which waits for seed[0]'s own view
        // to converge; $staleSeed is never told at all here.
        $this->adminClientFor($oldOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
        $this->adminClientFor($newOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);

        // The real precondition this test exists to prove: seed[0] is
        // genuinely still unaware, checked immediately (before any other
        // round trip widens the window) rather than assumed. Real gossip
        // propagation timing can't be forced deterministically — an
        // already-converged seed here means the environment settled
        // faster than this run could observe, not a bug, so this skips
        // rather than reporting a false failure.
        $stillStaleOwnerId = null;

        foreach ($this->adminClientFor($staleSeed)->execute('CLUSTER', 'SHARDS') as $shard) {
            $shardMap = self::pairsToAssoc($shard);
            $slots = $shardMap['slots'];

            for ($i = 0, $c = count($slots); $i < $c; $i += 2) {
                if ($slot < $slots[$i] || $slot > $slots[$i + 1]) {
                    continue;
                }

                foreach ($shardMap['nodes'] as $node) {
                    $nodeMap = self::pairsToAssoc($node);

                    if ($nodeMap['role'] === 'master') {
                        $stillStaleOwnerId = $nodeMap['id'];
                    }
                }
            }
        }

        if ($stillStaleOwnerId !== $oldOwner['id']) {
            $this->waitForGossipConvergence($slot, $newOwner['id']);
            $this->adminClientFor($newOwner)->delete($key);
            self::markTestSkipped('seed[0] already converged before this run could observe it stale — gossip propagated faster than this environment could catch.');
        }

        try {
            self::assertSame(
                'MOVED',
                $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
                'the old owner must genuinely reply MOVED before the assertions below mean anything',
            );

            $start = microtime(true);
            $staleCache->set($key, 'immediate-moved-value');
            $elapsed = microtime(true) - $start;

            $raw = $this->adminClientFor($newOwner)->get($key);
            self::assertNotNull($raw);
            self::assertSame('immediate-moved-value', (new NativeSerializer())->unserialize($raw));
            self::assertLessThan(2.0, $elapsed, 'succeeding via the reported target directly should not need to wait out any convergence delay');
        } finally {
            $this->waitForGossipConvergence($slot, $newOwner['id']);
            $this->adminClientFor($newOwner)->delete($key);
        }
    }

    /**
     * The PSR-16 violation this whole overlay mechanism exists to close:
     * a value written through a MOVED retry, in the exact same
     * pre-gossip window the previous test proves the write itself
     * succeeds in, must not be able to survive an immediately-following
     * clear() call. Without ClusterTopology recording the MOVED
     * target durably, clear()'s own FLUSHDB fan-out (via allMasters(),
     * built from whatever masters a *stale* topology already knew about)
     * would have no way to know the new owner exists at all, and could
     * report success while leaving the value behind on it.
     */
    public function test_clear_after_a_moved_routed_write_does_not_leave_data_behind(): void
    {
        [$oldOwner, $newOwner, $staleSeed] = $this->requireThreeDistinctMasters();
        $slot = $this->emptySlotIn($oldOwner);
        $key = $this->findKeyInSlot($slot);

        $config = new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => "{$staleSeed['host']}:{$staleSeed['port']},{$oldOwner['host']}:{$oldOwner['port']},{$newOwner['host']}:{$newOwner['port']}",
        ]);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($config);
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // discovers via seed[0] = $staleSeed: $oldOwner owns $slot

        $this->adminClientFor($oldOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
        $this->adminClientFor($newOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);

        try {
            self::assertSame(
                'MOVED',
                $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key)),
                'the old owner must genuinely reply MOVED before the assertions below mean anything',
            );

            // The write itself is already proven to succeed immediately
            // in this exact window by the test above — the interesting
            // part here is what happens next.
            $staleCache->set($key, 'must-not-survive-clear');
            self::assertNotNull(
                $this->adminClientFor($newOwner)->get($key),
                'sanity check: the value must have genuinely landed on the new owner before clear() is even called',
            );

            $result = $staleCache->clear();

            self::assertTrue($result);
            self::assertNull(
                $this->adminClientFor($newOwner)->get($key),
                'clear() reported success but left this value behind on the node only reachable via the MOVED override — the exact violation this fix closes',
            );
        } finally {
            $this->waitForGossipConvergence($slot, $newOwner['id']);
            $this->adminClientFor($newOwner)->delete($key);
        }
    }

    /**
     * The real, two-slot version of the sequential scenario proven
     * precisely (with engineered snapshots) in ClusterTopologyTest: two
     * different slots, moved to two different targets, in the same
     * pre-gossip window — both writes must round-trip correctly and
     * clear() must reach both targets, regardless of which of the two
     * this particular run's own gossip timing happens to confirm first
     * (this test asserts on the observable guarantee, not on which
     * internal mechanism — the stable $ranges or the override overlay —
     * ends up serving either slot).
     */
    public function test_two_slots_moved_to_different_targets_both_survive_a_shared_pre_gossip_window(): void
    {
        [$oldOwner, $target1, $target2] = $this->requireThreeDistinctMasters();
        // emptySlotInLargestRange(), offset 9/11 — not emptySlotIn()'s
        // default first-range/offset-1 shape: several earlier tests
        // already reassign masters[0]'s own small-offset slots, and by
        // the time this test runs its "first range" may already be a
        // stray leftover fragment from one of those rather than
        // masters[0]'s own main range (see emptySlotInLargestRange()'s
        // own docblock); picking from the largest range at genuinely
        // unused offsets sidesteps both a wrong-range read and a
        // same-slot gossip race in one step — both measurably observed
        // causes of this test's own convergence failing under real
        // Docker CPU contention, not hypothetical concerns.
        $slot1 = $this->emptySlotInLargestRange($oldOwner, offset: 9);
        $slot2 = $this->emptySlotInLargestRange($oldOwner, offset: 11); // a second, distinct empty slot in the same owner's range
        $key1 = $this->findKeyInSlot($slot1);
        $key2 = $this->findKeyInSlot($slot2);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key1); // locks in: oldOwner owns both slots, before either move happens

        $this->adminClientFor($oldOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot1, 'NODE', $target1['id']);
        $this->adminClientFor($target1)->execute('CLUSTER', 'SETSLOT', (string) $slot1, 'NODE', $target1['id']);
        $this->adminClientFor($oldOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot2, 'NODE', $target2['id']);
        $this->adminClientFor($target2)->execute('CLUSTER', 'SETSLOT', (string) $slot2, 'NODE', $target2['id']);

        try {
            self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key1)));
            self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($oldOwner)->get($key2)));

            $staleCache->set($key1, 'slot1-value');
            $staleCache->set($key2, 'slot2-value');

            self::assertSame('slot1-value', $staleCache->get($key1), 'a later read for slot 1 must still resolve correctly');
            self::assertSame('slot2-value', $staleCache->get($key2), 'a later read for slot 2 must still resolve correctly, unaffected by slot 1\'s own handling');

            $result = $staleCache->clear();

            self::assertTrue($result);
            self::assertNull($this->adminClientFor($target1)->get($key1), 'clear() must reach target1');
            self::assertNull($this->adminClientFor($target2)->get($key2), 'clear() must reach target2');
        } finally {
            $this->waitForGossipConvergenceOfAll([
                ['slot' => $slot1, 'ownerId' => $target1['id']],
                ['slot' => $slot2, 'ownerId' => $target2['id']],
            ]);
            $this->adminClientFor($target1)->delete($key1);
            $this->adminClientFor($target2)->delete($key2);
        }
    }

    /**
     * The scenario the reviewer named explicitly: a slot moves (MOVED)
     * and the individual key is *also* still migrating out of the new
     * owner (ASK) — both real, both within one guardKeyed() call. Built
     * by reassigning an empty slot's ownership first (source -> new
     * stable owner), then immediately beginning a real migration of
     * that same slot from the new owner onward to a third node, before
     * the already-stale cache instance ever gets a chance to refresh.
     */
    public function test_a_real_moved_then_ask_sequence_is_followed_correctly_in_one_call(): void
    {
        [$original, $intermediate, $final] = $this->requireThreeDistinctMasters();
        $slot = $this->emptySlotIn($original);
        $key = $this->findKeyInSlot($slot);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key); // locks in "original owns $slot", never refreshed again below

        $this->reassignEmptySlot($original, $intermediate, $slot);

        $freshCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $freshCache);
        $freshCache->set($key, 'sequence-final-value');

        $this->beginMigration($intermediate, $final, $slot, $key);

        try {
            self::assertSame('MOVED', $this->firstWordOfReplyError(fn () => $this->adminClientFor($original)->get($key)));
            self::assertSame('ASK', $this->firstWordOfReplyError(fn () => $this->adminClientFor($intermediate)->get($key)));

            self::assertSame('sequence-final-value', $staleCache->get($key, 'MISSING'));
        } finally {
            $this->finalizeMigration($intermediate, $final, $slot);
        }
    }

    /**
     * The reviewer's own concrete scenario for a real dedicated ASK
     * client's own failure: a separate slot's genuinely durable MOVED
     * override (installed via a real pre-gossip MOVED reply, exactly
     * like the sequential-scenario tests above) must survive a generic,
     * connection-level failure on the *retried* command against a real
     * ASK target — after ASKING against that same real target has
     * already genuinely succeeded. This is the one half of the
     * reviewer's scenario ClusteredRedisSimpleCacheTest's own fake
     * addresses can't reach (ASKING needs a real, reachable target to
     * succeed at all), so it's proven here instead, against the real
     * cluster: the retried command itself is substituted via a
     * guardKeyed() closure invoked by reflection, the only point where
     * the real production code has no further control to hand to a
     * test — everything before it (the real ASK trigger, the real
     * ASKING call) runs exactly as production code would.
     */
    public function test_a_generic_failure_on_an_ask_targets_retry_does_not_erase_a_separate_slots_durable_overlay(): void
    {
        [$stableOldOwner, $stableNewOwner, $migrationSource] = $this->requireThreeDistinctMasters();
        // emptySlotInLargestRange(), not emptySlotIn(): several earlier
        // tests reassign masters[0]'s own small-offset slots, and by
        // the time this test runs either role here may have already
        // accumulated a stray single-slot fragment from one of those —
        // picking from the largest range sidesteps both that and this
        // file's usual 1/3 offset collisions in one step.
        $stableSlot = $this->emptySlotInLargestRange($stableOldOwner, offset: 5);
        $stableKey = $this->findKeyInSlot($stableSlot);

        $cache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
        $cache->has($stableKey); // locks in: stableOldOwner owns $stableSlot, before the reassignment below

        // A real, pre-gossip MOVED redirect below installs a genuine,
        // durable override for $stableSlot — not a synthetic one — the
        // exact mechanism this whole regression is actually about.
        $this->adminClientFor($stableOldOwner)->execute('CLUSTER', 'SETSLOT', (string) $stableSlot, 'NODE', $stableNewOwner['id']);
        $this->adminClientFor($stableNewOwner)->execute('CLUSTER', 'SETSLOT', (string) $stableSlot, 'NODE', $stableNewOwner['id']);

        // $stableOldOwner is otherwise uninvolved once the reassignment
        // above already moved $stableSlot away from it, so it's free to
        // also serve as a real, live ASK migration target for a
        // completely different slot without either role overlapping.
        $migrationSlot = $this->emptySlotInLargestRange($migrationSource, offset: 1);
        $migrationKey = $this->findKeyInSlot($migrationSlot);
        $this->adminClientFor($migrationSource)->set($migrationKey, 'irrelevant-migrating-value');

        try {
            self::assertSame(
                'MOVED',
                $this->firstWordOfReplyError(fn () => $this->adminClientFor($stableOldOwner)->get($stableKey)),
                'the old owner must genuinely reply MOVED before the durable override below means anything',
            );

            $cache->set($stableKey, 'stable-value'); // a real MOVED retry -> installs the durable override
            self::assertSame('stable-value', $cache->get($stableKey), 'sanity check: the durable override must work before the unrelated ASK failure below');

            // Only the override's own *installation* needs to be
            // pre-gossip — nothing below tests staleness for
            // $stableSlot again, so letting it converge now, before the
            // unrelated migration below adds its own SETSLOT/gossip
            // traffic, keeps that traffic from competing with this
            // slot's convergence and slowing this test down for no
            // reason.
            $this->waitForGossipConvergence($stableSlot, $stableNewOwner['id']);

            $this->beginMigration($migrationSource, $stableOldOwner, $migrationSlot, $migrationKey);

            try {
                self::assertSame(
                    'ASK',
                    $this->firstWordOfReplyError(fn () => $this->adminClientFor($migrationSource)->get($migrationKey)),
                    'the migration source must genuinely reply ASK for this key before the assertions below mean anything',
                );

                $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
                $attempts = 0;

                try {
                    $guardKeyed->invoke($cache, 'get', $migrationKey, function (RedisClient $client) use (&$attempts, $migrationKey): mixed {
                        $attempts++;

                        if ($attempts === 1) {
                            // The real migration source, genuinely
                            // replying -ASK for this already-migrated key.
                            return $client->get($migrationKey);
                        }

                        // guardKeyed() only ever reaches a second
                        // attempt after ASKING has already succeeded for
                        // real against the real dedicated target -- this
                        // simulates the retried command itself failing
                        // at the connection level right after, the
                        // reviewer's own exact scenario.
                        throw new RedisException('Simulated connection loss right after ASKING');
                    });

                    self::fail('Expected a CacheException from the simulated post-ASKING failure.');
                } catch (CacheException) {
                    // expected
                }

                self::assertSame(2, $attempts, 'sanity check: ASKING must have genuinely succeeded for the second attempt to have been reached at all');

                self::assertSame(
                    'stable-value',
                    $cache->get($stableKey),
                    'a separate slot\'s own durable override must survive an ASK-dedicated client\'s own failure',
                );

                $result = $cache->clear();
                self::assertTrue($result);
                self::assertNull(
                    $this->adminClientFor($stableNewOwner)->get($stableKey),
                    'clear() must still reach the durable override\'s target after the unrelated ASK failure',
                );
            } finally {
                $this->finalizeMigration($migrationSource, $stableOldOwner, $migrationSlot);
            }
        } finally {
            // $stableSlot's own gossip convergence already happened
            // right after the override was installed above, before the
            // migration phase added its own SETSLOT traffic — nothing
            // further to wait on here, just the raw cleanup.
            $this->adminClientFor($stableNewOwner)->delete($stableKey);
        }
    }

    /**
     * The interleaving hazard a shared, multiplexed connection would
     * create: an unrelated Fiber's command landing on the wire between
     * ASKING and the retried operation. Proven by racing the real
     * ASK-redirected read against many unrelated real reads sharing the
     * source and target nodes' own memoized connections, all through
     * concurrently() — every single one must return its own correct
     * value, with nothing corrupted by anything else in flight.
     */
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

        try {
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
        } finally {
            $this->finalizeMigration($source, $target, $slot);
        }
    }

    /**
     * MAX_REDIRECT_ATTEMPTS bounds the loop rather than looping forever
     * — genuinely exercised here (not merely asserted from reading the
     * source) by leaving a slot permanently mid-migration, so every one
     * of guardKeyed()'s repeated ASKING+retry attempts keeps succeeding
     * at ASKING but keeps receiving another ASK for the operation
     * itself, since ASKING's own grant only ever covers the *one*
     * command sent immediately after it, and a plain (non-eval, non-
     * pipelined) get() through Amp\Redis is exactly such a single
     * command each time.
     *
     * amphp/redis's own MULTI/EXEC-less client has no way to send
     * ASKING and the real command as one atomic unit — which is exactly
     * why this is possible to construct as a real, not simulated, bound
     * violation: a legitimately migrating key normally resolves in one
     * ASK hop, but nothing about the protocol itself prevents a
     * misbehaving/flapping node from repeating it, and this is the
     * proof that a real repeated ASK sequence fails cleanly rather than
     * hanging.
     */
    /**
     * A redirect that keeps naming the exact same target is a repeating
     * cycle, detected and rejected the moment it repeats — not spent
     * bouncing through the full MAX_REDIRECT_ATTEMPTS budget the way a
     * genuinely non-repeating chain would (that bound-exhaustion case is
     * covered deterministically, with no real backend needed, in
     * ClusteredRedisSimpleCacheTest). What only a real backend proves is
     * that this holds even when the redirect target *genuinely* accepts
     * a real ASKING call every single time it's asked — a synthetic
     * operation that keeps reporting ASK to the very same real,
     * reachable, IMPORTING node no matter how many times it's called
     * still gets caught by the loop detector on its 2nd occurrence, not
     * by a connection failure and not by exhausting the bound.
     */
    public function test_a_repeated_redirect_to_a_genuinely_reachable_target_is_caught_as_a_loop(): void
    {
        [$source, $target] = $this->requireTwoDistinctMasters();
        $slot = $source['ranges'][0][0];
        $key = $this->findKeyInSlot($slot);
        $calls = 0;

        $this->cache->set($key, 'never-reached');

        $this->beginMigration($source, $target, $slot, $key);

        try {
            self::assertSame('ASK', $this->firstWordOfReplyError(fn () => $this->adminClientFor($source)->get($key)));

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage(
                "Redirect loop detected: ASK to {$target['host']}:{$target['port']} was already followed for this operation.",
            );

            $guardKeyed = new ReflectionMethod($this->cache, 'guardKeyed');
            $askMessage = "ASK {$slot} {$target['host']}:{$target['port']}";

            $guardKeyed->invoke($this->cache, 'get', $key, function (RedisClient $client) use (&$calls, $askMessage): never {
                $calls++;

                throw new QueryException($askMessage);
            });
        } finally {
            self::assertSame(2, $calls, 'the loop must be caught on its 2nd occurrence against this real target, not after spending the full redirect budget');
            $this->finalizeMigration($source, $target, $slot);
        }
    }

    /**
     * @return list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}>
     */
    private function discoverMasters(): array
    {
        $seeds = explode(',', (string) getenv('REDIS_CLUSTER_SEEDS'));
        $admin = createRedisClient(RedisConfig::fromUri('tcp://' . trim($seeds[0])));
        $shards = $admin->execute('CLUSTER', 'SHARDS');

        $masters = [];

        foreach ($shards as $shard) {
            $shardMap = self::pairsToAssoc($shard);
            $slots = $shardMap['slots'];
            $ranges = [];

            for ($i = 0, $c = count($slots); $i < $c; $i += 2) {
                $ranges[] = [$slots[$i], $slots[$i + 1]];
            }

            foreach ($shardMap['nodes'] as $node) {
                $nodeMap = self::pairsToAssoc($node);

                if ($nodeMap['role'] === 'master') {
                    $masters[] = [
                        'id' => $nodeMap['id'],
                        'host' => $nodeMap['ip'],
                        'port' => $nodeMap['port'],
                        'ranges' => $ranges,
                    ];
                }
            }
        }

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
        $masters = $this->discoverMasters();

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
        $masters = $this->discoverMasters();

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
     * Searches every range $master currently owns, not just the first —
     * an earlier test's own emptySlotIn()/reassignEmptySlot() call can
     * permanently fragment a master's original single wide range into
     * several disjoint ones (moving one slot away from the middle or
     * edge of it), and the *first* of those fragments can end up no
     * wider than the one slot $excluding names, with genuinely no room
     * left in it for an "unrelated" key at all.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $master
     */
    private function findKeyOwnedBy(array $master, ?int $excluding = null): string
    {
        foreach ($master['ranges'] as [$start, $end]) {
            if ($start === $end && $start === $excluding) {
                continue; // this fragment *is* the excluded slot -- no room here, try the next one
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
        // A single-slot range (start === end, as every emptySlotIn()/
        // findKeyInSlot() call needs) has roughly 1-in-16384 odds of a
        // match per try, so this needs real headroom, not a round
        // number: 300,000 tries brings the odds of finding none down to
        // roughly 1-in-13-million, and runs in well under two seconds
        // even in that single-slot worst case.
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
     * A slot genuinely has to hold zero keys before Redis permits an
     * abrupt CLUSTER SETSLOT ... NODE ownership change — confirmed
     * directly: the server refuses otherwise ("Can't assign hashslot
     * ... while I still hold keys"). $oldOwner's own first range is
     * used, since a fresh cluster's default slot assignment starts with
     * genuinely empty ranges before any test in this file writes to
     * them.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $owner
     */
    private function emptySlotIn(array $owner, int $offset = 1): int
    {
        return $owner['ranges'][0][0] + $offset;
    }

    /**
     * emptySlotIn()'s own ranges[0][0] assumption can point at the
     * wrong thing once a master has accumulated a stray single-slot
     * fragment from an earlier, unrelated test's own finalized
     * migration onto it — real Redis Cluster lists a shard's ranges in
     * ascending slot order, so a tiny leftover fragment (slot 1, say)
     * sorts *ahead of* the master's own large, original range and
     * silently becomes ranges[0] instead, feeding emptySlotIn() a slot
     * number that isn't actually within the master's main range at
     * all — confirmed as a real, observed cause of a raw SETSLOT/write
     * failing with a genuine server-side MOVED, not a hypothetical.
     * This picks the *largest* range instead, which a small leftover
     * fragment can never be, sidestepping the ambiguity entirely.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $owner
     */
    private function emptySlotInLargestRange(array $owner, int $offset = 1): int
    {
        $largest = $owner['ranges'][0];

        foreach ($owner['ranges'] as $range) {
            if ($range[1] - $range[0] > $largest[1] - $largest[0]) {
                $largest = $range;
            }
        }

        return $largest[0] + $offset;
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
        $this->adminClientFor($target)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'IMPORTING', $source['id']);
        $this->adminClientFor($source)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'MIGRATING', $target['id']);
        $this->adminClientFor($source)->execute('MIGRATE', $target['host'], (string) $target['port'], $key, '0', '5000', 'REPLACE');
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $source
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $target
     */
    private function finalizeMigration(array $source, array $target, int $slot): void
    {
        $this->adminClientFor($source)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);
        $this->adminClientFor($target)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);
        $this->waitForGossipConvergence($slot, $target['id']);
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $oldOwner
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $newOwner
     */
    private function reassignEmptySlot(array $oldOwner, array $newOwner, int $slot): void
    {
        foreach ([$oldOwner, $newOwner] as $master) {
            $this->adminClientFor($master)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
        }

        $this->waitForGossipConvergence($slot, $newOwner['id']);
    }

    /**
     * discoverMasters() always reads CLUSTER SHARDS from seeds[0]
     * specifically, and a CLUSTER SETSLOT ... NODE issued directly
     * against the two nodes actually involved in an ownership change
     * doesn't make that change visible on every *other* node
     * synchronously — gossip propagation genuinely takes a moment.
     * Confirmed as a real, observed cause of test flakiness (a chain of
     * MOVED replies that never converged within guardKeyed()'s own
     * bounded attempts, because the seed being asked hadn't caught up
     * yet), not a hypothetical concern: polling seeds[0]'s own view
     * directly, rather than a fixed sleep, is what actually closes it.
     */
    private function waitForGossipConvergence(int $slot, string $expectedOwnerId): void
    {
        $this->waitForGossipConvergenceOfAll([['slot' => $slot, 'ownerId' => $expectedOwnerId]]);
    }

    /**
     * The combined form of waitForGossipConvergence() — polls once,
     * checking every {slot, ownerId} pair on each iteration, returning
     * only once all of them are satisfied. This is not just a
     * convenience over calling waitForGossipConvergence() once per pair
     * sequentially: real gossip propagation for reassignments made
     * close together in time genuinely happens *in parallel* across the
     * cluster, so waiting on all of them together only ever needs to
     * wait for the slowest one, not their sum. A sequential-calls
     * version of the two-slot test below independently waited on each
     * slot's own full budget one after another — up to double the real
     * wall-clock risk for no correctness benefit, and directly observed
     * failing in CI (specifically under kinetis/framework's own
     * sonarqube.yml coverage step, which runs this suite as one of
     * ~26 sequential packages sharing a single runner — genuinely
     * heavier contention than a dedicated job) even after the per-call
     * budget below was already widened once.
     *
     * @param list<array{slot: int, ownerId: string}> $expectations
     */
    private function waitForGossipConvergenceOfAll(array $expectations): void
    {
        $seeds = explode(',', (string) getenv('REDIS_CLUSTER_SEEDS'));
        $seed = createRedisClient(RedisConfig::fromUri('tcp://' . trim($seeds[0])));

        // 800 tries at 50ms is 40 real seconds of headroom — widened
        // from an original 20s (400 tries) after that ceiling was
        // directly observed being hit in real CI runs, not just
        // theorized about: a single reassignment usually converges in
        // well under a second, but real gossip propagation under
        // genuine CI CPU contention has been measured taking well past
        // 5, and occasionally past 15, real seconds. This margin is
        // shared by every pair in $expectations together (see this
        // method's own docblock for why that's correct, not just
        // convenient), so it exists purely for genuine host-level
        // slowness, not for the number of pairs being waited on.
        // Reusing the same relative slot offset across several tests
        // would compound this further, racing against an earlier
        // test's still-settling gossip for the identical slot —
        // avoided at the call sites that need it via
        // emptySlotInLargestRange() and genuinely distinct offsets (see
        // that method's own docblock).
        for ($i = 0; $i < 800; $i++) {
            $shards = $seed->execute('CLUSTER', 'SHARDS');
            $remaining = [];

            foreach ($expectations as $expectation) {
                if (!self::shardsReportOwner($shards, $expectation['slot'], $expectation['ownerId'])) {
                    $remaining[] = $expectation;
                }
            }

            if ($remaining === []) {
                return;
            }

            $expectations = $remaining;

            usleep(50000);
        }

        $descriptions = array_map(
            static fn (array $e): string => "slot {$e['slot']} -> {$e['ownerId']}",
            $expectations,
        );

        throw new RuntimeException('Gossip never converged: seeds[0] still doesn\'t report ' . implode(', ', $descriptions) . '.');
    }

    /**
     * @param mixed $shards a raw CLUSTER SHARDS reply
     */
    private static function shardsReportOwner(mixed $shards, int $slot, string $expectedOwnerId): bool
    {
        foreach ($shards as $shard) {
            $shardMap = self::pairsToAssoc($shard);
            $slots = $shardMap['slots'];

            for ($j = 0, $c = count($slots); $j < $c; $j += 2) {
                if ($slot < $slots[$j] || $slot > $slots[$j + 1]) {
                    continue;
                }

                foreach ($shardMap['nodes'] as $node) {
                    $nodeMap = self::pairsToAssoc($node);

                    if ($nodeMap['role'] === 'master' && $nodeMap['id'] === $expectedOwnerId) {
                        return true;
                    }
                }
            }
        }

        return false;
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
