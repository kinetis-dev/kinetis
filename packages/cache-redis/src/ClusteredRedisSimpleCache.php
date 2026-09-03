<?php

declare(strict_types=1);

namespace Kinetis\SimpleCache;

use Amp\Redis\Command\Option\SetOptions;
use Amp\Redis\Protocol\QueryException;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisException;
use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Kinetis\SimpleCache\AtomicCounterInterface;
use Kinetis\SimpleCache\AtomicConsumeInterface;
use DateInterval;
use DateTimeImmutable;
use Kinetis\Config\Config;
use Kinetis\SimpleCache\Cluster\ClusterEndpoint;
use Kinetis\SimpleCache\Cluster\ClusterRedirect;
use Kinetis\SimpleCache\Cluster\ClusterRedirectKind;
use Kinetis\SimpleCache\Cluster\ClusterTopology;
use Kinetis\SimpleCache\Cluster\Crc16;
use Kinetis\SimpleCache\Connection\TlsRedisConnector;
use Kinetis\SimpleCache\Exception\CacheException;
use Kinetis\SimpleCache\Exception\InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

use function Amp\Redis\createRedisClient;
use function Kinetis\Async\concurrently;

/**
 * The cluster-aware counterpart to RedisSimpleCache — same
 * Psr\SimpleCache\CacheInterface, but every key is routed to whichever
 * cluster node actually owns it (Crc16::slotFor()) rather than a single
 * fixed connection. A separate class rather than a mode flag on
 * RedisSimpleCache: the two have genuinely different routing logic for
 * every single method, not a small conditional branch.
 *
 * Redis Cluster only ever supports database 0 — REDIS_DATABASE has no
 * equivalent here, unlike RedisSimpleCache's single-node config.
 */
final class ClusteredRedisSimpleCache implements CacheInterface, AtomicCounterInterface, AtomicConsumeInterface
{
    private readonly Serializer $serializer;

    public function __construct(
        private readonly ClusterTopology $topology,
        ?Serializer $serializer = null,
    ) {
        $this->serializer = $serializer ?? new NativeSerializer();
    }

    /**
     * Requires REDIS_CLUSTER=true — returns null otherwise, the same
     * "Redis is optional" contract RedisSimpleCache::fromConfig() has, so
     * AppScope::boot() can try this first and fall back to the single-node
     * class. REDIS_CLUSTER_SEEDS (comma-separated "host:port" or
     * "[ipv6-address]:port" entries) is required once REDIS_CLUSTER is
     * set — a cluster needs more than one seed to bootstrap from in case
     * any single one happens to be down, so there's no single-host
     * fallback the way RedisSimpleCache has. Every seed is parsed via
     * ClusterEndpoint::parse() before any client is constructed, so a
     * malformed or empty seed, or a port outside 1-65535, fails loudly
     * here rather than surfacing later as a warning, a silent port 0, or
     * an incidental URI-parsing error once a request actually tries to
     * connect.
     */
    public static function fromConfig(Config $config, string $connection = 'default'): ?self
    {
        if (!$config->bool(Config::scopedKey('REDIS_CLUSTER', $connection), false)) {
            return null;
        }

        $rawSeeds = array_map('trim', explode(',', $config->required(Config::scopedKey('REDIS_CLUSTER_SEEDS', $connection))));
        $seeds = array_map(ClusterEndpoint::parse(...), $rawSeeds);

        $timeoutKey = Config::scopedKey('REDIS_TIMEOUT', $connection);
        $timeout = $config->float($timeoutKey, RedisConfig::DEFAULT_TIMEOUT);

        if ($timeout <= 0.0) {
            throw new InvalidArgumentException("{$timeoutKey} must be a positive number of seconds, got {$timeout}.");
        }

        $password = $config->get(Config::scopedKey('REDIS_PASSWORD', $connection));

        $clientFactory = static function (ClusterEndpoint $endpoint) use ($config, $connection, $timeout, $password): RedisClient {
            $uri = $endpoint->toUri();
            $redisConfig = RedisConfig::fromUri($uri, $timeout);

            if ($password !== null) {
                $redisConfig = $redisConfig->withPassword($password);
            }

            return createRedisClient($redisConfig, TlsRedisConnector::fromConfig($config, $uri, $timeout, $connection));
        };

        return new self(new ClusterTopology($seeds, $clientFactory));
    }

