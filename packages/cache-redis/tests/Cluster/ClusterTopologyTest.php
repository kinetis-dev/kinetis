<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Cluster;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use InvalidArgumentException;
use Kinetis\Async\Timer;
use Kinetis\SimpleCache\Cluster\ClusterEndpoint;
use Kinetis\SimpleCache\Cluster\ClusterTopology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function Amp\Redis\createRedisClient;
use function Kinetis\Async\concurrently;

/**
 * ClusterTopology talks to a real cluster for refresh() itself (CLUSTER
 * SHARDS over the network), which this suite can't fake — RedisClient is
 * final, so there's no mocking it either. What's genuinely unit-testable
 * without a live cluster is everything else: parseShards() is a pure,
 * network-free function once the raw CLUSTER SHARDS reply is in hand
 * (extracted from applyShards() specifically for this), and
 * nodeForSlot()/clientFor()/allMasters() can be exercised directly by
 * constructing the topology with ranges already applied via a real,
 * never-connected RedisClient — safe because Amp\Redis\createRedisClient()
 * never opens a socket eagerly (confirmed elsewhere in this codebase; the
 * connection only opens on the first command actually executed).
 *
 * CLUSTER SHARDS is network protocol input, not trusted PHP data —
 * parseShards() validates the complete reply shape (alternating
 * key/value lists at every level, slot boundaries, node fields, and the
 * resulting map's own completeness) before indexing or casting any of
 * it, so this suite is also where every malformed-reply shape is pinned
 * — see malformedShardsCases() below.
 *
 * The real refresh()/MOVED-retry path against a live 6-node cluster is
 * verified separately, by hand — not part of this committed suite.
 */
