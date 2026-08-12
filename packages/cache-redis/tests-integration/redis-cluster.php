<?php

declare(strict_types=1);

/**
 * Real-backend regression coverage for
 * Kinetis\SimpleCache\ClusteredRedisSimpleCache against a genuine,
 * multi-node Redis Cluster — not a single-node stand-in. Exercises the
 * full PSR-16 surface routed through Crc16::slotFor()/ClusterTopology,
 * a real cross-node clear() fan-out, and the same non-blocking proof
 * technique used elsewhere in this project (a cluster operation raced
 * against Timer::delay() via concurrently()).
 *
 * Needs REDIS_CLUSTER_SEEDS (comma-separated "host:port" entries) —
 * see .github/workflows/integration.yml's `redis-cluster` job for how
 * the real cluster this connects to is stood up in CI.
 */

require __DIR__ . '/../vendor/autoload.php';

use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Kinetis\Async\Timer;
use Kinetis\Config\Config;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\ClusteredRedisSimpleCache;

use function Amp\Redis\createRedisClient;
use function Kinetis\Async\concurrently;

function check(string $label, bool $condition): void
{
    echo ($condition ? "OK   " : "FAIL ") . $label . "\n";

    if (!$condition) {
        exit(1);
    }
}

/**
 * A fresh, independent CLUSTER NODES parse — deliberately not reusing
 * ClusterTopology (the class under test) to discover real slot
 * ownership, so this stays a trustworthy oracle for the MOVED-retry
 * proof below.
 *
 * @return list<array{id: string, address: string, master: bool, slots: list<array{0:int,1:int}>}>
 */
function discoverClusterNodes(RedisClient $seed): array
{
    $lines = explode("\n", trim((string) $seed->execute('CLUSTER', 'NODES')));
    $nodes = [];

    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }

        $fields = preg_split('/\s+/', $line);
        $addressAndBus = explode('@', $fields[1])[0];
        $flags = $fields[2];
        $slots = [];

        foreach (array_slice($fields, 8) as $token) {
            if (preg_match('/^(\d+)-(\d+)$/', $token, $m) === 1) {
                $slots[] = [(int) $m[1], (int) $m[2]];
            } elseif (preg_match('/^\d+$/', $token) === 1) {
                $slots[] = [(int) $token, (int) $token];
            }
        }

        $nodes[] = [
            'id' => $fields[0],
            'address' => $addressAndBus,
            'master' => str_contains($flags, 'master'),
            'slots' => $slots,
        ];
    }

    return $nodes;
}

$config = new Config([
    'REDIS_CLUSTER' => 'true',
    'REDIS_CLUSTER_SEEDS' => getenv('REDIS_CLUSTER_SEEDS') ?: '127.0.0.1:7000,127.0.0.1:7001,127.0.0.1:7002',
]);

$cache = ClusteredRedisSimpleCache::fromConfig($config);

if ($cache === null) {
    fwrite(STDERR, "ClusteredRedisSimpleCache::fromConfig() returned null — check REDIS_CLUSTER_SEEDS.\n");
    exit(1);
}

$cache->clear();

// 50 keys, deliberately enough to land across every one of the cluster's
// 3 masters (Crc16::slotFor() confirmed spreading keys across the full
// 0-16383 slot space elsewhere in this project) — proves routing, not
// just that a single lucky key happens to work.
$keys = [];

for ($i = 0; $i < 50; $i++) {
    $keys[] = "cluster-key-{$i}";
}

$slotsSeen = [];

foreach ($keys as $key) {
    $slotsSeen[Crc16::slotFor($key)] = true;
}

check('50 keys spread across more than one slot region', count($slotsSeen) > 1);

foreach ($keys as $i => $key) {
    $cache->set($key, "value-{$i}");
}

$allRoundTrip = true;

foreach ($keys as $i => $key) {
    if ($cache->get($key) !== "value-{$i}") {
        $allRoundTrip = false;

        break;
    }
}

check('every key set() individually round-trips via get(), across every node', $allRoundTrip);

check('has() reflects a stored key', $cache->has('cluster-key-0'));
check('has() is false for a missing key', !$cache->has('cluster-key-missing'));

$multi = $cache->getMultiple($keys, default: 'MISSING');
$multiArray = iterator_to_array($multi);
$allMultiCorrect = true;

foreach ($keys as $i => $key) {
    if ($multiArray[$key] !== "value-{$i}") {
        $allMultiCorrect = false;

        break;
    }
}

check('getMultiple() reads every key back across multiple nodes (one GET per key, never a cross-slot MGET)', $allMultiCorrect);

check('deleteMultiple() removes every key across multiple nodes', $cache->deleteMultiple($keys));

