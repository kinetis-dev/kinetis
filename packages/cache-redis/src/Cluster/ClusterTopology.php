<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache\Cluster;

use Amp\Redis\RedisClient;
use Amp\Redis\RedisException;
use Closure;
use InvalidArgumentException;

/**
 * Discovers a Redis Cluster's slot-to-node layout via CLUSTER SHARDS
 * (issued through RedisClient::execute(), the generic raw-command escape
 * hatch — CLUSTER SHARDS has no typed method of its own) and resolves
 * which node owns a given slot. $clientFactory builds a RedisClient for
 * any ClusterEndpoint this discovers, using the same auth/TLS/timeout
 * configuration as the seed nodes — a real cluster shares that
 * configuration across every node, so ClusteredRedisSimpleCache's
 * fromConfig() only ever builds one factory closure.
 */
final class ClusterTopology
{
    /** @var list<array{start:int,end:int,client:RedisClient}> */
    private array $ranges = [];

    /** @var array<string, RedisClient> keyed by ClusterEndpoint::key() */
    private array $clientsByAddress = [];

    /** @var list<ClusterEndpoint> every master's endpoint, for a cluster-wide fan-out (e.g. clear()) */
    private array $masterEndpoints = [];

    /**
     * A MOVED-driven per-slot override, taking precedence over $ranges
     * for that one slot until something more authoritative than the
     * override itself supersedes it — ClusteredRedisSimpleCache::
     * guardKeyed() installs one via applyMovedOverride() the moment it
     * learns a slot's real current owner, so that knowledge survives
     * past the one operation that discovered it rather than being
     * thrown away once that call returns. Without this, every later
     * operation for that slot would keep hitting the old owner and
     * paying another MOVED round trip until $ranges itself eventually
     * catches up — and, more seriously, allMasters() (which clear() fans
     * FLUSHDB out to) would have no way to know the override's target
     * exists at all, letting a value written through a MOVED retry
     * survive a clear() call that reports success. An overlay is
     * deliberately simpler than splitting/patching $ranges itself — a
     * single slot is corrected without needing to reconstruct the
     * surrounding range's own start/end boundaries.
     *
     * A fresh CLUSTER SHARDS reply is *not* automatically more
     * authoritative than an override, even when it's syntactically
     * complete: a real gossip-lagging seed can return a fully valid,
     * fully-covering snapshot that still names the *old* owner for a
     * slot this instance already has a directly-received MOVED reply
     * for. applyShards() therefore reconciles each override
     * individually — clearing only the ones the fresh data actually
     * *confirms* — rather than wiping the whole overlay on any
     * successful discovery; see applyShards() itself for exactly how.
     * An override does still get replaced or removed by two more
     * direct signals, both real MOVED-protocol events rather than a
     * background discovery pass: a later MOVED reply *from the
     * overlaid target itself* simply overwrites this array's entry
     * (applyMovedOverride() is a plain assignment, last write wins —
     * the natural way an ownership change chains correctly even when
     * it happens more than once for the same slot); and
     * invalidateMovedOverrideIfTarget() removes an entry when the
     * client that just failed *is* that entry's own current target —
     * a dedicated ASK client failing must never remove a slot's stable
     * override just because it happens to share the same slot: the
     * ASK client isn't the override's target at all, so the identity
     * check that method makes leaves the override untouched, and only
     * the overlaid target's own genuine, unreachable-with-no-redirect
     * failure can remove it — see that method for the full reasoning.
     *
     * @var array<int, ClusterEndpoint>
     */
    private array $movedOverlay = [];

    /**
     * @param list<ClusterEndpoint> $seedEndpoints
     * @param Closure(ClusterEndpoint): RedisClient $clientFactory
     */
    public function __construct(
        private readonly array $seedEndpoints,
        private readonly Closure $clientFactory,
    ) {}

    public function nodeForSlot(int $slot): RedisClient
    {
        if (isset($this->movedOverlay[$slot])) {
            return $this->clientFor($this->movedOverlay[$slot]);
        }

        if ($this->ranges === []) {
            $this->refresh();

            if (isset($this->movedOverlay[$slot])) {
                return $this->clientFor($this->movedOverlay[$slot]);
            }
        }

        foreach ($this->ranges as $range) {
            if ($slot >= $range['start'] && $slot <= $range['end']) {
                return $range['client'];
            }
        }

        throw new RedisException("No cluster node owns slot {$slot}.");
    }

