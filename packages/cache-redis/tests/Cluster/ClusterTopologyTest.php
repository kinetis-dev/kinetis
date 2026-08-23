<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Tests\Cluster;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Kinetis\SimpleCache\Cluster\ClusterTopology;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function Amp\Redis\createRedisClient;

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
 * The real refresh()/MOVED-retry path against a live 6-node cluster is
 * verified separately, by hand — not part of this committed suite.
 */
final class ClusterTopologyTest extends TestCase
{
    public function test_parses_a_single_shard_single_range_reply(): void
    {
        $shards = [
            [
                'slots', [0, 5460],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame(
            [['start' => 0, 'end' => 5460, 'address' => '127.0.0.1:7000']],
            $parsed['ranges'],
        );
        self::assertSame(['127.0.0.1:7000'], $parsed['masterAddresses']);
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
                ['start' => 0, 'end' => 5460, 'address' => '127.0.0.1:7000'],
                ['start' => 5461, 'end' => 10922, 'address' => '127.0.0.1:7001'],
                ['start' => 10923, 'end' => 16383, 'address' => '127.0.0.1:7002'],
            ],
            $parsed['ranges'],
        );
        self::assertSame(
            ['127.0.0.1:7000', '127.0.0.1:7001', '127.0.0.1:7002'],
            $parsed['masterAddresses'],
        );
    }

    public function test_a_shard_with_multiple_disjoint_slot_ranges_produces_one_range_entry_each(): void
    {
        // A real, migrating-slot shape: CLUSTER SHARDS can report more than
        // one [start, end] pair per shard, not just one contiguous block.
        $shards = [
            [
                'slots', [0, 100, 200, 300],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame(
            [
                ['start' => 0, 'end' => 100, 'address' => '127.0.0.1:7000'],
                ['start' => 200, 'end' => 300, 'address' => '127.0.0.1:7000'],
            ],
            $parsed['ranges'],
        );
        self::assertSame(['127.0.0.1:7000'], $parsed['masterAddresses']);
    }

    public function test_a_shard_with_no_master_node_is_skipped_entirely(): void
    {
        // A real, if unusual, transient cluster state — a shard mid-failover
        // with no node currently holding the master role.
        $shards = [
            [
                'slots', [0, 100],
                'nodes', [
                    ['id', 'node-1-replica', 'port', 7003, 'ip', '127.0.0.1', 'role', 'replica'],
                ],
            ],
            [
                'slots', [101, 200],
                'nodes', [
                    ['id', 'node-2', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertSame(
            [['start' => 101, 'end' => 200, 'address' => '127.0.0.1:7001']],
            $parsed['ranges'],
        );
        self::assertSame(['127.0.0.1:7001'], $parsed['masterAddresses']);
    }

    public function test_master_addresses_are_deduplicated(): void
    {
        // Two disjoint ranges owned by the same master (the multi-range
        // case above) must not produce a duplicate master address.
        $shards = [
            [
                'slots', [0, 100, 200, 300],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
        ];

        $parsed = ClusterTopology::parseShards($shards);

        self::assertCount(1, $parsed['masterAddresses']);
    }

    public function test_an_empty_reply_produces_no_ranges_and_no_masters(): void
    {
        $parsed = ClusterTopology::parseShards([]);

        self::assertSame([], $parsed['ranges']);
        self::assertSame([], $parsed['masterAddresses']);
    }

    public function test_a_non_array_reply_throws(): void
    {
        $this->expectException(RedisException::class);

        ClusterTopology::parseShards('not an array');
    }

    public function test_node_for_slot_returns_the_client_owning_that_slot(): void
    {
        [$topology, $clientA, $clientB] = $this->topologyWithTwoRanges();

        self::assertSame($clientA, $topology->nodeForSlot(0));
        self::assertSame($clientA, $topology->nodeForSlot(100));
        self::assertSame($clientB, $topology->nodeForSlot(101));
        self::assertSame($clientB, $topology->nodeForSlot(200));
    }

    public function test_node_for_slot_throws_when_no_range_covers_the_slot(): void
    {
        [$topology] = $this->topologyWithTwoRanges();

        $this->expectException(RedisException::class);
        $this->expectExceptionMessage('No cluster node owns slot 500.');

        $topology->nodeForSlot(500);
    }

    public function test_client_for_memoizes_by_address(): void
    {
        $built = [];
        $topology = new ClusterTopology(
            ['127.0.0.1:7000'],
            function (string $host, int $port) use (&$built): RedisClient {
                $client = createRedisClient(RedisConfig::fromUri("tcp://{$host}:{$port}"));
                $built[] = "{$host}:{$port}";

                return $client;
            },
        );

        $first = $topology->clientFor('127.0.0.1:7000');
        $second = $topology->clientFor('127.0.0.1:7000');

        self::assertSame($first, $second);
        self::assertSame(['127.0.0.1:7000'], $built);
    }

    public function test_client_for_builds_a_distinct_client_per_distinct_address(): void
    {
        $topology = new ClusterTopology(
            ['127.0.0.1:7000'],
            fn (string $host, int $port): RedisClient => createRedisClient(RedisConfig::fromUri("tcp://{$host}:{$port}")),
        );

        $a = $topology->clientFor('127.0.0.1:7000');
        $b = $topology->clientFor('127.0.0.1:7001');

        self::assertNotSame($a, $b);
    }

    public function test_all_masters_returns_the_deduplicated_client_set(): void
    {
        // Exercises applyShards() -> parseShards() together, seeded
        // directly via reflection rather than a real refresh() network
        // call (refresh() itself needs a real cluster to reach — see the
        // class docblock above) — the one place this suite proves
        // applyShards() itself, not just parseShards() in isolation,
        // resolves addresses into real RedisClient instances correctly.
        $topology = new ClusterTopology(
            ['127.0.0.1:7000'],
            fn (string $host, int $port): RedisClient => createRedisClient(RedisConfig::fromUri("tcp://{$host}:{$port}")),
        );

        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            [
                'slots', [0, 100],
                'nodes', [
                    ['id', 'node-1', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master'],
                ],
            ],
            [
                'slots', [101, 200],
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
    private function topologyWithTwoRanges(): array
    {
        $clientA = createRedisClient(RedisConfig::fromUri('tcp://127.0.0.1:7000'));
        $clientB = createRedisClient(RedisConfig::fromUri('tcp://127.0.0.1:7001'));
        $clients = ['127.0.0.1:7000' => $clientA, '127.0.0.1:7001' => $clientB];

        $topology = new ClusterTopology(
            ['127.0.0.1:7000'],
            fn (string $host, int $port): RedisClient => $clients["{$host}:{$port}"],
        );

        // Seeds ranges directly via the private applyShards() rather than a
        // real refresh() network call, the same reflection-based seeding
        // the allMasters() test below uses for the same reason.
        (new ReflectionMethod($topology, 'applyShards'))->invoke($topology, [
            ['slots', [0, 100], 'nodes', [['id', 'a', 'port', 7000, 'ip', '127.0.0.1', 'role', 'master']]],
            ['slots', [101, 200], 'nodes', [['id', 'b', 'port', 7001, 'ip', '127.0.0.1', 'role', 'master']]],
        ]);

        return [$topology, $clientA, $clientB];
    }
}