final class ClusterTopologyTest extends TestCase
{
    public function test_parses_a_single_shard_single_range_reply(): void
    {
        $shards = [
            [
                'slots', [0, 16383],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertCount(1, $parsed['ranges']);
        self::assertSame(0, $parsed['ranges'][0]['start']);
        self::assertSame(16383, $parsed['ranges'][0]['end']);
        self::assertSame('127.0.0.1:7000', $parsed['ranges'][0]['endpoint']->key());
        self::assertCount(1, $parsed['masterEndpoints']);
        self::assertSame('127.0.0.1:7000', $parsed['masterEndpoints'][0]->key());
    }

    public function test_parses_multiple_shards_each_contributing_their_own_range(): void
    {
        $shards = [
            [
                'slots', [0, 5460],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                    ['id', 'node-1-replica', 'port', 7003, 'ip', '127.0.0.1', 'role', 'replica'],
                ],
            ],
            [
                'slots', [5461, 10922],
                'nodes', [
                    ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
            [
                'slots', [10923, 16383],
                'nodes', [
                    ['id', 'node-3', 'port', 7002, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame(
            [
                ['start' => 0, 'end' => 5460],
                ['start' => 5461, 'end' => 10922],
                ['start' => 10923, 'end' => 16383],
            ],
            array_map(
                static fn (array $range): array => ['start' => $range['start'], 'end' => $range['end']],
                $parsed['ranges'],
            ),
        );
        self::assertSame(
            ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
            array_map(
                static fn (array $range): string => $range['endpoint']->key(),
                $parsed['ranges'],
            ),
        );
        self::assertSame(
            ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
            array_map(static fn (ClusterEndpoint $e): string => $e->key(), $parsed['masterEndpoints']),
        );
    }

    /**
     * Discovered IPv6 masters go straight through fromParts() from
     * CLUSTER SHARDS's own already-separate ip/port fields — never
     * joined into a string and re-parsed, so this proves the whole
     * parseShards() path preserves an IPv6 address correctly.
     */
    public function test_parses_a_discovered_ipv6_master(): void
    {
        $shards = [
            [
                'slots', [0, 16383],
                'nodes', [
                    ['id', 'node-1', 'port', 6379, 'ip', '2001:db8::10', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame('2001:db8::10', $parsed['ranges'][0]['endpoint']->host);
        self::assertSame(6379, $parsed['ranges'][0]['endpoint']->port);
        self::assertSame('[2001:db8::10]:6379', $parsed['ranges'][0]['endpoint']->key());
        self::assertSame('tcp://[2001:db8::10]:6379', $parsed['ranges'][0]['endpoint']->toUri());
        self::assertSame('[2001:db8::10]:6379', $parsed['masterEndpoints'][0]->key());
    }

    /**
     * A real, migrating-slot shape: CLUSTER SHARDS can report more than
     * one [start, end] pair per shard, not just one contiguous block.
     * Two shards, each with two disjoint ranges, interleaving to cover
     * the full keyspace between them — proves both the disjoint-pairs
     * mechanic and that ranges from different shards sort and merge
     * correctly regardless of reply order.
     */
    public function test_a_shard_with_multiple_disjoint_slot_ranges_produces_one_range_entry_each(): void
    {
        $shards = [
            [
                'slots', [0, 100, 200, 300],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
            [
                'slots', [101, 199, 301, 16383],
                'nodes', [
                    ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertCount(4, $parsed['ranges']);
        self::assertSame(0, $parsed['ranges'][0]['start']);
        self::assertSame(100, $parsed['ranges'][0]['end']);
        self::assertSame(200, $parsed['ranges'][1]['start']);
        self::assertSame(300, $parsed['ranges'][1]['end']);
        self::assertSame('127.0.0.1:7000', $parsed['ranges'][0]['endpoint']->key());
        self::assertSame('127.0.0.1:7000', $parsed['ranges'][1]['endpoint']->key());
        self::assertCount(2, $parsed['masterEndpoints']);
    }

    /**
     * One shard, two disjoint ranges together spanning the whole
     * keyspace — the same master must not be counted twice in
     * masterEndpoints.
     */
    public function test_master_addresses_are_deduplicated(): void
    {
        $shards = [
            [
                'slots', [0, 8000, 8001, 16383],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertCount(1, $parsed['masterEndpoints']);
    }

    /** findMaster() must pick the node with role "master" and ignore a replica, regardless of which comes first in the reply. */
    public function test_finds_the_master_among_mixed_role_nodes(): void
    {
        $shards = [
            [
                'slots', [0, 16383],
                'nodes', [
                    ['id', 'node-1-replica', 'port', 7003, 'ip', '127.0.0.1', 'role', 'replica'],
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame('127.0.0.1:7000', $parsed['masterEndpoints'][0]->key());
    }

    /**
     * A shard with no current master (a real, transient state — mid-
     * failover, no node currently holds the role) contributes no ranges
     * of its own, which leaves those slots uncovered. Silently accepting
     * that as a successful refresh would let nodeForSlot() route
     * ambiguously or fail later for reasons this call site can't
     * explain — the whole parseShards() call fails instead, immediately
     * and clearly, the moment the resulting map turns out incomplete.
     */
    public function test_a_shard_with_no_master_leaves_a_gap_that_fails_the_completeness_check(): void
    {
        $shards = [
            [
                'slots', [0, 100],
                'nodes', [
                    ['id', 'node-1-replica', 'port', 7003, 'ip', '127.0.0.1', 'role', 'replica'],
                ],
            ],
            [
                'slots', [101, 16383],
                'nodes', [
                    ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('CLUSTER SHARDS reported an incomplete slot map: no node owns slots 0-100.');

        ClusterTopology::parseShards($shards);
    }

    public function test_an_empty_reply_is_rejected_as_incomplete(): void
    {
        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('CLUSTER SHARDS reported an incomplete slot map: no node owns slots 0-16383.');

        ClusterTopology::parseShards([]);
    }

    public function test_a_non_array_reply_throws(): void
    {
        $this->expectException(RedisException::class);

        ClusterTopology::parseShards('not an array');
    }

    /**
     * Every way a CLUSTER SHARDS reply can be malformed and still reach
     * parseShards() as *some* PHP value — this is network protocol
     * input, not trusted data, so every one of these must fail with a
     * clean RedisException and never a PHP warning/TypeError escaping
     * first (assertNoErrorsOrWarnings() below enforces the latter half).
     *
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function malformedShardsCases(): iterable
    {
        $validMaster = ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'];

        yield 'odd-length shard pair list' => [
            [['slots', [0, 16383], 'nodes']],
            'Unexpected CLUSTER SHARDS reply shape: an odd-length key/value list.',
        ];

        yield 'shard missing slots' => [
            [['nodes', [$validMaster]]],
            'CLUSTER SHARDS reply is missing a valid "slots" list.',
        ];

        yield 'shard missing nodes' => [
            [['slots', [0, 16383]]],
            'CLUSTER SHARDS reply is missing a valid "nodes" list.',
        ];

        yield 'non-array nodes value' => [
            [['slots', [0, 16383], 'nodes', 'not-an-array']],
            'CLUSTER SHARDS reply is missing a valid "nodes" list.',
        ];

        yield 'odd-length slots list' => [
            [['slots', [0, 100, 200], 'nodes', [$validMaster]]],
            'CLUSTER SHARDS reply has an odd-length "slots" list.',
        ];

        yield 'non-integer slot boundary' => [
            [['slots', ['0', 16383], 'nodes', [$validMaster]]],
            'CLUSTER SHARDS reply contains an invalid slot boundary',
        ];

        yield 'slot boundary above 16383' => [
            [['slots', [0, 20000], 'nodes', [$validMaster]]],
            'CLUSTER SHARDS reply contains an invalid slot boundary',
        ];

        yield 'negative slot boundary' => [
            [['slots', [-1, 100], 'nodes', [$validMaster]]],
            'CLUSTER SHARDS reply contains an invalid slot boundary',
        ];

        yield 'reversed slot range (start > end)' => [
            [['slots', [100, 0], 'nodes', [$validMaster]]],
            'CLUSTER SHARDS reply contains an invalid slot boundary',
        ];

        yield 'master ip as a non-scalar' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', 7000, 'ip', ['not', 'scalar'], 'role', 'master']]]],
            'CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.',
        ];

        yield 'master port as a non-scalar' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', ['bad'], 'ip', '127.0.0.1', 'role', 'master']]]],
            'CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.',
        ];

        // A lossy scalar cast (a float or bool coerced to int, an int
        // coerced to string) would silently accept a value the real
        // RESP wire shape never sends — ip is always a string, port
        // always an int — so each is rejected outright rather than
        // coerced.
        yield 'master port as a float' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', 6379.9, 'ip', '127.0.0.1', 'role', 'master']]]],
            'CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.',
        ];

        yield 'master port as a bool' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', true, 'ip', '127.0.0.1', 'role', 'master']]]],
            'CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.',
        ];

        yield 'master ip as an int' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', 7000, 'ip', 2130706433, 'role', 'master']]]],
            'CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.',
        ];

        yield 'node with role as a non-scalar' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', 7000, 'ip', '127.0.0.1', 'role', ['not', 'a', 'string']]]]],
            'CLUSTER SHARDS reported a node with a missing or non-string "role" field.',
        ];

        yield 'node with a missing role field' => [
            [['slots', [0, 16383], 'nodes', [['id', 'n', 'port', 7000, 'ip', '127.0.0.1']]]],
            'CLUSTER SHARDS reported a node with a missing or non-string "role" field.',
        ];

        // The alternating maps at every level are string-keyed by the
        // real protocol — never a bare int, never a repeated field.
        yield 'node with an integer map key' => [
            [['slots', [0, 16383], 'nodes', [[0, 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]]],
            'Unexpected CLUSTER SHARDS reply shape: a non-string map key.',
        ];

        yield 'node with a duplicate map key' => [
            [['slots', [0, 16383], 'nodes', [
                ['id', 'n', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master', 'role', 'replica'],
            ]]],
            'Unexpected CLUSTER SHARDS reply shape: duplicate map key "role".',
        ];

        // findMaster() validates every node in the list, not only up to
        // whichever one first satisfies the master search — a malformed
        // node reported after an already-found master must still fail
        // the whole shard, not be silently skipped once the search is
        // "done".
        yield 'a malformed node reported after an otherwise valid first master' => [
            [['slots', [0, 16383], 'nodes', [
                ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ['id', 'node-2', 'port'],
            ]]],
            'Unexpected CLUSTER SHARDS reply shape: an odd-length key/value list.',
        ];

        // Two nodes both claiming the master role is ambiguous topology
        // input, not a valid shard with a redundant report — the
        // *count* of masters found is what decides validity, never
        // merely "was one found".
        yield 'two nodes both claiming the master role' => [
            [['slots', [0, 16383], 'nodes', [
                ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
            ]]],
            'CLUSTER SHARDS reported a shard with more than one node claiming the master role.',
        ];

        yield 'gap between two shards' => [
            [
                ['slots', [0, 100], 'nodes', [$validMaster]],
                ['slots', [200, 16383], 'nodes', [['id', 'n2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master']]],
            ],
            'CLUSTER SHARDS reported an incomplete slot map: no node owns slots 101-199.',
        ];

        yield 'overlapping shards' => [
            [
                ['slots', [0, 100], 'nodes', [$validMaster]],
                ['slots', [50, 16383], 'nodes', [['id', 'n2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master']]],
            ],
            'CLUSTER SHARDS reported an ambiguous slot map: slot 50 is claimed by more than one node.',
        ];
    }

    #[DataProvider('malformedShardsCases')]
    public function test_rejects_a_malformed_reply(mixed $shards, string $expectedMessage): void
    {
        try {
            ClusterTopology::parseShards($shards);
            self::fail('parseShards() was expected to throw.');
        } catch (RedisException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    /**
     * Every malformed-reply case above must fail cleanly — a stray
     * "Undefined array key"/"Array to string conversion" warning, or a
     * raw TypeError from indexing past the end of a list, would mean a
     * real crack in the validation this class exists to provide.
     */
    #[DataProvider('malformedShardsCases')]
    public function test_a_malformed_reply_never_emits_a_php_warning(mixed $shards, string $expectedMessage): void
    {
        set_error_handler(static function (int $errno, string $errstr): bool {
            self::fail("Unexpected PHP warning/notice while parsing a malformed reply: {$errstr}");
        });

        try {
            ClusterTopology::parseShards($shards);
        } catch (RedisException) {
            // Expected — test_rejects_a_malformed_reply() already covers
            // the exception itself and its message; this test is purely
            // about whether a warning escaped on the way there.
        } finally {
            restore_error_handler();
        }

        self::assertTrue(true, "No PHP warning was emitted while rejecting: {$expectedMessage}");
    }

    public function test_node_for_slot_returns_the_client_owning_that_slot(): void
    {
        [$topology, $clientA, $clientB] = $this->topologyWithTwoRanges();

        self::assertSame($clientA, $topology->nodeForSlot(0));
        self::assertSame($clientA, $topology->nodeForSlot(100));
        self::assertSame($clientB, $topology->nodeForSlot(101));
        self::assertSame($clientB, $topology->nodeForSlot(16383));
    }

    /**
     * A complete topology (the only kind parseShards() ever produces)
     * covers every valid slot, so the only way to reach this branch is a
     * slot number outside the valid 0-16383 range altogether —
     * nodeForSlot() itself doesn't validate its own input, so this is
     * its own defensive fallback being exercised directly.
     */
    public function test_node_for_slot_throws_when_no_range_covers_the_slot(): void
    {
        [$topology] = $this->topologyWithTwoRanges();

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('No cluster node owns slot 20000.');

        $topology->nodeForSlot(20000);
    }

    public function test_client_for_memoizes_by_address(): void
    {
        $built = [];
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            function (ClusterEndpoint $endpoint) use (&$built): RedisClient {
                $client = createRedisClient(RedisConfig::fromUri($endpoint->toUri()));
                $built[] = $endpoint->key();

                return $client;
            },
        );

        $first = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7000'));
        $second = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7000'));

        self::assertSame($first, $second);
        self::assertSame(['127.0.0.1:7000'], $built);
    }

    public function test_client_for_builds_a_distinct_client_per_distinct_address(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $a = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7000'));
        $b = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7001'));

        self::assertNotSame($a, $b);
    }

    /**
     * A bracketed IPv6 endpoint memoizes correctly too — key() is what
     * clientFor() dedupes on, and it must agree for two ClusterEndpoint
     * instances built from the identical host/port.
     */
    public function test_client_for_memoizes_an_ipv6_endpoint_by_its_key(): void
    {
        $built = [];
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('[2001:db8::10]:6379')],
            function (ClusterEndpoint $endpoint) use (&$built): RedisClient {
                $built[] = $endpoint->toUri();

                return createRedisClient(RedisConfig::fromUri($endpoint->toUri()));
            },
        );

        $first = $topology->clientFor(ClusterEndpoint::fromParts('2001:db8::10', 6379));
        $second = $topology->clientFor(ClusterEndpoint::parse('[2001:db8::10]:6379'));

        self::assertSame($first, $second);
        self::assertSame(['tcp://[2001:db8::10]:6379'], $built);
    }

    /**
     * buildDedicatedClient() is the one client-building path that must
     * NEVER memoize — an ASK redirect's ASKING-then-retry sequence needs
     * a connection nothing else can share, since a shared, multiplexed
     * connection would let an unrelated concurrent Fiber's command land
     * on the wire between ASKING and the retried operation.
     */
    public function test_build_dedicated_client_never_memoizes(): void
    {
        $built = [];
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            function (ClusterEndpoint $endpoint) use (&$built): RedisClient {
                $client = createRedisClient(RedisConfig::fromUri($endpoint->toUri()));
                $built[] = $endpoint->key();

                return $client;
            },
        );

        $first = $topology->buildDedicatedClient(ClusterEndpoint::parse('127.0.0.1:7000'));
        $second = $topology->buildDedicatedClient(ClusterEndpoint::parse('127.0.0.1:7000'));

        self::assertNotSame($first, $second, 'buildDedicatedClient() must build a fresh instance every call');
        self::assertSame(['127.0.0.1:7000', '127.0.0.1:7000'], $built);
    }

    /**
     * The two client-building paths are genuinely independent: a
     * dedicated client built for an address clientFor() has already
     * memoized is still a distinct instance, and neither call registers
     * with the other's bookkeeping.
     */
    public function test_build_dedicated_client_is_independent_of_client_for(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $memoized = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7000'));
        $dedicated = $topology->buildDedicatedClient(ClusterEndpoint::parse('127.0.0.1:7000'));
        $memoizedAgain = $topology->clientFor(ClusterEndpoint::parse('127.0.0.1:7000'));

        self::assertNotSame($memoized, $dedicated);
        self::assertSame($memoized, $memoizedAgain, 'building a dedicated client must not disturb clientFor()\'s own memoization');
    }

    /**
     * applyMovedOverride() is the durable fix behind a MOVED retry:
     * without it, a later, separate call for the same slot would keep
     * resolving to the old range owner and pay another redirect round
     * trip every time. nodeForSlot() must consult it before ever
     * looking at $ranges.
     */
    public function test_apply_moved_override_takes_precedence_over_ranges(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $overriddenTarget = ClusterEndpoint::parse('10.0.0.9:9000');
        $topology->applyMovedOverride(0, $overriddenTarget);

        self::assertSame(
            $topology->clientFor($overriddenTarget),
            $topology->nodeForSlot(0),
            'a slot with a recorded override must resolve to the override\'s own client, not whatever range originally covered it',
        );
        self::assertNotSame(
            $topology->nodeForSlot(0),
            $topology->nodeForSlot(101),
            'an unrelated slot with no override must be completely unaffected',
        );
    }

    /**
     * allMasters() backs clear()'s FLUSHDB fan-out — an override's own
     * target has to be included there too, not just reachable via
     * nodeForSlot(), or a value written through a MOVED retry could
     * survive a clear() call that reports success.
     */
    public function test_all_masters_includes_a_moved_override_target(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $overriddenTarget = ClusterEndpoint::parse('10.0.0.9:9000');
        $topology->applyMovedOverride(0, $overriddenTarget);

        $masters = $topology->allMasters();

        self::assertContains($topology->clientFor($overriddenTarget), $masters);
        self::assertCount(3, $masters, '2 original masters + the 1 new override target, none of them duplicated');
    }

    /**
     * An override target that happens to coincide with an existing
     * master (the override "confirms" what allMasters() already knew,
     * rather than naming a genuinely new node) must not appear twice.
     */
    public function test_all_masters_deduplicates_an_override_that_matches_an_existing_master(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $topology->applyMovedOverride(0, ClusterEndpoint::parse('127.0.0.1:7000')); // the exact address topologyWithTwoRealRanges()'s own first master already uses

        $masters = $topology->allMasters();

        self::assertCount(2, $masters, 'the override duplicates an already-known master, so the count must not grow');
    }

    /**
     * A fresh, successful applyShards() (what a real refresh() does
     * internally) reconciles an override only when it actively confirms
     * it — the fresh data's own owner for that slot matches the
     * override's target exactly. It is not unconditionally more
     * authoritative than an override: a snapshot can be complete and
     * error-free while still predating the override's own MOVED reply,
     * so agreement, not mere freshness, is what removes an entry.
     */
    public function test_a_fresh_apply_shards_reconciles_the_moved_overlay_away_once_it_confirms_the_override(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $applyShards = new ReflectionMethod($topology, 'applyShards');
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.9:9000'));
        self::assertNotSame(
            $topology->nodeForSlot(101),
            $topology->nodeForSlot(100),
            'the override must be in effect before the reconciling refresh below',
        );

        // A fresh, successful discovery that genuinely CONFIRMS the
        // override — slot 100 is now reported as owned by the exact
        // same 10.0.0.9:9000 the override already named, as if gossip
        // had finally caught up with the real MOVED this instance
        // already knew about directly.
        $applyShards->invoke($topology, [
            ['slots', [0, 99], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [100, 100], 'nodes', [['id', 'target', 'port', 9000, 'ip', '10.0.0.9', 'role', 'master']]],
            ['slots', [101, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        self::assertSame(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000')),
            $topology->nodeForSlot(100),
            'nodeForSlot() must still resolve slot 100 to the confirmed target directly through $ranges, now that the override for it has been reconciled away',
        );
        self::assertCount(2, $topology->allMasters(), 'no duplicate fan-out target left over from the reconciled override');
    }

    /**
     * The bug this exact regression exists to catch: an earlier version
     * of applyShards() cleared the *entire* overlay on any successful,
     * syntactically complete discovery — meaning a second slot's own
     * best-effort refresh, landing on a seed that hadn't yet gossiped
     * the first slot's real move, would silently erase that still-
     * correct override the moment it ran. A disagreeing snapshot must
     * never be trusted over a directly-received MOVED reply just
     * because it happens to be freshly read and internally complete.
     */
    public function test_a_stale_disagreeing_snapshot_does_not_erase_an_unconfirmed_override(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $applyShards = new ReflectionMethod($topology, 'applyShards');
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        // A real MOVED reply for slot 100, received directly.
        $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.9:9000'));

        // A fresh, syntactically complete discovery that is nonetheless
        // STALE for slot 100 — it still reports node-1 as the owner,
        // exactly what a gossip-lagging seed would return.
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        self::assertSame(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000')),
            $topology->nodeForSlot(100),
            'the disagreeing snapshot must not have erased the override — it represents strictly newer information than a stale re-read',
        );
        self::assertContains(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000')),
            $topology->allMasters(),
            'clear()\'s own fan-out must still reach the override\'s target even after a stale reconciliation attempt',
        );
    }

    /**
     * The exact two-slot sequential scenario named directly: slot A's
     * override must survive a *different* slot's (B's) best-effort
     * refresh landing on a snapshot that hasn't caught up with A yet —
     * and B's own, separately-installed override must coexist alongside
     * it, with allMasters() covering both.
     */
    public function test_two_overrides_from_different_slots_both_survive_a_partial_stale_reconciliation(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $applyShards = new ReflectionMethod($topology, 'applyShards');
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        // 1. Slot A (100) reports MOVED to target A; its override is recorded.
        $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.9:9000'));

        // 2. Before seed gossip converges for A, slot B (200) reports
        //    MOVED too. B's own best-effort refresh happens to land on a
        //    snapshot that is complete but still stale specifically for
        //    A (though it DOES correctly reflect B's own boundary, since
        //    a real CLUSTER SHARDS reply from any seed always reflects
        //    that seed's own current view of the *whole* keyspace, not
        //    a partial one).
        $applyShards->invoke($topology, [
            ['slots', [0, 199], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [200, 16383], 'nodes', [['id', 'node-3', 'port', 7003, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);
        $topology->applyMovedOverride(200, ClusterEndpoint::parse('10.0.0.8:8000'));

        $targetA = $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000'));
        $targetB = $topology->clientFor(ClusterEndpoint::parse('10.0.0.8:8000'));

        self::assertSame($targetA, $topology->nodeForSlot(100), 'A\'s override must survive B\'s stale-on-A reconciliation');
        self::assertSame($targetB, $topology->nodeForSlot(200), 'B\'s own override must also be in effect');

        $masters = $topology->allMasters();
        self::assertContains($targetA, $masters, 'clear() must still reach A\'s target');
        self::assertContains($targetB, $masters, 'clear() must still reach B\'s target');

        // 3. A LATER discovery that finally confirms A (but is still
        //    stale on B) must remove only A's now-redundant entry,
        //    leaving B's still-unconfirmed one standing.
        $applyShards->invoke($topology, [
            ['slots', [0, 99], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [100, 199], 'nodes', [['id', 'target-a', 'port', 9000, 'ip', '10.0.0.9', 'role', 'master']]],
            ['slots', [200, 16383], 'nodes', [['id', 'node-3', 'port', 7003, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        self::assertSame($targetA, $topology->nodeForSlot(100), 'A resolves the same way whether through the (now-reconciled) $ranges or the override');
        self::assertSame($targetB, $topology->nodeForSlot(200), 'B\'s override must still be standing — this snapshot never confirmed it');
        self::assertContains($targetB, $topology->allMasters(), 'clear() must still reach B\'s target after A alone was reconciled');
    }

    /**
     * A later MOVED reply *from the overlaid target itself* is the
     * documented, correct way an override self-corrects when the slot
     * moves again — a plain array assignment overwriting the prior
     * entry, exercised here directly against ClusterTopology (the
     * guardKeyed()-level proof of the same mechanism lives in
     * ClusteredRedisSimpleCacheTest).
     */
    public function test_a_later_apply_moved_override_for_the_same_slot_replaces_the_earlier_one(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $applyShards = new ReflectionMethod($topology, 'applyShards');
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.9:9000'));
        $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.7:7000')); // the slot moved again

        self::assertSame(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.7:7000')),
            $topology->nodeForSlot(100),
            'the second MOVED must replace the first, not merely add to it',
        );
    }

    /**
     * The failing client passed in is the override's own current
     * target: the identity check must accept it, not merely leave the
     * override alone by coincidence.
     */
    public function test_invalidate_moved_override_if_target_removes_the_entry_and_falls_back_to_ranges_when_the_failed_client_matches(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        // Captured before any override exists, so the fallback assertion
        // below proves a real fallback to $ranges's own client, not a
        // tautological comparison of nodeForSlot(0) against itself.
        $originalRangeClient = $topology->nodeForSlot(0);

        $overrideEndpoint = ClusterEndpoint::parse('10.0.0.9:9000');
        $topology->applyMovedOverride(0, $overrideEndpoint);
        $overrideClient = $topology->clientFor($overrideEndpoint);
        self::assertNotSame($originalRangeClient, $overrideClient, 'sanity: the override must genuinely change routing before invalidation means anything');

        $removed = $topology->invalidateMovedOverrideIfTarget(0, $overrideClient);

        self::assertTrue($removed, 'the failed client matched the override\'s own current target, so it must report removing it');
        self::assertSame(
            $originalRangeClient,
            $topology->nodeForSlot(0),
            'nodeForSlot() must fall back to the original range client, not merely return some client',
        );
        self::assertNotContains(
            $overrideClient,
            $topology->allMasters(),
            'the invalidated target must no longer be part of the fan-out set',
        );
    }

    /**
     * The reviewer's own concrete failure mode: a client that failed
     * but was never this slot's override target — the same shape a
     * dedicated ASK client failing has — must never be able to erase a
     * separate, still-healthy override, even for the exact same slot
     * number.
     */
    public function test_invalidate_moved_override_if_target_leaves_a_mismatched_override_untouched(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $overrideEndpoint = ClusterEndpoint::parse('10.0.0.9:9000');
        $topology->applyMovedOverride(0, $overrideEndpoint);
        $overrideClient = $topology->clientFor($overrideEndpoint);

        // A different client entirely — built the same way guardKeyed()
        // builds a dedicated ASK client, never memoized as anything's
        // target, so it can never equal $overrideClient by construction.
        $unrelatedClient = $topology->buildDedicatedClient(ClusterEndpoint::parse('10.0.0.9:9999'));

        $removed = $topology->invalidateMovedOverrideIfTarget(0, $unrelatedClient);

        self::assertFalse($removed, 'a failure on an unrelated client must not report removing anything');
        self::assertSame(
            $overrideClient,
            $topology->nodeForSlot(0),
            'the override must survive a failure that came from a client other than its own current target',
        );
    }

    /**
     * The race the reviewer named directly: one Fiber's stale reference
     * to an override's *old* target failing after another Fiber has
     * already replaced that same slot's entry with a newer target must
     * not remove the newer entry — the identical identity check that
     * protects an ASK client's failure protects this too, for the same
     * reason: the failed client no longer matches what's currently
     * recorded.
     */
    public function test_invalidate_moved_override_if_target_cannot_remove_a_newer_replacement_installed_after_the_failed_client_was_captured(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $oldTarget = ClusterEndpoint::parse('10.0.0.9:9000');
        $topology->applyMovedOverride(0, $oldTarget);
        $oldClient = $topology->clientFor($oldTarget); // captured before the replacement below, as a delayed failure would

        $newTarget = ClusterEndpoint::parse('10.0.0.7:7000');
        $topology->applyMovedOverride(0, $newTarget); // a second, later MOVED reply replaces the entry
        $newClient = $topology->clientFor($newTarget);

        $removed = $topology->invalidateMovedOverrideIfTarget(0, $oldClient);

        self::assertFalse($removed, 'a delayed failure against the old target must not remove the newer replacement');
        self::assertSame($newClient, $topology->nodeForSlot(0), 'the newer override must still be in effect');
    }

    public function test_invalidate_moved_override_if_target_on_a_slot_with_no_override_is_a_harmless_no_op(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $before = $topology->nodeForSlot(0);
        $anyClient = $topology->buildDedicatedClient(ClusterEndpoint::parse('10.0.0.9:9000'));
        $removed = $topology->invalidateMovedOverrideIfTarget(0, $anyClient); // never had an override
        $after = $topology->nodeForSlot(0);

        self::assertFalse($removed);
        self::assertSame($before, $after);
    }

    public function test_apply_moved_override_rejects_a_negative_slot(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Redis Cluster slot -1: outside the valid 0-16383 range.');

        $topology->applyMovedOverride(-1, ClusterEndpoint::parse('10.0.0.9:9000'));
    }

    public function test_apply_moved_override_rejects_a_slot_above_the_valid_range(): void
    {
        $topology = $this->topologyWithTwoRealRanges();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Redis Cluster slot 16384: outside the valid 0-16383 range.');

        $topology->applyMovedOverride(16384, ClusterEndpoint::parse('10.0.0.9:9000'));
    }

    /**
     * A genuinely Fiber-interleaved version of the sequential two-slot
     * proof above — not merely a different code shape for the same
     * check, but the actual concern named directly: an override
     * installed by one Fiber while a *different* Fiber's own refresh is
     * still suspended (mid network round trip, in real use) must not be
     * lost once that suspended refresh finally completes with data that
     * predates the override. Built with Kinetis\Async\concurrently() and
     * Timer::delay() — the same deterministic, no-real-network technique
     * this codebase already uses to prove concurrently() genuinely
     * overlaps work rather than running it sequentially.
     */
    public function test_an_override_installed_while_another_refresh_is_suspended_survives_it(): void
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        $applyShards = new ReflectionMethod($topology, 'applyShards');
        $applyShards->invoke($topology, [
            ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        concurrently([
            // Task 1: a "slow" refresh, already carrying a stale
            // snapshot (as if the CLUSTER SHARDS round trip that
            // produced it had already been in flight before task 2's
            // MOVED was even known about) — it doesn't apply that
            // snapshot to the topology until after yielding, exactly
            // like a real, still-pending network reply would.
            function () use ($applyShards, $topology): void {
                Timer::delay(0.05);
                $applyShards->invoke($topology, [
                    ['slots', [0, 16383], 'nodes', [['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
                ]);
            },
            // Task 2: a real MOVED reply, recorded synchronously — with
            // no suspension point of its own, it runs to completion
            // before the event loop even needs to consider task 1's
            // still-pending timer.
            function () use ($topology): void {
                $topology->applyMovedOverride(100, ClusterEndpoint::parse('10.0.0.9:9000'));
            },
        ]);

        self::assertSame(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000')),
            $topology->nodeForSlot(100),
            'the override installed while the other refresh was suspended must survive that refresh completing with stale data',
        );
        self::assertContains(
            $topology->clientFor(ClusterEndpoint::parse('10.0.0.9:9000')),
            $topology->allMasters(),
        );
    }

    public function test_all_masters_returns_the_deduplicated_client_set(): void
    {
        // Exercises applyShards() -> parseShards() together, seeded
        // directly via reflection rather than a real refresh() network
        // call (refresh() itself needs a real cluster to reach — see the
        // class docblock above) — the one place this suite proves
        // applyShards() itself, not just parseShards() in isolation,
        // resolves endpoints into real RedisClient instances correctly.
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            [
                'slots', [0, 100],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
            [
                'slots', [101, 16383],
                'nodes', [
                    ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ]);

        $masters = $topology->allMasters();

        self::assertCount(2, $masters);
        self::assertContainsOnlyInstancesOf(RedisClient::class, $masters);
    }

    /**
     * @return array{0: ClusterTopology, 1: RedisClient, 2: RedisClient}
     */
    /**
     * A general-purpose sibling of topologyWithTwoRanges() below: that
     * one's own $clientFactory is a fixed lookup table keyed to exactly
     * its own two addresses, which a MOVED-override test needs to build
     * a client for a genuinely new, previously-unseen address —
     * confirmed directly, not assumed: reusing the fixed-table version
     * for that produces a real TypeError, not a subtle test bug.
     */
    private function topologyWithTwoRealRanges(): ClusterTopology
    {
        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => createRedisClient(RedisConfig::fromUri($endpoint->toUri())),
        );

        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            ['slots', [0, 100], 'nodes', [['id', 'a', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [101, 16383], 'nodes', [['id', 'b', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        return $topology;
    }

    private function topologyWithTwoRanges(): array
    {
        $clientA = createRedisClient(RedisConfig::fromUri('tcp://127.0.0.1:7000'));
        $clientB = createRedisClient(RedisConfig::fromUri('tcp://127.0.0.1:7001'));
        $clients = ['127.0.0.1:7000' => $clientA, '127.0.0.1:7001' => $clientB];

        $topology = new ClusterTopology(
            [ClusterEndpoint::parse('127.0.0.1:7000')],
            fn (ClusterEndpoint $endpoint): RedisClient => $clients[$endpoint->key()],
        );

        // Seeds ranges directly via the private applyShards() rather than a
        // real refresh() network call, the same reflection-based seeding
        // the allMasters() test below uses for the same reason. The second
        // range runs to the end of the keyspace so the topology is
        // complete — parseShards() rejects anything less.
        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            ['slots', [0, 100], 'nodes', [['id', 'a', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [101, 16383], 'nodes', [['id', 'b', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        return [$topology, $clientA, $clientB];
    }
}
