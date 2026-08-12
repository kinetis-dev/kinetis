<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisException;
use Closure;

/**
 * Discovers a Redis Cluster's slot-to-node layout via CLUSTER SHARDS
 * (issued through RedisClient::execute(), the generic raw-command escape
 * hatch — CLUSTER SHARDS has no typed method of its own) and resolves
 * which node owns a given slot. $clientFactory builds a RedisClient for
 * any "host:port" this discovers, using the same auth/TLS/timeout
 * configuration as the seed nodes — a real cluster shares that
 * configuration across every node, so ClusteredRedisSimpleCache's
 * fromConfig() only ever builds one factory closure.
 */
final class ClusterTopology
{
    /** @var list<array{start:int,end:int,client:RedisClient}> */
    private array $ranges = [];

    /** @var array<string, RedisClient> keyed by "host:port" */
    private array $clientsByAddress = [];

    /** @var list<string> every master's "host:port", for a cluster-wide fan-out (e.g. clear()) */
    private array $masterAddresses = [];

    /**
     * @param list<string> $seedAddresses "host:port" strings
     * @param Closure(string, int): RedisClient $clientFactory
     */
    public function __construct(
        private readonly array $seedAddresses,
        private readonly Closure $clientFactory,
    ) {}

    public function nodeForSlot(int $slot): RedisClient
    {
        if ($this->ranges === []) {
            $this->refresh();
        }

        foreach ($this->ranges as $range) {
            if ($slot >= $range['start'] && $slot <= $range['end']) {
                return $range['client'];
            }
        }

        throw new RedisException("No cluster node owns slot {$slot}.");
    }

    /**
     * @return list<RedisClient>
     */
    public function allMasters(): array
    {
        if ($this->ranges === []) {
            $this->refresh();
        }

        return array_map(
            fn (string $address): RedisClient => $this->clientFor($address),
            $this->masterAddresses,
        );
    }

    public function clientFor(string $address): RedisClient
    {
        if (!isset($this->clientsByAddress[$address])) {
            [$host, $port] = explode(':', $address, 2);
            $this->clientsByAddress[$address] = ($this->clientFactory)($host, (int) $port);
        }

        return $this->clientsByAddress[$address];
    }

    /**
     * Re-runs CLUSTER SHARDS against the first reachable seed and rebuilds
     * the slot map from scratch — called once lazily on first use, and
     * again by ClusteredRedisSimpleCache after a MOVED reply, since a
     * targeted single-slot patch can't be correct in general (a
     * resharding event can move more than the one slot a caller happened
     * to hit first).
     */
    public function refresh(): void
    {
        $lastError = null;

        foreach ($this->seedAddresses as $seedAddress) {
            try {
                $shards = $this->clientFor($seedAddress)->execute('CLUSTER', 'SHARDS');
                $this->applyShards($shards);

                return;
            } catch (RedisException $e) {
                $lastError = $e;
            }
        }

        throw new RedisException(
            'Could not discover cluster topology from any seed node.',
            previous: $lastError,
        );
    }

    private function applyShards(mixed $shards): void
    {
        $parsed = self::parseShards($shards);

        $this->ranges = array_map(
            fn (array $range): array => [
                'start' => $range['start'],
                'end' => $range['end'],
                'client' => $this->clientFor($range['address']),
            ],
            $parsed['ranges'],
        );
        $this->masterAddresses = $parsed['masterAddresses'];
    }

    /**
     * The pure, network-free half of applying a CLUSTER SHARDS reply:
     * raw reply -> slot ranges keyed by node address, plus every
     * distinct master address seen. Extracted as a public static method
     * specifically so this can be unit-tested directly against a canned
     * reply — `Amp\Redis\RedisClient` is `final` and can't be faked, so
     * the moment address resolution turns into a real `RedisClient` (in
     * applyShards() above) is exactly where testability without a real
     * cluster has to stop; everything before that point is plain array
     * parsing and belongs on this side of the split.
     *
     * @return array{ranges: list<array{start:int,end:int,address:string}>, masterAddresses: list<string>}
     */
    public static function parseShards(mixed $shards): array
    {
        if (!is_array($shards)) {
            throw new RedisException('Unexpected CLUSTER SHARDS reply shape.');
        }

        $ranges = [];
        $masterAddresses = [];

        foreach ($shards as $shard) {
            /** @var array<string, mixed> $shardMap */
            $shardMap = self::pairsToMap($shard);
            /** @var list<int> $slots */
            $slots = $shardMap['slots'];
            /** @var list<mixed> $nodes */
            $nodes = $shardMap['nodes'];

            $master = null;

            foreach ($nodes as $node) {
                $nodeMap = self::pairsToMap($node);

                if (($nodeMap['role'] ?? null) === 'master') {
                    $master = $nodeMap;

                    break;
                }
            }

            if ($master === null) {
                continue;
            }

            $address = "{$master['ip']}:{$master['port']}";
            $masterAddresses[] = $address;

            for ($i = 0, $count = count($slots); $i < $count; $i += 2) {
                $ranges[] = ['start' => $slots[$i], 'end' => $slots[$i + 1], 'address' => $address];
            }
        }

        return ['ranges' => $ranges, 'masterAddresses' => array_values(array_unique($masterAddresses))];
    }

    /**
     * @param mixed $pairs flat alternating key/value list
     * @return array<string, mixed>
     */
    private static function pairsToMap(mixed $pairs): array
    {
        if (!is_array($pairs)) {
            throw new RedisException('Unexpected CLUSTER SHARDS reply shape.');
        }

        $map = [];

        for ($i = 0, $count = count($pairs); $i < $count; $i += 2) {
            $map[$pairs[$i]] = $pairs[$i + 1];
        }

        return $map;
    }
}