    /**
     * Every known master, deduplicated by address — $masterEndpoints
     * plus $movedOverlay's own targets, so clear()'s FLUSHDB fan-out
     * reaches a node this instance only knows about via a MOVED
     * override and never misses it just because a fresh CLUSTER SHARDS
     * discovery hasn't happened yet.
     *
     * @return list<RedisClient>
     */
    public function allMasters(): array
    {
        if ($this->ranges === []) {
            $this->refresh();
        }

        $endpointsByAddress = [];

        foreach ($this->masterEndpoints as $endpoint) {
            $endpointsByAddress[$endpoint->key()] = $endpoint;
        }

        foreach ($this->movedOverlay as $endpoint) {
            $endpointsByAddress[$endpoint->key()] = $endpoint;
        }

        return array_map(
            fn (ClusterEndpoint $endpoint): RedisClient => $this->clientFor($endpoint),
            array_values($endpointsByAddress),
        );
    }

    /**
     * Records $target as $slot's real current owner, taking precedence
     * over whatever $ranges says for that one slot until something more
     * authoritative supersedes it (see $movedOverlay's own docblock for
     * the three ways that can happen) — the fix for a MOVED reply
     * guardKeyed() has already retried the current operation against,
     * so every later operation and allMasters()'s own fan-out both see
     * it too, not just the one call that discovered it. A plain array
     * assignment: calling this again for a slot that already has an
     * entry simply overwrites it, which is exactly what makes a later
     * MOVED reply *from the overlaid target itself* the correct,
     * automatic way an override self-corrects if the slot moves again.
     * Never called for ASK — an ASK target is a transient per-key
     * exception, never a durable ownership change, so it must never
     * enter this overlay at all. $slot is validated against Redis
     * Cluster's real 0-16383 range regardless of caller — every
     * internal caller already derives it from a parsed redirect
     * ClusterRedirect::tryParse() itself already bounds, but this is a
     * public method with no other guard between it and arbitrary
     * caller data. A plain \InvalidArgumentException, not one of this
     * package's own PSR-16-flavored exception types: this is an
     * internal routing API, not a Psr\SimpleCache\CacheInterface
     * method, so there's no PSR-16 exception contract to honor here.
     */
    public function applyMovedOverride(int $slot, ClusterEndpoint $target): void
    {
        if ($slot < 0 || $slot > 16383) {
            throw new InvalidArgumentException("Invalid Redis Cluster slot {$slot}: outside the valid 0-16383 range.");
        }

        $this->movedOverlay[$slot] = $target;
    }

    /**
     * Removes $slot's override, but only when $failedClient is that
     * entry's own current target — never merely because something
     * failed while operating on this slot. Called by guardKeyed() on a
     * connection-level failure (not a redirect reply), passing whichever
     * client the failing attempt actually ran against: the overlay's
     * own memoized client after a MOVED reply, or a dedicated,
     * never-memoized ASK client built for an entirely different,
     * transient per-key target. The identity check this method makes is
     * what keeps those two apart — an ASK client is never the same
     * instance as clientFor($movedOverlay[$slot]) for any slot, so its
     * own failure can never remove a slot's durable override, and a
     * failure that arrives after another Fiber has already replaced
     * this slot's entry with a newer target can't remove that newer
     * entry either, for the identical reason: $failedClient no longer
     * matches what's currently recorded.
     *
     * Without this, an override naming a node that has genuinely gone
     * away would pin every future operation for that slot to a
     * connection that keeps failing the same way, since applyShards()'s
     * own per-slot reconciliation only ever removes an override the
     * fresh data actively *confirms*, deliberately never one it merely
     * disagrees with (see $movedOverlay's own docblock) — a snapshot
     * alone can't tell a genuinely dead override apart from one that's
     * merely newer than the snapshot itself. A connection failure
     * against the override's own current target is a direct signal a
     * snapshot read can't provide either way, so it's what actually
     * justifies giving up on the override here and falling back to
     * whatever $ranges already knows instead of repeating the same
     * failing attempt indefinitely.
     *
     * @return bool whether an entry was actually removed
     */
    public function invalidateMovedOverrideIfTarget(int $slot, RedisClient $failedClient): bool
    {
        if (!isset($this->movedOverlay[$slot]) || $this->clientFor($this->movedOverlay[$slot]) !== $failedClient) {
            return false;
        }

        unset($this->movedOverlay[$slot]);

        return true;
    }

