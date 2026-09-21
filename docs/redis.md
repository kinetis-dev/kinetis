# Redis

`kinetis/cache-redis` binds `Psr\SimpleCache\CacheInterface` to Redis
from `REDIS_*` configuration, which is how a Kinetis application normally
uses Redis. The `kinetis/redis` transport underneath it also serves
{doc}`queue-redis` and the Redis session store. {doc}`appendix-redis`
holds the full transport and cache contract.

This cache is a runtime key-value store, unrelated to {doc}`caching`'s
build-time compilation beyond the shared word.

## A cache in a Kinetis application

```{code-block} sh
composer require kinetis/cache-redis
```

```{code-block} text
:caption: .env

REDIS_HOST=cache.internal
REDIS_PORT=6379
REDIS_PASSWORD=secret
```

With Redis configured, `AppScope::boot()` binds `CacheInterface` to
`Kinetis\SimpleCache\RedisSimpleCache`, and any class injects it:

```{code-block} php
use Psr\SimpleCache\CacheInterface;

final readonly class ArticleSummaries
{
    public function __construct(private CacheInterface $cache) {}

    /** @param callable(int): array<string, mixed> $load */
    public function get(int $id, callable $load): array
    {
        $key = "article.summary.{$id}";
        $summary = $this->cache->get($key);

        if ($summary === null) {
            $summary = $load($id);
            $this->cache->set($key, $summary, 300);
        }

        return $summary;
    }
}
```

- **Configuration.** `REDIS_URL` (`redis://:password@host:6379/0`) wins
  outright over `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` and
  `REDIS_DATABASE`. `REDIS_TIMEOUT` (default 5 seconds) is the budget for
  a whole operation, connecting and cluster redirects included, not a
  connect timeout. {doc}`config` lists every key.
- **Without Redis.** With none of `REDIS_URL`, `REDIS_HOST` and
  `REDIS_CLUSTER` set, `CacheInterface` is `NullSimpleCache`, which
  always misses and never stores. `RateLimitMiddleware` (see
  {doc}`middleware`) and `kinetis/auth-jwt`'s `RevocationStore` (see
  {doc}`auth-jwt`) refuse it at construction, since a cache that stores
  nothing enforces nothing.
- **Redis configured, package missing.** The binding is a cache whose
  every operation throws `SimpleCacheUnavailableException` naming
  `kinetis/cache-redis`. The application still boots, so a leftover
  `REDIS_*` key is not fatal, and code that uses the cache fails loudly
  instead of silently missing.
- **Values and keys.** Any serializable PHP value can be stored. Each key
  is stored as `kinetis_cache:<namespace>:<key>`, where the namespace is
  `REDIS_CACHE_NAMESPACE` (letters, digits, underscores and dashes;
  `default` unless set). A failure names the operation, never the key, so
  a key holding a session identifier or token hash stays out of logs and
  error pages. A value the serializer cannot encode, and a stored value
  it cannot decode, are failures like any other: they raise
  `CacheException` rather than returning a miss or the default, and
  `get()` leaves the entry where it is.
- **Several keys.** Prefer `getMultiple()` and `deleteMultiple()` to a
  loop of single calls ({ref}`redis-reference-batching`).
- **`clear()`** deletes this namespace's keys and nothing else, by
  scanning every node's keyspace. It is neither atomic nor a snapshot:
  use it to reset a cache, not inside request handling.

```{warning}
A failed cache call throws `Kinetis\SimpleCache\Exception\CacheException`.
When its previous exception is `Kinetis\Redis\Exception\OutcomeUnknown`,
the command was sent and no reply came back, so a `set()` or `delete()`
may have been applied. The command is never sent again; repeat it only
when applying it twice is harmless.
```

A cache on named `REDIS_*` keys instead of the default ones is
{ref}`redis-reference-named-cache`.

## TLS

Add `REDIS_TLS=true` to a single-node or cluster connection:

```{code-block} text
REDIS_HOST=cache.example.com
REDIS_PORT=6380
REDIS_TLS=true
REDIS_TLS_CA_FILE=/etc/ssl/certs/redis-ca.crt
```

`REDIS_TLS_CA_FILE` points at a CA certificate to verify the server
against; omit it to use the system's default trust store.
`REDIS_TLS_VERIFY_PEER=false` skips verification entirely — useful
against a self-signed certificate in development, not in production.

## Redis Cluster

Set `REDIS_CLUSTER=true` and `REDIS_CLUSTER_SEEDS` instead of
`REDIS_HOST` or `REDIS_URL`:

```{code-block} text
REDIS_CLUSTER=true
REDIS_CLUSTER_SEEDS=10.0.0.1:6379,10.0.0.2:6379,10.0.0.3:6379
REDIS_PASSWORD=secret
```

Several seeds let the client discover the cluster's layout while one
seed is down. Every key is routed to the node that owns it, and
`REDIS_PASSWORD` and `REDIS_TLS` apply to every node. Redis Cluster
serves database 0 only, so there is no `REDIS_DATABASE`.
`kinetis/queue-redis` is single-node, so it ignores `REDIS_CLUSTER`.

A seed is `host:port`, or `[address]:port` for an IPv6 node:

```{code-block} text
REDIS_CLUSTER_SEEDS=[2001:db8::10]:6379,[2001:db8::11]:6379
```

An unbracketed IPv6 address (`2001:db8::10:6379`) is rejected rather than
guessed at, since its own colons make the port ambiguous. A malformed
seed, an empty entry, or a port outside 1-65535 fails when the cache is
configured, before any connection is attempted. Application code works
against `CacheInterface` either way.

```{warning}
A cluster announces its nodes, in `CLUSTER SLOTS` and in `MOVED`/`ASK`
redirects, by IP address unless it is configured with
`cluster-announce-hostname` and `cluster-preferred-endpoint-type
hostname`. With peer verification on, a certificate carrying only
hostname SANs verifies against the seed and fails against every
discovered node. Give the certificates IP SANs or announce hostnames on
the server; verification is never relaxed for a discovered node.
```

## Raw Redis commands

`CacheInterface` covers caching. For any other command, bind a
`kinetis/redis` client yourself: {ref}`redis-reference-client` shows the
wiring and what each failure means, and the
[`kinetis/redis` README](https://github.com/kinetis-dev/redis#readme)
covers standalone use.

## See also

- {doc}`appendix-redis` — the transport, its failure and budget contract,
  cluster routing, and the cache's commands.
- {doc}`queue-redis` — the queue over the same transport.
- {doc}`config` — every `REDIS_*` key and the named-connection
  convention.
- {doc}`telemetry` — a span per cache operation.
