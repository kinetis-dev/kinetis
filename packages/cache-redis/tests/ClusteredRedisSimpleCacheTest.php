<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests;

use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Kinetis\Config\Config;
use Kinetis\Config\Exception\MissingConfigException;
use Kinetis\SimpleCache\Cluster\ClusterEndpoint;
use Kinetis\SimpleCache\Cluster\ClusterTopology;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

use function Amp\Redis\createRedisClient;
use function Amp\Socket\socketConnector;

final class ClusteredRedisSimpleCacheTest extends TestCase
{
    private static ?SocketConnector $originalSocketConnector = null;

    /**
     * The MOVED-handling tests below call the real ClusterTopology::refresh()
     * — its result is deliberately swallowed (best-effort — see
     * guardKeyed()'s own docblock), but the connection attempt itself
     * still genuinely happens against an unreachable seed. Amp\Socket's
     * own default connector (Amp\Socket\socketConnector()) wraps every
     * attempt in a 3-try, 2/4-second-backoff retry by default, so an
     * unreachable target normally takes ~6 seconds to fail regardless of
     * any RedisConfig timeout — confirmed directly, not assumed:
     * shortening RedisConfig's own connect timeout had no effect,
     * proving the delay lives in the retry wrapper, not the connect
     * call itself. Swapped out for a plain, non-retrying connector for
     * the duration of this class only, restored afterward, so these
     * still-genuinely-network-touching-but-fast-failing tests stay
     * inside the unit suite's normal runtime instead of adding tens of
     * seconds to every run.
     */
    public static function setUpBeforeClass(): void
    {
        self::$originalSocketConnector = socketConnector();
        socketConnector(new DnsSocketConnector());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$originalSocketConnector !== null) {
            socketConnector(self::$originalSocketConnector);
        }
    }

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

    /**
     * A bracketed IPv6 seed, mixed with an ordinary IPv4 and a hostname
     * seed in the same comma-separated list — every seed is parsed and
     * accepted before any client is built, the same laziness as the
     * plain-IPv4 case above.
     */
    public function test_from_config_returns_an_instance_when_seeds_include_a_bracketed_ipv6_address(): void
    {
        $cache = ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => '[2001:db8::10]:6379, 10.0.0.2:6379 ,redis-node.internal:6379',
        ]));

        self::assertInstanceOf(ClusteredRedisSimpleCache::class, $cache);
    }

    /**
     * An unbracketed IPv6 address is genuinely ambiguous (which colon is
     * the port separator?) — rejected at fromConfig() time, before
     * ClusterTopology or any RedisClient is ever constructed, the exact
     * bug this validation exists to close.
     */
    public function test_from_config_rejects_an_unbracketed_ipv6_seed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Redis Cluster endpoint "2001:db8::10:6379"');

        ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => '2001:db8::10:6379',
        ]));
    }

    public function test_from_config_rejects_an_empty_seed_entry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => '10.0.0.1:6379,,10.0.0.2:6379',
        ]));
    }

    public function test_from_config_rejects_a_seed_with_a_port_outside_the_valid_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port 70000 is outside the valid 1-65535 range');

        ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => '10.0.0.1:70000',
        ]));
    }

    public function test_from_config_rejects_a_non_positive_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_TIMEOUT must be a positive number of seconds');

        ClusteredRedisSimpleCache::fromConfig(new Config([
            'REDIS_CLUSTER' => 'true',
            'REDIS_CLUSTER_SEEDS' => 'node1:7001',
            'REDIS_TIMEOUT' => '0',
        ]));
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
     * guard() backs clear()'s zero-arg FLUSHDB fan-out — no slot routing,
     * no redirect handling. Directly testable with no network at all,
     * since the fake operation closure throws immediately.
     */
    public function test_guard_wraps_a_query_exception_as_a_cache_exception(): void
    {
        $cache = $this->cacheWithUnreachableSeed();
        $guard = new ReflectionMethod($cache, 'guard');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            'Redis "clear" failed for key "*": WRONGTYPE Operation against a key holding the wrong kind of value',
        );

        $guard->invoke($cache, 'clear', '*', function (): never {
            throw new QueryException('WRONGTYPE Operation against a key holding the wrong kind of value');
        });
    }

    public function test_guard_wraps_a_generic_redis_exception_as_a_cache_exception(): void
    {
        $cache = $this->cacheWithUnreachableSeed();
        $guard = new ReflectionMethod($cache, 'guard');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis "clear" failed for key "*": Connection lost');

        $guard->invoke($cache, 'clear', '*', function (): never {
            throw new RedisException('Connection lost');
        });
    }

    /**
     * guardKeyed()'s non-redirect path never touches the topology at
     * all beyond the one already-resolved (pre-seeded, no real network)
     * client — ClusterRedirect::tryParse() returns null immediately for
     * an unrelated error, so the exception wraps without ever reaching
     * refresh()/ASKING.
     */
    public function test_guard_keyed_wraps_a_non_redirect_query_exception_as_a_cache_exception(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            'Redis "get" failed for key "some-key": WRONGTYPE Operation against a key holding the wrong kind of value',
        );

        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client): never {
            throw new QueryException('WRONGTYPE Operation against a key holding the wrong kind of value');
        });
    }

    public function test_guard_keyed_wraps_a_generic_redis_exception_as_a_cache_exception(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis "get" failed for key "some-key": Connection lost');

        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client): never {
            throw new RedisException('Connection lost');
        });
    }

    /**
     * A malformed redirect (ClusterRedirect::tryParse() itself throwing)
     * wraps as a CacheException too — reachable, and rejected, before
     * guardKeyed() ever tries to act on it (no refresh(), no dedicated
     * client, no network at all).
     */
    public function test_guard_keyed_wraps_a_malformed_moved_redirect_as_a_cache_exception(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis "get" failed for key "some-key": Malformed MOVED redirect: invalid slot "abc".');

        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client): never {
            throw new QueryException('MOVED abc 10.0.0.5:7000');
        });
    }

    public function test_guard_keyed_wraps_a_malformed_ask_redirect_as_a_cache_exception(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            'Redis "get" failed for key "some-key": Malformed ASK redirect: slot 99999 is outside the valid 0-16383 range.',
        );

        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client): never {
            throw new QueryException('ASK 99999 10.0.0.5:7000');
        });
    }

    /**
     * MOVED and ASK are dispatched differently — proven here purely by
     * which malformation message reaches the caller, since a
     * kind-detection bug (treating an ASK message as MOVED, say) would
     * surface as the wrong redirect keyword in the wrapped message even
     * though both inputs are otherwise identically shaped.
     */
    public function test_guard_keyed_distinguishes_moved_from_ask_by_kind(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');

        foreach (['MOVED', 'ASK'] as $kind) {
            try {
                $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use ($kind): never {
                    throw new QueryException("{$kind} not-a-slot 10.0.0.5:7000");
                });
                self::fail('Expected a CacheException.');
            } catch (CacheException $e) {
                self::assertStringContainsString("Malformed {$kind} redirect", $e->getMessage());
            }
        }
    }

    /**
     * A redirect naming a slot other than Crc16::slotFor($key) is
     * malformed for *this* operation regardless of how well-formed the
     * message otherwise is — rejected before guardKeyed() ever acts on
     * it (refreshes, builds a client, or retries).
     */
    public function test_guard_keyed_rejects_a_redirect_naming_a_different_slot(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');
        $wrongSlot = ($realSlot + 1) % 16384;

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            "Redis \"get\" failed for key \"some-key\": MOVED redirect names slot {$wrongSlot}, but \"some-key\" hashes to slot {$realSlot}.",
        );

        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use ($wrongSlot): never {
            throw new QueryException("MOVED {$wrongSlot} 10.0.0.5:7000");
        });
    }

    /**
     * A genuine ownership swing (the slot moving since the last
     * refresh, and the individual key *also* still migrating out of the
     * new owner) is a real, valid sequence — this test proves it stays
     * distinct from an actual repeating cycle: the mismatched-slot guard
     * above doesn't fire (both redirects name the same, correct slot),
     * and neither does the loop detector (the two redirects target
     * different nodes).
     *
     * The retry itself needs zero real network reachability to prove
     * this: MOVED's own retry deliberately tolerates refresh() failing
     * (this topology's one seed is unreachable) by going straight to the
     * redirect's own reported target via clientFor(), which — like
     * every client-building path in this codebase — never connects
     * eagerly. The operation closure never touches $client at all, so
     * its real reachability is irrelevant to what this test proves.
     */
    public function test_guard_keyed_moved_retries_against_the_redirects_own_target_even_when_refresh_fails(): void
    {
        $builtEndpoints = [];
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:1')], // the one seed, deliberately unreachable -- refresh() always fails
            function (ClusterEndpoint $endpoint) use (&$builtEndpoints): RedisClient {
                $builtEndpoints[] = $endpoint->key();

                return createRedisClient(RedisConfig::fromUri($endpoint->toUri()));
            },
        );
        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 1, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        $cache = new ClusteredRedisSimpleCache($topology);
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');

        $clientFor = new ReflectionMethod($topology, 'clientFor');
        $expectedTargetClient = $clientFor->invoke($topology, ClusterEndpoint::parse('127.0.0.1:2'));

        $calls = 0;
        $secondCallClient = null;
        $result = $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$calls, &$secondCallClient, $realSlot) {
            $calls++;

            if ($calls === 1) {
                throw new QueryException("MOVED {$realSlot} 127.0.0.1:2");
            }

            $secondCallClient = $client;

            return 'success';
        });

        self::assertSame('success', $result);
        self::assertSame(2, $calls);
        self::assertSame(
            $expectedTargetClient,
            $secondCallClient,
            'the retry must use the redirect\'s own reported target, not whatever a failed refresh() could have supplied',
        );
        // refresh() only ever got as far as the one (unreachable) seed
        // before failing — proving the retry's success came from the
        // redirect target directly, never from refresh() having
        // secretly succeeded some other way.
        self::assertSame(['127.0.0.1:1', '127.0.0.1:2'], $builtEndpoints);
    }

    /**
     * The same kind+target redirect reported twice for one operation is
     * a real, distinct failure mode — detected the moment it repeats
     * (here, on the 2nd occurrence), not by spending the whole
     * MAX_REDIRECT_ATTEMPTS budget bouncing between the same nodes.
     * Uses MOVED for the identical reason the test above does: its
     * retry needs no real reachable target.
     */
    public function test_guard_keyed_detects_a_repeated_redirect_as_a_loop_before_exhausting_the_bound(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');
        $calls = 0;

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage(
            'Redis "get" failed for key "some-key": Redirect loop detected: MOVED to 10.0.0.5:7000 was already followed for this operation.',
        );

        try {
            $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$calls, $realSlot): never {
                $calls++;

                throw new QueryException("MOVED {$realSlot} 10.0.0.5:7000");
            });
        } finally {
            self::assertSame(2, $calls, 'the loop must be caught on its 2nd occurrence, not after spending the full redirect budget');
        }
    }

    /**
     * The counterpart to the loop-detection test above: a chain of
     * MAX_REDIRECT_ATTEMPTS genuinely *distinct* redirects (a different
     * target every time) must still exhaust the bound cleanly — the
     * loop detector must never mistake "ran out of attempts" for "found
     * a repeat" when nothing actually repeated.
     */
    public function test_guard_keyed_a_non_repeating_chain_exhausts_the_bound_without_being_flagged_as_a_loop(): void
    {
        [, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');
        $calls = 0;

        $this->expectException(CacheException::class);
        $this->expectExceptionMessage('Redis "get" failed for key "some-key": Exceeded 6 cluster redirect attempts.');

        try {
            $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$calls, $realSlot): never {
                $calls++;
                $port = 7000 + $calls; // a different target every single time -- never repeats

                throw new QueryException("MOVED {$realSlot} 10.0.0.5:{$port}");
            });
        } finally {
            self::assertSame(6, $calls, 'a non-repeating chain must spend the full redirect budget, not stop early');
        }
    }

    /**
     * MOVED's own target is recorded durably (ClusterTopology::
     * applyMovedOverride()), not just used for the one operation that
     * discovered it — a completely separate, later guardKeyed() call for
     * the same key must resolve directly to the reported target,
     * needing zero MOVED reply of its own. Needs no real network
     * reachability: the second call's operation closure never touches
     * $client at all, only records which instance it received.
     */
    public function test_guard_keyed_moved_records_a_durable_override_for_later_operations(): void
    {
        [$topology, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');

        $clientFor = new ReflectionMethod($topology, 'clientFor');
        $expectedTargetClient = $clientFor->invoke($topology, ClusterEndpoint::parse('127.0.0.1:2'));

        $firstCallAttempts = 0;
        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$firstCallAttempts, $realSlot) {
            $firstCallAttempts++;

            if ($firstCallAttempts === 1) {
                throw new QueryException("MOVED {$realSlot} 127.0.0.1:2");
            }

            return 'first-call-result';
        });
        self::assertSame(2, $firstCallAttempts, 'sanity check: the first call really did need one MOVED redirect');

        $secondCallAttempts = 0;
        $secondCallClient = null;
        $result = $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$secondCallAttempts, &$secondCallClient) {
            $secondCallAttempts++;
            $secondCallClient = $client;

            return 'second-call-result';
        });

        self::assertSame('second-call-result', $result);
        self::assertSame(1, $secondCallAttempts, 'the second, separate call must reach the target directly, with zero redirects of its own');
        self::assertSame($expectedTargetClient, $secondCallClient);
    }

    /**
     * A connection-level failure against the overlay's own target — no
     * redirect reply at all, just the operation itself failing outright
     * — is the one signal that justifies giving up on an override:
     * ClusterTopology::invalidateMovedOverrideIfTarget() must be called
     * so a later, separate operation for the same slot doesn't keep
     * retrying a connection that will never succeed again. The failing
     * client here genuinely *is* the override's own current target (the
     * second call's own guardKeyed() resolves it directly via
     * nodeForSlot(), since the first call already installed it), which
     * is exactly the case invalidateMovedOverrideIfTarget() must accept.
     */
    public function test_guard_keyed_a_connection_failure_against_the_overlay_target_invalidates_it(): void
    {
        [$topology, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');

        $firstCallAttempts = 0;
        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$firstCallAttempts, $realSlot) {
            $firstCallAttempts++;

            if ($firstCallAttempts === 1) {
                throw new QueryException("MOVED {$realSlot} 127.0.0.1:2");
            }

            return 'ok';
        });

        try {
            $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client): never {
                throw new RedisException('Connection reset by peer');
            });
            self::fail('Expected a CacheException.');
        } catch (CacheException) {
            // expected
        }

        $overlayProp = new ReflectionProperty($topology, 'movedOverlay');
        self::assertArrayNotHasKey(
            $realSlot,
            $overlayProp->getValue($topology),
            'a connection-level failure against the override\'s own target must invalidate the slot\'s override rather than leaving it pinned forever',
        );
    }

    /**
     * A later, separate operation whose own MOVED reply comes from the
     * *overlaid target itself* is the documented, correct way an
     * override self-corrects when the slot moves again — a plain
     * overwrite, not an accumulation.
     */
    public function test_guard_keyed_a_later_moved_reply_replaces_the_earlier_override(): void
    {
        [$topology, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');

        $firstCallAttempts = 0;
        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$firstCallAttempts, $realSlot) {
            $firstCallAttempts++;

            if ($firstCallAttempts === 1) {
                throw new QueryException("MOVED {$realSlot} 127.0.0.1:2");
            }

            return 'ok';
        });

        $secondCallAttempts = 0;
        $secondCallClient = null;
        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$secondCallAttempts, &$secondCallClient, $realSlot) {
            $secondCallAttempts++;

            if ($secondCallAttempts === 1) {
                throw new QueryException("MOVED {$realSlot} 127.0.0.1:3"); // the previously-overlaid target itself says it moved again
            }

            $secondCallClient = $client;

            return 'ok';
        });

        $clientFor = new ReflectionMethod($topology, 'clientFor');
        self::assertSame(
            $clientFor->invoke($topology, ClusterEndpoint::parse('127.0.0.1:3')),
            $secondCallClient,
            'the override must have been replaced, not merely added to',
        );

        $thirdCallAttempts = 0;
        $thirdCallClient = null;
        $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use (&$thirdCallAttempts, &$thirdCallClient) {
            $thirdCallAttempts++;
            $thirdCallClient = $client;

            return 'ok';
        });
        self::assertSame(1, $thirdCallAttempts, 'a third, separate call must resolve directly to the latest target, with zero redirects');
        self::assertSame($clientFor->invoke($topology, ClusterEndpoint::parse('127.0.0.1:3')), $thirdCallClient);
    }

    /**
     * ASK is a transient, per-key exception, never a durable ownership
     * change — it must never install a moved override, and the stable
     * topology (nodeForSlot()/allMasters()) must come out identical
     * whether or not the ASK attempt itself succeeded.
     */
    public function test_guard_keyed_ask_never_installs_a_moved_override_or_disturbs_stable_routing(): void
    {
        [$topology, $cache] = $this->cacheWithPreSeededTopology();
        $guardKeyed = new ReflectionMethod($cache, 'guardKeyed');
        $realSlot = Crc16::slotFor('some-key');

        $mastersBefore = $topology->allMasters();
        $nodeForSlot = new ReflectionMethod($topology, 'nodeForSlot');
        $clientBefore = $nodeForSlot->invoke($topology, $realSlot);

        try {
            $guardKeyed->invoke($cache, 'get', 'some-key', function (RedisClient $client) use ($realSlot): never {
                // 127.0.0.1:1 is the same unreachable seed this topology
                // was already built with -- ASKING against it fails,
                // which is fine: this test only asserts on the stable
                // topology's own state, never on the operation's result.
                throw new QueryException("ASK {$realSlot} 127.0.0.1:1");
            });
            self::fail('Expected a CacheException from the failed ASKING call.');
        } catch (CacheException) {
            // expected
        }

        self::assertSame($mastersBefore, $topology->allMasters(), 'ASK must never change allMasters()\'s own result');
        self::assertSame($clientBefore, $nodeForSlot->invoke($topology, $realSlot), 'ASK must never change nodeForSlot()\'s own result for the affected slot');
    }

    /**
     * ASK's own genuinely network-dependent paths (ASKING succeeding
     * against a real target, then the retried operation itself
     * succeeding) need a real reachable target to prove end to end; an
     * unreachable one only ever proves the *first* attempt's ASKING
     * failure. Those happy paths, plus the full real MOVED/ASK/
     * sequencing/no-mutation proofs, live in
     * ClusteredRedisSimpleCacheIntegrationTest against a real
     * live-migrating cluster, not here.
     *
     * @return array{0: ClusterTopology, 1: ClusteredRedisSimpleCache}
     */
    private function cacheWithPreSeededTopology(): array
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:1')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        // Seeded directly via reflection, the same pattern
        // ClusterTopologyTest's own helpers use, so nodeForSlot() finds
        // real (never-yet-connected) ranges without ever calling
        // refresh() itself — the fake operation closures below throw
        // before touching $client at all, so its own connectivity is
        // irrelevant.
        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            [
                'slots', [0, 16383],
                'nodes', [
                    ['id', 'node-1', 'port', 1, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ]);

        return [$topology, new ClusteredRedisSimpleCache($topology)];
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