    public function clientFor(ClusterEndpoint $endpoint): RedisClient
    {
        $key = $endpoint->key();

        if (!isset($this->clientsByAddress[$key])) {
            $this->clientsByAddress[$key] = ($this->clientFactory)($endpoint);
        }

        return $this->clientsByAddress[$key];
    }

    /**
     * A fresh RedisClient for $endpoint, deliberately never memoized —
     * every call builds a genuinely new instance. clientFor() exists so
     * concurrent operations against the same stable node share one
     * connection; that sharing is exactly what an ASK redirect's
     * ASKING-then-retry sequence can't tolerate, since another Fiber's
     * unrelated command interleaving between the two on a shared
     * connection would land on the wire between them, breaking ASKING's
     * "next command" contract. The caller owns the returned client's
     * lifetime and is responsible for letting it go once the ASK
     * sequence completes — RedisClient has no explicit close(); dropping
     * every reference is enough, since ReconnectingRedisLink's own
     * destructor closes the underlying connection.
     */
    public function buildDedicatedClient(ClusterEndpoint $endpoint): RedisClient
    {
        return ($this->clientFactory)($endpoint);
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

        foreach ($this->seedEndpoints as $seedEndpoint) {
            try {
                $shards = $this->clientFor($seedEndpoint)->execute('CLUSTER', 'SHARDS');
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
                'client' => $this->clientFor($range['endpoint']),
            ],
            $parsed['ranges'],
        );
        $this->masterEndpoints = $parsed['masterEndpoints'];

        // Reconciled per slot, never by wiping the whole overlay: a
        // syntactically complete CLUSTER SHARDS reply is not
        // automatically ownership-current for every slot it covers — a
        // reachable-but-gossip-lagging seed can return a fully valid
        // snapshot that still names the *old* owner for a slot this
        // instance already holds a directly-received, strictly newer
        // MOVED reply for. Clearing the entire overlay unconditionally
        // would erase that still-correct override the moment *any*
        // other slot's best-effort refresh happens to succeed against a
        // stale seed — the same clear()-misses-data violation this
        // whole mechanism exists to close, just for a second slot
        // instead of the first. An override is removed here only when
        // this fresh snapshot actively *confirms* it (the same owner);
        // one it merely disagrees with is left standing, since a
        // disagreeing snapshot can't tell "genuinely stale" apart from
        // "still correct, just not confirmed by this particular read"
        // — only a direct signal can (see $movedOverlay's own docblock
        // for the two that do: a later MOVED from the overlaid target,
        // or invalidateMovedOverrideIfTarget() on the overlaid target's
        // own outright connection failure).
        foreach ($this->movedOverlay as $slot => $overrideEndpoint) {
            if ($this->rangeClientForSlot($slot) === $this->clientFor($overrideEndpoint)) {
                unset($this->movedOverlay[$slot]);
            }
        }
    }

    private function rangeClientForSlot(int $slot): ?RedisClient
    {
        foreach ($this->ranges as $range) {
            if ($slot >= $range['start'] && $slot <= $range['end']) {
                return $range['client'];
            }
        }

        return null;
    }