    #[\Override]
    public function get(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guardKeyed('get', $key, fn (RedisClient $client): ?string => $client->get($key));

        return $value === null ? $default : $this->serializer->unserialize($value);
    }

    /**
     * One key, so the script runs on whichever node owns that key's
     * slot — the same routing every other operation here uses.
     */
    #[\Override]
    public function consume(string $key, mixed $default = null): mixed
    {
        self::assertValidKey($key);

        $value = $this->guardKeyed('consume', $key, fn (RedisClient $client) => $client->eval(
            "local v = redis.call('GET', KEYS[1]) if v then redis.call('DEL', KEYS[1]) end return v",
            [$key],
        ));

        return $value === null ? $default : $this->serializer->unserialize((string) $value);
    }

    /**
     * One key, so the script runs on whichever node owns that key's
     * slot — the same routing every other operation here uses.
     */
    #[\Override]
    public function increment(string $key, int $ttlSeconds): int
    {
        self::assertValidKey($key);

        $value = $this->guardKeyed('increment', $key, fn (RedisClient $client) => $client->eval(
            "local v = redis.call('INCR', KEYS[1]) redis.call('EXPIRE', KEYS[1], ARGV[1]) return v",
            [$key],
            [(string) $ttlSeconds],
        ));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function count(string $key): int
    {
        self::assertValidKey($key);

        $value = $this->guardKeyed('count', $key, fn (RedisClient $client): ?string => $client->get($key));

        return is_numeric($value) ? (int) $value : 0;
    }

    #[\Override]
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        self::assertValidKey($key);
        $seconds = self::ttlInSeconds($ttl);

        if ($seconds !== null && $seconds <= 0) {
            return $this->delete($key);
        }

        $this->guardKeyed('set', $key, function (RedisClient $client) use ($key, $value, $seconds): void {
            $options = $seconds !== null ? (new SetOptions())->withTtl($seconds) : null;
            $client->set($key, $this->serializer->serialize($value), $options);
        });

        return true;
    }

    #[\Override]
    public function delete(string $key): bool
    {
        self::assertValidKey($key);
        $this->guardKeyed('delete', $key, fn (RedisClient $client): int => $client->delete($key));

        return true;
    }

    #[\Override]
    public function has(string $key): bool
    {
        self::assertValidKey($key);

        return $this->guardKeyed('has', $key, fn (RedisClient $client): bool => $client->has($key));
    }

    /**
     * FLUSHDB against one cluster node only clears that node's own shard,
     * not the cluster as a whole, so this fans out to every known master
     * — concurrently, since each is an independent round trip to a
     * different node.
     */
    #[\Override]
    public function clear(): bool
    {
        $this->guard('clear', '*', function (): void {
            $masters = $this->topology->allMasters();

            if ($masters !== []) {
                concurrently(array_map(
                    static fn (RedisClient $client) => static function () use ($client): void {
                        $client->flushDatabase();
                    },
                    $masters,
                ));
            }
        });

        return true;
    }

