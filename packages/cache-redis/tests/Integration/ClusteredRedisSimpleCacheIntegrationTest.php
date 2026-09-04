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
    /**
     * Every reservedSlotFor() call site in this file names one of these
     * explicitly — 600 slots apart, comfortably inside any master's
     * ~5461-slot original range even with all eight reservations drawn
     * from the same one.
     *
     * Two properties matter. Offsets clustered within a few slots of
     * each other fragment one neighborhood of a master's slot map into
     * many single-slot pieces, which measurably slows gossip for
     * whichever test reassigns there last — hence the spacing. And an
     * offset relative to whatever range a master *currently* reports is
     * a moving target, since every earlier reservation changes it —
     * hence reservedSlotFor() resolving each offset against the frozen
     * original split in $originalMasterRangeStarts, never live range
     * data.
     */
    private const int TEST_SLOT_OFFSET_A = 100;
    private const int TEST_SLOT_OFFSET_B = 700;
    private const int TEST_SLOT_OFFSET_C = 1300;
    private const int TEST_SLOT_OFFSET_D = 1900;
    private const int TEST_SLOT_OFFSET_E = 2500;
    private const int TEST_SLOT_OFFSET_F = 3100;
    private const int TEST_SLOT_OFFSET_G = 3700;
    private const int TEST_SLOT_OFFSET_H = 4300;

    /**
     * Captured once, in setUpBeforeClass() — before any test has had a
     * chance to reassign a single slot — mapping each master's own node
     * id to the start of its original, contiguous range. Every
     * reservedSlotFor() call reads from this frozen snapshot rather than
     * a live discoverMasters() call: a live call reflects whatever
     * fragmentation every *earlier* test in the same run has already
     * caused, and reservedSlotFor() exists so no test has to reason
     * about that.
     *
     * The guarantee holds for one PHPUnit invocation against one
     * freshly bootstrapped cluster, never across two. At least one test
     * (test_a_generic_failure_on_an_ask_targets_retry_does_not_erase_a_separate_slots_durable_overlay)
     * permanently reassigns its own reserved slot through
     * finalizeMigration() as part of a passing run, so a second
     * invocation against the same service container snapshots a split
     * in which that slot is already elsewhere, and the test's direct
     * write to the node it expects to own the slot fails with a raw
     * MOVED error. A retry of this suite therefore needs a fresh service
     * container — a job-level re-run — never a second `phpunit` in the
     * same job; integration.yml's redis-cluster job and sonarqube.yml's
     * cache-redis coverage step both run a single invocation for this
     * reason.
     *
     * @var array<string, int>
     */
    private static array $originalMasterRangeStarts = [];

    private ClusteredRedisSimpleCache $cache;

    public static function setUpBeforeClass(): void
    {
        $seeds = \getenv('REDIS_CLUSTER_SEEDS');

        if ($seeds === false || $seeds === '') {
            return; // setUp()'s own per-test check is what actually skips each test; nothing to snapshot here without a real cluster.
        }

        $masters = self::discoverMasters();

        foreach ($masters as $master) {
            self::$originalMasterRangeStarts[$master['id']] = $master['ranges'][0][0];
        }

        // Redis defaults latency-monitor-threshold to 0 (disabled), so
        // LATENCY HISTORY/LATEST report nothing unless it is raised
        // beforehand. Enabled once per class, on every master, so that
        // a gossip-convergence timeout (see
        // waitForGossipConvergenceOfAll()) can show whether the target
        // node's own command or fork processing was slow during the run.
        // 100ms is well above this suite's normal command latencies and
        // well below any convergence delay worth diagnosing, so it
        // records nothing under normal conditions.
        foreach ($masters as $master) {
            try {
                createRedisClient(RedisConfig::fromUri("tcp://{$master['host']}:{$master['port']}"))
                    ->execute('CONFIG', 'SET', 'latency-monitor-threshold', '100');
            } catch (\Throwable) {
                // Diagnostics are best-effort — a master this couldn't
                // reach here would already be failing every real test
                // against it, loudly, on its own terms.
            }
        }

        // Randomized, not left at a fixed 0 — see $masterRotation's own
        // docblock for why a fixed starting point defeats the entire
        // point of rotating at all.
        self::$masterRotation = random_int(0, PHP_INT_MAX);
    }

    /**
     * The slot this file reserves for one specific test, deterministic
     * regardless of how many earlier tests have already reassigned
     * slots elsewhere in the cluster (see $originalMasterRangeStarts
     * and the TEST_SLOT_OFFSET_* constants' own docblocks for why that
     * guarantee is the entire point). $owner can be any master this
     * file discovered — its *current* ranges are never consulted here,
     * only its node id, which reservedSlotFor() uses to look up that
     * master's frozen original starting boundary.
     *
     * A slot has to hold zero keys before Redis permits an abrupt
     * CLUSTER SETSLOT ... NODE ownership change (the server refuses
     * otherwise: "Can't assign hashslot ... while I still hold keys").
     * Every offset this file reserves stays well inside a master's
     * original range and 600 slots apart from every other reservation,
     * so the returned slot is always empty regardless of what earlier
     * tests have done elsewhere in the cluster.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $owner
     */
    private function reservedSlotFor(array $owner, int $offset): int
    {
        self::assertArrayHasKey(
            $owner['id'],
            self::$originalMasterRangeStarts,
            'setUpBeforeClass() never snapshotted this master — was it added to the cluster after this test class started running?',
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
        // A widely-spaced offset — see the TEST_SLOT_OFFSET_* docblock
        // for why reservations are kept 600 slots apart.
        $slot = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_A);
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
     * transitioned. Here the client discovers its topology through a
     * *third*, uninvolved master before the reassignment and keeps that
     * snapshot until a MOVED reply refreshes it — so its first write
     * after the move is routed on stale information by construction,
     * however far gossip has spread by then. That write still succeeds
     * immediately.
     */
    public function test_a_real_moved_redirect_succeeds_immediately_without_waiting_for_gossip_convergence(): void
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

        // Reassign ownership on $oldOwner and $newOwner only — deliberately
        // NOT via reassignEmptySlot(), which waits for seed[0]'s own view
        // to converge. The staleness this test exercises is the
        // *client's*: $staleCache locked its topology from $staleSeed
        // above, before the move, and nothing refreshes it until a MOVED
        // reply does — so its first write below is guaranteed to be
        // routed on stale information whatever any seed reports by now.
        $this->assignSlot($oldOwner, $newOwner, $slot);

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
        // Two distinct TEST_SLOT_OFFSET_* constants, same $oldOwner —
        // see the constants' docblock for why they are spaced apart and
        // resolved against the frozen original split.
        $slot1 = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_D);
        $slot2 = $this->reservedSlotFor($oldOwner, self::TEST_SLOT_OFFSET_E); // a second, distinct empty slot in the same owner's range
        $key1 = $this->findKeyInSlot($slot1);
        $key2 = $this->findKeyInSlot($slot2);

        $staleCache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $staleCache);
        $staleCache->has($key1); // locks in: oldOwner owns both slots, before either move happens

        $this->assignSlot($oldOwner, $target1, $slot1);
        $this->assignSlot($oldOwner, $target2, $slot2);

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
        $slot = $this->reservedSlotFor($original, self::TEST_SLOT_OFFSET_F);
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
        // Resolved against the frozen original split, so whatever either
        // role has already accumulated elsewhere in the cluster by now
        // does not affect it — see the TEST_SLOT_OFFSET_* docblock.
        $stableSlot = $this->reservedSlotFor($stableOldOwner, self::TEST_SLOT_OFFSET_G);
        $stableKey = $this->findKeyInSlot($stableSlot);

        $cache = ClusteredRedisSimpleCache::fromConfig($this->clusterConfig());
        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
        $cache->has($stableKey); // locks in: stableOldOwner owns $stableSlot, before the reassignment below

        // A real, pre-gossip MOVED redirect below installs a genuine,
        // durable override for $stableSlot — not a synthetic one — the
        // exact mechanism this whole regression is actually about.
        $this->assignSlot($stableOldOwner, $stableNewOwner, $stableSlot);

        // $stableOldOwner is otherwise uninvolved once the reassignment
        // above already moved $stableSlot away from it, so it's free to
        // also serve as a real, live ASK migration target for a
        // completely different slot without either role overlapping —
        // and TEST_SLOT_OFFSET_H keeps it out of every other test's own
        // reserved neighborhood too (see the two-slot test's own
        // docblock for why that matters).
        $migrationSlot = $this->reservedSlotFor($migrationSource, self::TEST_SLOT_OFFSET_H);
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
    private static function discoverMasters(): array
    {
        return array_map(static fn (array $shard): array => $shard['master'], self::discoverShards());
    }

    /**
     * @return array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}
     */
    private static function masterById(string $id): array
    {
        foreach (self::discoverMasters() as $master) {
            if ($master['id'] === $id) {
                return $master;
            }
        }

        throw new RuntimeException("No master with id {$id} in seeds[0]'s CLUSTER SHARDS.");
    }

    /**
     * Every shard as seeds[0]'s CLUSTER SHARDS reports it: its one
     * master plus every replica of it, all carrying the shard's slot
     * ranges. Replicas are needed by waitForReplicasToMirrorTheirMasters()
     * alone; everything else in this class only ever wants the masters.
     *
     * @return list<array{master: array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}, replicas: list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}>}>
     */
    private static function discoverShards(): array
    {
        $seeds = explode(',', (string) getenv('REDIS_CLUSTER_SEEDS'));
        $admin = createRedisClient(RedisConfig::fromUri('tcp://' . trim($seeds[0])));

        $result = [];

        foreach ($admin->execute('CLUSTER', 'SHARDS') as $shard) {
            $shardMap = self::pairsToAssoc($shard);
            $slots = $shardMap['slots'];
            $ranges = [];

            for ($i = 0, $c = count($slots); $i < $c; $i += 2) {
                $ranges[] = [$slots[$i], $slots[$i + 1]];
            }

            $master = null;
            $replicas = [];

            foreach ($shardMap['nodes'] as $node) {
                $nodeMap = self::pairsToAssoc($node);
                $entry = [
                    'id' => $nodeMap['id'],
                    'host' => $nodeMap['ip'],
                    'port' => $nodeMap['port'],
                    'ranges' => $ranges,
                ];

                if ($nodeMap['role'] === 'master') {
                    $master = $entry;
                } else {
                    $replicas[] = $entry;
                }
            }

            if ($master === null) {
                continue; // a shard mid-failover with no master yet has nothing this class can address
            }

            $result[] = ['master' => $master, 'replicas' => $replicas];
        }

        return $result;
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
     * Shared by requireTwoDistinctMasters()/requireThreeDistinctMasters()
     * — incremented on every call to either, never reset between tests.
     * discoverMasters()'s CLUSTER SHARDS-derived ordering is stable call
     * to call, and every test in this file destructures the *first*
     * returned element as its reservation owner ("$oldOwner"/
     * "$original"/"$stableOldOwner"), so without rotation every
     * reservation in the suite lands on the one master that sorts
     * first, however far apart the TEST_SLOT_OFFSET_* values are —
     * concentrating every reassignment on one node's slot map.
     *
     * setUpBeforeClass() seeds this randomly rather than at 0. PHPUnit's
     * test execution order is stable run to run, so from a fixed
     * starting point any given test reaches requireXDistinctMasters()
     * after the same number of prior calls every run — the same
     * rotation offset, the same physical master, every time. A random
     * seed is what makes the master a given test draws vary between
     * runs, not only between tests within one run.
     */
    private static int $masterRotation = 0;

    /**
     * @param list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}> $masters
     * @return list<array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>}>
     */
    private static function rotated(array $masters): array
    {
        $offset = self::$masterRotation % count($masters);
        self::$masterRotation++;

        return [...array_slice($masters, $offset), ...array_slice($masters, 0, $offset)];
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

        $masters = self::rotated($masters);

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

        $masters = self::rotated($masters);

        return [$masters[0], $masters[1], $masters[2]];
    }

    private function findKeyInSlot(int $slot): string
    {
        return $this->findKeyInRange($slot, $slot);
    }

    /**
     * Searches every range $master currently owns, not just the first —
     * an earlier test's own reservedSlotFor()/reassignEmptySlot() call
     * can permanently fragment a master's original single wide range into
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
        // A single-slot range (start === end, as every reservedSlotFor()/
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
        // Redis bumps the importing node's epoch itself on this SETSLOT,
        // with the same tie-loses-the-collision gap assignSlot()
        // documents — a target that loses the collision leaves the
        // source's replica attributing the slot to the source with
        // nothing higher ever claiming it. Same ordering as assignSlot(),
        // for the same reasons.
        $this->waitForReplicasToMirrorTheirMasters();
        $this->raiseEpochAboveEveryOtherMaster($target);
        $this->adminClientFor($source)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);
        $this->adminClientFor($target)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $target['id']);
        $this->waitForReplicasToMirrorTheirMasters();
        $this->waitForGossipConvergence($slot, $target['id']);
    }

    /**
     * The one way this file reassigns a slot directly (no IMPORTING/
     * MIGRATING handshake). A node learning a slot claim via gossip
     * accepts it only if the claimant's configEpoch is *higher* than
     * that of the node it currently attributes the slot to, and a bare
     * `SETSLOT <slot> NODE` never raises the new owner's epoch (Redis
     * bumps it only when the slot was in IMPORTING state). Every node
     * starts at its bootstrap epoch (1-3 on this six-node image), so a
     * lower-epoch new owner's claim applies locally — the node answers
     * "mine" when asked directly — and is rejected by every third party
     * until something else raises that node's epoch. Against this
     * image, a third party still attributes such a slot to the old
     * owner 24s after the SETSLOT pair and switches within 1s of a
     * BUMPEPOCH; in the favorable direction (lower-epoch owner to
     * higher-epoch target) it switches within 1s with no bump.
     *
     * The bump is verified, not fired and forgotten — see
     * raiseEpochAboveEveryOtherMaster() for the tie case a single
     * BUMPEPOCH gets wrong.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $oldOwner
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $newOwner
     */
    private function assignSlot(array $oldOwner, array $newOwner, int $slot): void
    {
        // Order matters; each step closes one way the old owner can take
        // the slot back:
        //
        // 1. A replica advertises its *master's* slot bitmap and epoch
        //    in its own gossip, from a local copy that lags the master
        //    by up to a gossip round. A replica of the old owner still
        //    advertising the pre-SETSLOT bitmap re-assigns the slot to
        //    the old owner in any table where the old owner's epoch is
        //    the higher one — including the new owner's own table, which
        //    then answers MOVED back to the old owner while the old
        //    owner answers MOVED to the new one: a redirect loop. So no
        //    epoch moves while any stale bitmap exists anywhere.
        // 2. The new owner's epoch is raised strictly above every other
        //    master's *before* it takes the slot, so its claim outranks
        //    every copy of the old bitmap from the first PING it sends.
        // 3. Ownership changes hands.
        // 4. This does not return until every replica mirrors its master
        //    again — until the old owner's replica holds the new claim.
        //    Waiting only at the start of the *next* reassignment leaves
        //    a hole: that reassignment can raise the old owner's epoch
        //    while its replica still advertises the old bitmap, which
        //    then out-ranks the new owner in the new owner's own table —
        //    a slot no master claims.
        //
        // The MOVED tests' pre-gossip window is the *client's* cached
        // topology, locked before this call, which waiting on replicas
        // here does not touch.
        $this->waitForReplicasToMirrorTheirMasters();
        $this->raiseEpochAboveEveryOtherMaster($newOwner);
        $this->adminClientFor($oldOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
        $this->adminClientFor($newOwner)->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
        $this->waitForReplicasToMirrorTheirMasters();
    }

    /**
     * Blocks until every replica's local copy of its master's slot
     * bitmap matches what that master itself reports — the state in
     * which no node in the cluster is still advertising a bitmap that
     * predates the most recent reassignment. See assignSlot() for the
     * steal-back this closes. Each side is read from the node that owns
     * the answer: the master's ranges from the master, the replica's
     * view of them from the replica.
     *
     * A slot claim reaches a given node only through a *direct* PING or
     * PONG from the claiming owner — the gossip section of a message
     * relays other nodes' addresses and flags, never their slot bitmaps.
     * Each node pings one random peer per second, plus any peer it has
     * not heard from within cluster-node-timeout/2 (7.5s at the
     * default), so one specific pair can go several seconds without
     * exchanging a ping at all. 30s sits well above that worst case;
     * bounded so a cluster that never settles fails with every lagging
     * replica named.
     */
    private function waitForReplicasToMirrorTheirMasters(): void
    {
        $lagging = [];

        for ($attempt = 0; $attempt < 300; $attempt++) {
            $lagging = [];

            foreach (self::discoverShards() as $shard) {
                $master = $shard['master'];
                $expected = self::rangesOfNodeAccordingTo($this->adminClientFor($master), $master['id']);

                foreach ($shard['replicas'] as $replica) {
                    $seen = self::rangesOfNodeAccordingTo($this->adminClientFor($replica), $master['id']);

                    if ($seen !== $expected) {
                        $lagging[] = "{$replica['host']}:{$replica['port']} sees {$master['id']} as [{$seen}], master reports [{$expected}]";
                    }
                }
            }

            if ($lagging === []) {
                return;
            }

            usleep(100000);
        }

        // Every node's own full table — epochs (7th column) and slot
        // attribution as *that* node believes them — is the only way to
        // tell, after the fact, which claim each lagging replica is
        // rejecting and why.
        $tables = '';

        foreach (self::discoverShards() as $shard) {
            foreach ([$shard['master'], ...$shard['replicas']] as $node) {
                $tables .= "\n--- CLUSTER NODES as seen by {$node['host']}:{$node['port']} ---\n"
                    . (string) $this->adminClientFor($node)->execute('CLUSTER', 'NODES');
            }
        }

        throw new RuntimeException(
            "Replicas never caught up with their masters' slot bitmaps within 30s:\n" . implode("\n", $lagging) . "\n" . $tables,
        );
    }

    /**
     * The slot ranges $client's own CLUSTER NODES table attributes to
     * $nodeId, as one normalized string — empty when the table has no
     * ranges for it. This is one node's *view* of another (or of
     * itself), which is exactly the distinction
     * waitForReplicasToMirrorTheirMasters() needs to compare.
     */
    private static function rangesOfNodeAccordingTo(RedisClient $client, string $nodeId): string
    {
        foreach (explode("\n", (string) $client->execute('CLUSTER', 'NODES')) as $line) {
            $fields = explode(' ', trim($line));

            if (($fields[0] ?? null) !== $nodeId) {
                continue;
            }

            $ranges = array_values(array_filter(
                array_slice($fields, 8),
                static fn (string $f): bool => $f !== '' && !str_starts_with($f, '['),
            ));
            sort($ranges);

            return implode(' ', $ranges);
        }

        return '';
    }

    /**
     * Leaves $master's configEpoch strictly greater than every other
     * master's — the condition under which its slot claims are accepted
     * by every third party — with every master's epoch pairwise
     * distinct. A single `CLUSTER BUMPEPOCH` guarantees neither: it
     * replies STILL and changes nothing when the node already *ties*
     * for the cluster maximum, and Redis then resolves the tie itself by
     * bumping whichever of the two colliding masters has the smaller
     * node id — the old owner about half the time, whose claim then
     * outranks the new owner's.
     *
     * Each pass bumps, then reads every master's own epoch from that
     * master itself (`CLUSTER INFO`, never seeds[0]'s possibly stale
     * view). A tie resolves within a gossip round: either $master won
     * the collision (done) or the other side did, in which case $master
     * no longer ties for the maximum and the next BUMPEPOCH raises it
     * above. Bounded so a cluster that never settles fails with every
     * epoch named instead of hanging.
     *
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $master
     */
    private function raiseEpochAboveEveryOtherMaster(array $master): void
    {
        $client = $this->adminClientFor($master);
        $seen = [];

        // 30s: a tie resolves only once the two colliding masters
        // exchange a direct ping, on the per-pair schedule
        // waitForReplicasToMirrorTheirMasters() documents.
        for ($attempt = 0; $attempt < 300; $attempt++) {
            $client->execute('CLUSTER', 'BUMPEPOCH');

            $own = self::ownEpochOf($client);
            $others = [];

            foreach (self::discoverMasters() as $other) {
                if ($other['id'] !== $master['id']) {
                    $others[$other['id']] = self::ownEpochOf($this->adminClientFor($other));
                }
            }

            // Two *other* masters at an equal epoch is a latent tie, not
            // harmless just because both sit below $master: whenever
            // those two next ping each other, Redis's collision handler
            // bumps one of them to currentEpoch+1 — above the epoch
            // $master was just raised to — and if that node's replica
            // still advertises a pre-move bitmap, $master's claim is
            // rejected there from then on. Break the tie now (bump one
            // side; it lands above $master, and the next pass raises
            // $master again) rather than leaving it to fire later.
            $tied = array_keys(array_filter(array_count_values($others), static fn (int $n): bool => $n > 1));

            if ($tied !== []) {
                $tiedId = array_search($tied[0], $others, true);
                $this->adminClientFor(self::masterById((string) $tiedId))->execute('CLUSTER', 'BUMPEPOCH');
            } elseif ($others === [] || $own > max($others)) {
                return;
            }

            $seen = ['own' => $own] + $others;
            usleep(100000);
        }

        throw new RuntimeException(
            "Could not raise {$master['id']}'s configEpoch above every other master's within 30s: "
                . json_encode($seen, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The epoch a node reports for itself — authoritative for that
     * node, unlike the copy any other node holds about it, which is only
     * as fresh as the last gossip message that carried it.
     */
    private static function ownEpochOf(RedisClient $client): int
    {
        $info = (string) $client->execute('CLUSTER', 'INFO');

        if (preg_match('/^cluster_my_epoch:(\d+)/m', $info, $matches) !== 1) {
            throw new RuntimeException("CLUSTER INFO reply carried no cluster_my_epoch line:\n{$info}");
        }

        return (int) $matches[1];
    }

    /**
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $oldOwner
     * @param array{id: string, host: string, port: int, ranges: list<array{0: int, 1: int}>} $newOwner
     */
    private function reassignEmptySlot(array $oldOwner, array $newOwner, int $slot): void
    {
        $this->assignSlot($oldOwner, $newOwner, $slot);
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

        // 500 tries at 200ms is 100 seconds of headroom for one pair,
        // plus 750 more tries (150s) for every *additional* simultaneous
        // pair. Convergence takes about a second on this image; the
        // ceiling is wide so that a failure is unmistakably a failure,
        // never a close call.
        //
        // A convergence that never arrives is not slowness: gossip
        // rejects a slot claim from a node whose configEpoch is not
        // higher than the current owner's, and a rejected claim stays
        // rejected until something raises that node's epoch. assignSlot()
        // prevents that on every direct reassignment. The diagnostics
        // gathered below on timeout — the expected owner's own answer,
        // its CPU/fork/latency stats, every node's pong_recv and epoch —
        // are what separate that from resource contention or a dead
        // cluster-bus link, which present the same symptom.
        //
        // This margin is shared by every pair together for as long as
        // any remain unconverged (see this method's own docblock for why
        // that's correct, not just convenient) — the per-pair scaling
        // only changes the total ceiling, never how the pairs are
        // checked.
        // Reusing the same relative slot offset across several tests
        // would compound this further, racing against an earlier
        // test's still-settling gossip for the identical slot, or
        // accumulating real slot-map fragmentation in one neighborhood
        // — avoided by every call site naming one of this file's own
        // widely-spaced TEST_SLOT_OFFSET_* constants explicitly (see
        // their docblock).
        $maxTries = 500 + (max(0, count($expectations) - 1) * 750);

        for ($i = 0; $i < $maxTries; $i++) {
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

            usleep(200000);
        }

        $descriptions = array_map(
            static fn (array $e): string => "slot {$e['slot']} -> {$e['ownerId']}",
            $expectations,
        );

        // Raw CLUSTER NODES from seeds[0], appended on timeout only —
        // this is the one place this class ever surfaces the unparsed
        // wire reply, deliberately: it carries the exact per-node
        // ping/pong timestamps and any migrating/importing markers that
        // CLUSTER SHARDS collapses away, which is the only way to tell
        // "gossip is still converging, just slowly" apart
        // from "this node's own state never actually changed at all"
        // after the fact, without a live debugging session. Never
        // parsed or asserted on here — this method's own contract stays
        // exactly what it already was; this is diagnostic text only, on
        // the one path that already means the test is about to fail.
        $rawNodes = (string) $seed->execute('CLUSTER', 'NODES');

        // Why, not only that — gathered from seeds[0] (the node this
        // wait polls) and from every still-unconverged expectation's
        // expected owner (its host:port parsed out of $rawNodes, the one
        // place this method has it; CLUSTER SHARDS never carries it).
        // seeds[0] is the node whose command loop this polling competes
        // with; the expected owner is the node whose PING/PONG handling
        // propagates the change. A CPU- or fork-starved node shows it
        // here directly — a command-latency spike, a slow fork(), a
        // background save in progress.
        $diagnostics = ["seeds[0]" => self::gatherNodeDiagnostics($seed)];

        foreach ($expectations as $expectation) {
            $target = self::hostPortForNodeId($rawNodes, $expectation['ownerId']);

            if ($target === null) {
                continue; // the raw dump itself doesn't even mention this id — nothing to connect to.
            }

            $label = "expected owner of slot {$expectation['slot']} ({$expectation['ownerId']})";

            try {
                $targetClient = createRedisClient(RedisConfig::fromUri("tcp://{$target['host']}:{$target['port']}"));

                // The check that separates a propagation delay from a
                // SETSLOT that never took local effect: ask the target
                // *directly* whether it believes it owns this slot,
                // rather than only asking seeds[0] what it has heard via
                // gossip. seeds[0]'s pong_recv timestamps in the raw
                // dump above show whether the cluster-bus link itself is
                // alive; if the target's own answer is "no," the problem
                // is not propagation.
                $selfClaims = self::shardsReportOwner(
                    $targetClient->execute('CLUSTER', 'SHARDS'),
                    $expectation['slot'],
                    $expectation['ownerId'],
                );

                $diagnostics[$label] = 'target itself claims this slot (queried directly): '
                    . ($selfClaims ? 'YES' : 'NO')
                    . "\n\n" . self::gatherNodeDiagnostics($targetClient);
            } catch (\Throwable $e) {
                $diagnostics[$label] = 'could not connect: ' . $e->getMessage();
            }
        }

        $diagnosticsText = '';

        foreach ($diagnostics as $label => $text) {
            $diagnosticsText .= "\n--- {$label} ---\n{$text}";
        }

        throw new RuntimeException(
            'Gossip never converged: seeds[0] still doesn\'t report ' . implode(', ', $descriptions)
                . ".\nRaw CLUSTER NODES from seeds[0]:\n" . $rawNodes
                . "\n" . $diagnosticsText,
        );
    }

    /**
     * The subset of a node's own diagnostics that distinguishes "this
     * server process was busy or stalled" from "nothing here explains
     * it" — never parsed or asserted on, for a human reading a failure.
     * Best-effort throughout: one failed sub-command (one this Redis
     * build doesn't support, say) degrades to a labeled error line
     * rather than losing every other section, since this runs only on a
     * path that is already failing for its own reason.
     */
    private static function gatherNodeDiagnostics(RedisClient $client): string
    {
        $sections = [];

        try {
            $info = (string) $client->execute('INFO');
            $wanted = [
                'connected_clients', 'blocked_clients', 'used_memory_human',
                'mem_fragmentation_ratio', 'loading', 'rdb_bgsave_in_progress',
                'aof_rewrite_in_progress', 'latest_fork_usec',
                'instantaneous_ops_per_sec', 'used_cpu_sys', 'used_cpu_user',
                'total_commands_processed', 'total_net_input_bytes',
            ];
            $lines = [];

            foreach (explode("\n", $info) as $line) {
                $line = rtrim($line, "\r");
                $key = explode(':', $line, 2)[0] ?? '';

                if (in_array($key, $wanted, true)) {
                    $lines[] = $line;
                }
            }

            $sections[] = "INFO (selected fields):\n" . implode("\n", $lines);
        } catch (\Throwable $e) {
            $sections[] = 'INFO failed: ' . $e->getMessage();
        }

        foreach (['command', 'fast-command', 'fork', 'expire-cycle'] as $event) {
            try {
                $history = $client->execute('LATENCY', 'HISTORY', $event);
                $sections[] = "LATENCY HISTORY {$event}:\n" . json_encode($history, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $sections[] = "LATENCY HISTORY {$event} failed: " . $e->getMessage();
            }
        }

        try {
            $slowlog = $client->execute('SLOWLOG', 'GET', '10');
            $sections[] = 'SLOWLOG GET 10:' . "\n" . json_encode($slowlog, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $sections[] = 'SLOWLOG GET failed: ' . $e->getMessage();
        }

        return implode("\n\n", $sections);
    }

    /**
     * Pulled from a raw CLUSTER NODES reply — the one place any of this
     * class's own methods still have a given node id's real host:port
     * once CLUSTER SHARDS (which never carries the wire-format
     * "id ip:port@busport ..." line) has already been consulted. `null`
     * for an id the raw text doesn't mention at all, which
     * gatherNodeDiagnostics()'s own caller treats as nothing to connect
     * to rather than an error.
     *
     * @return array{host: string, port: int}|null
     */
    private static function hostPortForNodeId(string $rawNodes, string $nodeId): ?array
    {
        foreach (explode("\n", $rawNodes) as $line) {
            $fields = explode(' ', trim($line));

            if (($fields[0] ?? null) !== $nodeId) {
                continue;
            }

            $addr = explode('@', $fields[1] ?? '')[0] ?? '';
            $colon = strrpos($addr, ':');

            if ($colon === false) {
                return null;
            }

            return [
                'host' => substr($addr, 0, $colon),
                'port' => (int) substr($addr, $colon + 1),
            ];
        }

        return null;
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