    /**
     * The pure, network-free half of applying a CLUSTER SHARDS reply:
     * raw reply -> slot ranges keyed by node endpoint, plus every
     * distinct master endpoint seen. Extracted as a public static method
     * specifically so this can be unit-tested directly against a canned
     * reply — `Amp\Redis\RedisClient` is `final` and can't be faked, so
     * the moment address resolution turns into a real `RedisClient` (in
     * applyShards() above) is exactly where testability without a real
     * cluster has to stop; everything before that point is plain array
     * parsing and belongs on this side of the split.
     *
     * A discovered master's ip/port never round-trip through a string —
     * CLUSTER SHARDS already reports them as two distinct reply fields,
     * so ClusterEndpoint::fromParts() builds the endpoint directly from
     * them; there is nothing to disambiguate the way a seed config
     * string needs ClusterEndpoint::parse() for.
     *
     * This is network protocol input, not trusted PHP data: every layer
     * of the reply — the alternating key/value shape at every level (a
     * string-keyed map with no duplicates, never a bare int key or a
     * repeated field), the slots list, every node in the nodes list (not
     * only up to whichever one first satisfies the master search, so a
     * malformed node reported after the master is still caught), and a
     * master's own exactly-typed ip/port fields — is validated before it
     * is ever indexed or cast, so a malformed reply throws RedisException
     * with no PHP warning escaping first. A shard reporting no current
     * master (a real, transient state — mid-failover, no node currently
     * holds the role) contributes no ranges of its own rather than
     * failing outright, but a shard reporting *more than one* node
     * claiming the master role is rejected outright — ambiguous topology
     * input, not a valid shard with a redundant report. What always
     * fails, regardless of how any single shard parsed, is the
     * *resulting* map: assertCompleteSlotCoverage() below rejects
     * anything short of one unambiguous 0..16383 covering, whether the
     * gap came from a master-less shard, a corrupted reply, or an
     * entirely empty one. A refresh() that can't fully route the
     * keyspace is not a successful refresh, even if every individual
     * shard in the reply parsed without error.
     *
     * @return array{ranges: list<array{start:int,end:int,endpoint:ClusterEndpoint}>, masterEndpoints: list<ClusterEndpoint>}
     */
    public static function parseShards(mixed $shards): array
    {
        if (!is_array($shards)) {
            throw new RedisException('Unexpected CLUSTER SHARDS reply shape.');
        }

        $ranges = [];
        /** @var array<string, ClusterEndpoint> $mastersByKey */
        $mastersByKey = [];

        foreach ($shards as $shard) {
            $shardMap = self::pairsToMap($shard);
            $slots = self::assertSlotList($shardMap);
            $nodes = self::assertNodeList($shardMap);
            $master = self::findMaster($nodes);

            if ($master === null) {
                continue;
            }

            $endpoint = self::endpointFromMasterFields($master);
            $mastersByKey[$endpoint->key()] = $endpoint;

            for ($i = 0, $count = count($slots); $i < $count; $i += 2) {
                $ranges[] = ['start' => $slots[$i], 'end' => $slots[$i + 1], 'endpoint' => $endpoint];
            }
        }

        self::assertCompleteSlotCoverage($ranges);

        return ['ranges' => $ranges, 'masterEndpoints' => array_values($mastersByKey)];
    }