    #[\Override]
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return [];
        }

        // Not a per-node MGET: Redis Cluster rejects *any* multi-key
        // command whose keys don't all share one slot, even when a
        // single node happens to physically own every slot involved —
        // confirmed directly against a real cluster, not assumed from
        // the single-node client's own multi-key methods existing.
        // Dispatched one GET per key instead, concurrently, each
        // independently guarded/retried.
        $rawValues = concurrently(array_map(
            fn (string $key) => fn (): ?string => $this->guardKeyed(
                'getMultiple',
                $key,
                fn (RedisClient $client): ?string => $client->get($key),
            ),
            $keys,
        ));

        $result = [];

        foreach ($keys as $i => $key) {
            $value = $rawValues[$i];
            $result[$key] = $value === null ? $default : $this->serializer->unserialize($value);
        }

        return $result;
    }

    /**
     * A loop of independent set() calls run concurrently — Redis's MSET
     * has no per-key TTL option, so there was never a batched command to
     * send here even on a single node; concurrently() still saves real
     * time since different keys can land on different cluster nodes.
     *
     * @param iterable<string, mixed> $values
     */
    #[\Override]
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $tasks = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            $tasks[] = fn (): bool => $this->set($key, $value, $ttl);
        }

        if ($tasks !== []) {
            concurrently($tasks);
        }

        return true;
    }

    /**
     * One DELETE per key, concurrently — same CROSSSLOT reasoning as
     * getMultiple(), confirmed the same way: DEL rejects a multi-key call
     * whose keys don't share a slot exactly like GET/MGET does.
     */
    #[\Override]
    public function deleteMultiple(iterable $keys): bool
    {
        $keys = self::normalizeKeys($keys);

        if ($keys === []) {
            return true;
        }

        concurrently(array_map(
            fn (string $key) => fn (): int => $this->guardKeyed(
                'deleteMultiple',
                $key,
                fn (RedisClient $client): int => $client->delete($key),
            ),
            $keys,
        ));

        return true;
    }

    /**
     * The maximum number of attempts a single guardKeyed() call
     * makes in total before giving up — not one initial attempt plus
     * this many retries, six attempts altogether. Bounded so a
     * malformed or looping sequence of redirects fails cleanly rather
     * than hanging — real cluster operation never legitimately needs
     * more than a couple of hops (MOVED once, then at most one ASK for
     * the same key), so this leaves generous headroom without being
     * unbounded. A *repeated* redirect (the same kind and target seen
     * twice for this one operation) is detected and rejected on its own
     * terms well before this bound would otherwise be reached — see
     * $seenRedirects below.
     */
    private const int MAX_REDIRECT_ATTEMPTS = 6;

    /**
     * Runs a non-keyed operation (clear()'s FLUSHDB fan-out) — no slot
     * routing and no MOVED/ASK redirect to follow, since FLUSHDB is a
     * database-scoped admin command with no key argument for Redis
     * Cluster to redirect.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function guard(string $operationName, string $contextKey, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RedisException $e) {
            throw CacheException::forOperation($operationName, $contextKey, $e);
        }
    }

    /**
     * Resolves $key's owning node and runs $operation against it,
     * following Redis Cluster's own bounded redirect protocol:
     *
     * - MOVED means the slot's stable owner has genuinely changed since
     *   the last refresh. The topology is refreshed on a best-effort
     *   basis — updating routing for *future* calls, since a single
     *   resharding event can move more than the one slot involved, so a
     *   targeted single-slot patch wouldn't be correct in general — but
     *   the *current* retry goes straight to the redirect's own reported
     *   target, resolved through the topology's memoized clientFor()
     *   (the same auth/TLS-configured client factory every discovered
     *   node already uses, and a deliberately shared/reused connection,
     *   since a MOVED target is genuinely becoming the slot's new stable
     *   owner — not the throwaway, never-shared connection ASK needs).
     *   Retrying against the reply's own authoritative target, not
     *   solely against whatever refresh() manages to discover, matters
     *   because refresh() re-reads CLUSTER SHARDS from the first
     *   *reachable* seed — during live ownership propagation that seed
     *   can still be reporting the old (but internally self-consistent)
     *   topology even though the node that just replied MOVED has
     *   already authoritatively transitioned; relying on refresh() alone
     *   could mean silently repeating the identical stale lookup for
     *   every one of this call's attempts. A refresh() failure is
     *   likewise never allowed to block this retry — the MOVED reply
     *   itself already names a target with enough information to
     *   proceed; a later, unrelated call gets its own chance to refresh
     *   successfully. The redirect's own target is also recorded via
     *   ClusterTopology::applyMovedOverride() — a durable correction,
     *   not just routing for this one call: without it, a later
     *   operation for the same slot would keep hitting the old owner
     *   until $ranges itself eventually catches up, and allMasters()
     *   (which clear() fans FLUSHDB out to) would have no way to know
     *   the new owner exists, letting a value written through this very
     *   retry silently survive a clear() call that reports success.
     *   Durable specifically means it survives even a fresh, fully
     *   valid topology discovery that happens to disagree with it — a
     *   syntactically complete CLUSTER SHARDS reply from a
     *   gossip-lagging seed is not automatically more current than a
     *   MOVED reply this instance already received directly; see
     *   ClusterTopology's own docblocks (particularly $movedOverlay and
     *   applyShards()) for the full per-slot reconciliation model this
     *   relies on, and invalidateMovedOverrideIfTarget() below for how a
     *   dead override — one naming a node that's now genuinely
     *   unreachable — avoids pinning every future operation for that
     *   slot forever, without letting an unrelated failure (an ASK
     *   client's own, in particular) erase a still-healthy one.
     * - ASK means only this one key is mid-migration; the slot's stable
     *   owner hasn't changed. The operation is retried directly against
     *   the reported target — never installed as the slot's new owner,
     *   the topology is never refreshed for it, and applyMovedOverride()
     *   is never called for it either — preceded by ASKING on that
     *   exact connection, per the protocol.
     *
     * A MOVED-then-ASK sequence (the slot moved since the last refresh,
     * and the individual key is *also* still migrating out of the new
     * owner — a real, valid scenario) is followed correctly: each hop
     * re-enters the same loop rather than handling only one redirect
     * total. Every parsed redirect's own slot is checked against
     * Crc16::slotFor($key) before it's acted on at all — a redirect
     * naming a different slot is malformed for *this* operation
     * regardless of how well-formed the message otherwise is, and is
     * rejected rather than blindly followed. $seenRedirects catches a
     * repeating cycle (the identical kind+target redirect reported
     * twice for this one call) the moment it repeats, rather than
     * silently spending the whole MAX_REDIRECT_ATTEMPTS budget bouncing
     * between the same two nodes — a distinct failure from simply
     * exhausting the bound on a chain of genuinely different redirects.
     * A malformed redirect, a slot mismatch, a detected loop, an ASKING
     * failure, or exhausting MAX_REDIRECT_ATTEMPTS all wrap as
     * CacheException with the same operation/key context as any other
     * failure.
     *
     * The ASK target's client is always a fresh one from
     * ClusterTopology::buildDedicatedClient(), never the topology's own
     * memoized clientFor() — a shared, multiplexed connection would let
     * an unrelated concurrent Fiber's command land on the wire between
     * ASKING and the retried operation, breaking ASKING's one-shot
     * "next command" contract. Confirmed against amphp/redis's own
     * ReconnectingRedisLink: every execute() call is queued and matched
     * to its response strictly in send order, with nothing preventing a
     * second Fiber's send() from interleaving between two calls made by
     * this one — a dedicated, never-shared connection is what actually
     * guarantees the ordering, not merely calling the two in sequence.
     *
     * @template T
     * @param callable(RedisClient): T $operation
     * @return T
     */
    private function guardKeyed(string $operationName, string $key, callable $operation): mixed
    {
        $expectedSlot = Crc16::slotFor($key);
        $client = $this->topology->nodeForSlot($expectedSlot);

        /** @var array<string, true> $seenRedirects keyed by "{kind}:{target}", detects a repeating cycle */
        $seenRedirects = [];

        for ($attempt = 1; $attempt <= self::MAX_REDIRECT_ATTEMPTS; $attempt++) {
            try {
                return $operation($client);
            } catch (QueryException $e) {
                $client = $this->resolveRedirectClient($operationName, $key, $expectedSlot, $e, $seenRedirects);
            } catch (RedisException $e) {
                // A connection-level failure, not a redirect reply —
                // $client here is whichever one $operation() was
                // actually called against on this attempt, and that's
                // exactly what's passed through: the overlay's own
                // memoized target after a MOVED reply, or the
                // dedicated, never-memoized ASK client after a
                // successful ASKING. invalidateMovedOverrideIfTarget()
                // only ever removes this slot's override when $client
                // genuinely *is* that override's own current target —
                // an ASK-dedicated client's own failure can never match
                // it, since it was never memoized as anything's target,
                // so a transient failure retrying a single migrating
                // key can never erase a separate, still-healthy durable
                // override for this same slot. When $client *is* the
                // override's target, this is the one direct signal that
                // justifies giving up on it: ClusterTopology's own
                // per-slot reconciliation only ever removes an override
                // a fresh discovery actively confirms, never merely
                // disagrees with, so without this a node that's
                // genuinely gone would keep pinning every future
                // operation for this slot to a connection that will
                // never succeed again. A harmless no-op when this slot
                // has no override at all, or when the current override
                // no longer matches $client (already replaced by a
                // later MOVED reply, possibly from another Fiber).
                $this->topology->invalidateMovedOverrideIfTarget($expectedSlot, $client);

                throw CacheException::forOperation($operationName, $key, $e);
            }
        }

        throw CacheException::forOperation(
            $operationName,
            $key,
            new RedisException('Exceeded ' . self::MAX_REDIRECT_ATTEMPTS . ' cluster redirect attempts.'),
        );
    }

    /**
     * Parses $e as a cluster redirect and returns the client guardKeyed()'s
     * own retry loop should use next — or throws a CacheException for every
     * outcome that ends the whole operation instead (a malformed or
     * wrong-slot redirect, a detected redirect loop, a failed ASKING
     * handshake). $seenRedirects accumulates across the caller's own loop
     * iterations, by reference, so a repeating redirect is caught here
     * exactly like the inline version this was extracted from.
     *
     * @param array<string, true> $seenRedirects keyed by "{kind}:{target}", detects a repeating cycle
     */
    private function resolveRedirectClient(
        string $operationName,
        string $key,
        int $expectedSlot,
        QueryException $e,
        array &$seenRedirects,
    ): RedisClient {
        try {
            $redirect = ClusterRedirect::tryParse($e->getMessage());
        } catch (RedisException $malformed) {
            throw CacheException::forOperation($operationName, $key, $malformed);
        }

        if ($redirect === null) {
            throw CacheException::forOperation($operationName, $key, $e);
        }

        if ($redirect->slot !== $expectedSlot) {
            throw CacheException::forOperation($operationName, $key, new RedisException(
                "{$redirect->kind->value} redirect names slot {$redirect->slot}, but \"{$key}\" hashes to slot {$expectedSlot}.",
            ));
        }

        $redirectSignature = $redirect->kind->value . ':' . $redirect->target->key();

        if (isset($seenRedirects[$redirectSignature])) {
            throw CacheException::forOperation($operationName, $key, new RedisException(
                "Redirect loop detected: {$redirect->kind->value} to {$redirect->target->key()} was already followed for this operation.",
            ));
        }

        $seenRedirects[$redirectSignature] = true;

        if ($redirect->kind === ClusterRedirectKind::Moved) {
            try {
                $this->topology->refresh();
            } catch (RedisException) {
                // Best-effort — see guardKeyed()'s own docblock: this
                // retry proceeds against the reply's own authoritative
                // target regardless, so a transient refresh failure here
                // must not block an operation that can otherwise succeed.
            }

            // Recorded regardless of whether the refresh above succeeded
            // or failed — and regardless of whether a successful refresh
            // already agrees with it — a MOVED reply is durable ownership
            // information, not just a routing hint for this one call:
            // without this, a later operation for the same slot would
            // keep hitting the old owner until $ranges itself eventually
            // catches up, and allMasters() (which clear() fans FLUSHDB
            // out to) would have no way to know this target exists at
            // all.
            $this->topology->applyMovedOverride($redirect->slot, $redirect->target);

            return $this->topology->clientFor($redirect->target);
        }

        $askClient = $this->topology->buildDedicatedClient($redirect->target);

        try {
            $askClient->execute('ASKING');
        } catch (RedisException $askingFailed) {
            throw CacheException::forOperation($operationName, $key, $askingFailed);
        }

        return $askClient;
    }

    private static function ttlInSeconds(null|int|DateInterval $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }

        $now = new DateTimeImmutable();

        return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
    }

    /**
     * @param iterable<mixed, string> $keys
     * @return list<string>
     */
    private static function normalizeKeys(iterable $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $key = (string) $key;
            self::assertValidKey($key);
            $normalized[] = $key;
        }

        return $normalized;
    }

    private static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw InvalidArgumentException::forKey($key, 'must not be empty.');
        }

        if (preg_match('/[{}()\/\\\\@:]/', $key) === 1) {
            throw InvalidArgumentException::forKey($key, 'must not contain any of the reserved characters {}()/\@:.');
        }
    }
}