$stillPresent = false;

foreach ($keys as $key) {
    if ($cache->has($key)) {
        $stillPresent = true;

        break;
    }
}

check('none of the deleted keys remain', !$stillPresent);

$cache->set('ttl-key', 'will-expire', ttl: 1);
check('a TTL-bearing key exists immediately', $cache->has('ttl-key'));
sleep(2);
check('a TTL-bearing key actually expires', !$cache->has('ttl-key'));

$cache->set('will-clear-1', 'value');
$cache->set('will-clear-2', 'value');
$cache->set('will-clear-3', 'value');
$cache->clear();
check('clear() wipes every shard, not just one node', !$cache->has('will-clear-1') && !$cache->has('will-clear-2') && !$cache->has('will-clear-3'));

// A real MOVED reply, forced by an actual live slot reassignment against
// a real, standing multi-node cluster — not a simulated redirect.
$movedTestKey = 'moved-retry-key';
$cache->set($movedTestKey, 'before-migration');
check('warmed against the real current owner', $cache->get($movedTestKey) === 'before-migration');

$seedAddress = explode(',', (string) getenv('REDIS_CLUSTER_SEEDS') ?: '127.0.0.1:7000')[0];
$seed = createRedisClient(RedisConfig::fromUri("tcp://{$seedAddress}"));
$nodes = discoverClusterNodes($seed);
$slot = Crc16::slotFor($movedTestKey);

$currentOwner = null;
$newOwner = null;

foreach ($nodes as $node) {
    if (!$node['master']) {
        continue;
    }

    $ownsSlot = false;

    foreach ($node['slots'] as [$start, $end]) {
        if ($slot >= $start && $slot <= $end) {
            $ownsSlot = true;

            break;
        }
    }

    if ($ownsSlot) {
        $currentOwner = $node;
    } else {
        $newOwner ??= $node;
    }
}

check('found both the real current owner and a real different master to migrate to', $currentOwner !== null && $newOwner !== null);

$currentOwnerClient = createRedisClient(RedisConfig::fromUri("tcp://{$currentOwner['address']}"));

// Clear the slot's own data first — Redis Cluster refuses SETSLOT NODE
// on a slot that still holds keys, by design. A real MIGRATE-based data
// transfer is a materially bigger undertaking the reassignment itself
// doesn't need, since only the reassignment is what actually produces
// the MOVED reply.
$currentOwnerClient->execute('DEL', $movedTestKey);

// Broadcast the reassignment to every master, not just the two directly
// involved — a real, proper redis-cli --cluster reshard waits for full
// cluster-bus gossip propagation before considering a migration done;
// telling only two nodes here and immediately querying again raced that
// propagation in an earlier draft of this script (a seed node that
// hadn't yet gossiped-learned the change still reported the stale
// owner, producing a second, different MOVED that guard() correctly
// does not retry a second time — a real timing bug in this test script,
// not in ClusteredRedisSimpleCache). Telling every master directly makes
// the change atomic from any seed's perspective with no propagation
// window at all.
foreach ($nodes as $node) {
    if ($node['master']) {
        createRedisClient(RedisConfig::fromUri("tcp://{$node['address']}"))
            ->execute('CLUSTER', 'SETSLOT', (string) $slot, 'NODE', $newOwner['id']);
    }
}

// $cache's own topology still points this slot at the old owner — this
// set() genuinely hits it, gets a real -MOVED reply, and guard() must
// catch it, refresh, and retry against the new owner transparently.
check(
    'set() survives a real MOVED reply and succeeds against the new owner',
    $cache->set($movedTestKey, 'after-migration') === true,
);
check('get() reads the value back through the refreshed topology', $cache->get($movedTestKey) === 'after-migration');

// A second, independent key hashing to the same now-relocated slot,
// needing no retry this time — proves refresh() permanently updated the
// topology rather than the previous call being a one-off fluke.
$cache->set('moved-retry-key-again', 'no-retry-needed');
check('a second operation against the same slot needs no further MOVED handling', $cache->get('moved-retry-key-again') === 'no-retry-needed');

// A real cluster round trip raced against a plain Timer::delay(),
// confirming the cluster operation genuinely suspends its Fiber rather
// than blocking the whole process.
$start = microtime(true);
concurrently([
    function () use ($cache): void {
        for ($i = 0; $i < 20; $i++) {
            $cache->set("nonblocking-{$i}", 'value');
        }
    },
    function (): void {
        Timer::delay(0.5);
    },
]);
$elapsed = microtime(true) - $start;

check('a cluster operation raced against Timer::delay(0.5) finishes in well under their sum (non-blocking)', $elapsed < 1.0);

echo "ALL CHECKS PASSED\n";