    /**
     * @param array<string, mixed> $shardMap
     * @return list<int>
     */
    private static function assertSlotList(array $shardMap): array
    {
        if (!array_key_exists('slots', $shardMap) || !is_array($shardMap['slots'])) {
            throw new RedisException('CLUSTER SHARDS reply is missing a valid "slots" list.');
        }

        $slots = array_values($shardMap['slots']);
        $count = count($slots);

        if ($count % 2 !== 0) {
            throw new RedisException('CLUSTER SHARDS reply has an odd-length "slots" list.');
        }

        for ($i = 0; $i < $count; $i += 2) {
            $start = $slots[$i];
            $end = $slots[$i + 1];

            if (!is_int($start) || !is_int($end) || $start < 0 || $end > 16383 || $start > $end) {
                throw new RedisException(
                    'CLUSTER SHARDS reply contains an invalid slot boundary — each pair must be two integers '
                    . '0-16383 with start <= end.',
                );
            }
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $shardMap
     * @return list<mixed>
     */
    private static function assertNodeList(array $shardMap): array
    {
        if (!array_key_exists('nodes', $shardMap) || !is_array($shardMap['nodes'])) {
            throw new RedisException('CLUSTER SHARDS reply is missing a valid "nodes" list.');
        }

        return array_values($shardMap['nodes']);
    }

    /**
     * Every node is parsed and its role validated regardless of whether
     * a master has already been found — a malformed node later in the
     * list (odd pairs, a duplicate/non-string key, a non-string role)
     * must not go unvalidated just because an earlier node already
     * satisfied the search. More than one node claiming the master role
     * is ambiguous topology input, not a valid shard with a redundant
     * report, and is rejected the same way a missing master is
     * tolerated: the *count* of masters found is what decides validity,
     * never merely "was one found."
     *
     * @param list<mixed> $nodes
     * @return array<string, mixed>|null
     */
    private static function findMaster(array $nodes): ?array
    {
        $master = null;

        foreach ($nodes as $node) {
            $nodeMap = self::pairsToMap($node);

            if (!array_key_exists('role', $nodeMap) || !is_string($nodeMap['role'])) {
                throw new RedisException('CLUSTER SHARDS reported a node with a missing or non-string "role" field.');
            }

            if ($nodeMap['role'] !== 'master') {
                continue;
            }

            if ($master !== null) {
                throw new RedisException(
                    'CLUSTER SHARDS reported a shard with more than one node claiming the master role.',
                );
            }

            $master = $nodeMap;
        }

        return $master;
    }

    /**
     * RESP's own CLUSTER SHARDS reply has an exact wire shape here: ip
     * is a string, port is an int. Checked with is_string()/is_int()
     * rather than is_scalar() plus a cast — is_scalar() also accepts a
     * float or a bool, which a (string)/(int) cast would silently
     * coerce (6379.9 truncated to 6379, true turned into 1) instead of
     * rejecting the field for not actually being what the protocol
     * promises.
     *
     * @param array<string, mixed> $master
     */
    private static function endpointFromMasterFields(array $master): ClusterEndpoint
    {
        $ip = $master['ip'] ?? null;
        $port = $master['port'] ?? null;

        if (!is_string($ip) || !is_int($port)) {
            throw new RedisException('CLUSTER SHARDS reported a master node with a non-string ip or non-int port field.');
        }

        return ClusterEndpoint::fromParts($ip, $port);
    }

    /**
     * A CLUSTER SHARDS reply parsing cleanly shard-by-shard doesn't mean
     * the ranges it produced actually form one complete, unambiguous
     * 0..16383 covering — a gap or overlap only exists across shards,
     * never visible from any single one. Sorted by start, the first
     * range must begin at slot 0, each next range must begin exactly one
     * past the previous one's end (a start greater than expected is a
     * gap; less is an overlap), and the last must end at slot 16383. An
     * entirely empty $ranges list — no shards at all, or every shard
     * skipped for lacking a master — fails this the same way: there is
     * nothing to cover slot 0 with.
     *
     * @param list<array{start:int,end:int,endpoint:ClusterEndpoint}> $ranges
     */
    private static function assertCompleteSlotCoverage(array $ranges): void
    {
        usort($ranges, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $expectedStart = 0;

        foreach ($ranges as $range) {
            if ($range['start'] > $expectedStart) {
                throw new RedisException(
                    "CLUSTER SHARDS reported an incomplete slot map: no node owns slots {$expectedStart}-"
                    . ($range['start'] - 1) . '.',
                );
            }

            if ($range['start'] < $expectedStart) {
                throw new RedisException(
                    "CLUSTER SHARDS reported an ambiguous slot map: slot {$range['start']} is claimed by more "
                    . 'than one node.',
                );
            }

            $expectedStart = $range['end'] + 1;
        }

        if ($expectedStart <= 16383) {
            throw new RedisException(
                "CLUSTER SHARDS reported an incomplete slot map: no node owns slots {$expectedStart}-16383.",
            );
        }
    }

    /**
     * The protocol's own alternating maps are string-keyed — "slots",
     * "nodes", "id", "port", "ip", "role", never a bare integer — so an
     * int key is rejected here, not merely tolerated because PHP itself
     * would accept it as an array key. A repeated key ("slots" reported
     * twice in the same shard, say) is rejected too rather than
     * silently resolved to whichever value happened to come last:
     * neither PHP's own array assignment nor this class has any honest
     * way to know which of two conflicting values the server actually
     * meant.
     *
     * @param mixed $pairs flat alternating key/value list
     * @return array<string, mixed>
     */
    private static function pairsToMap(mixed $pairs): array
    {
        if (!is_array($pairs)) {
            throw new RedisException('Unexpected CLUSTER SHARDS reply shape.');
        }

        $pairs = array_values($pairs);
        $count = count($pairs);

        if ($count % 2 !== 0) {
            throw new RedisException('Unexpected CLUSTER SHARDS reply shape: an odd-length key/value list.');
        }

        $map = [];

        for ($i = 0; $i < $count; $i += 2) {
            $key = $pairs[$i];

            if (!is_string($key)) {
                throw new RedisException('Unexpected CLUSTER SHARDS reply shape: a non-string map key.');
            }

            if (array_key_exists($key, $map)) {
                throw new RedisException("Unexpected CLUSTER SHARDS reply shape: duplicate map key \"{$key}\".");
            }

            $map[$key] = $pairs[$i + 1];
        }

        return $map;
    }
}
