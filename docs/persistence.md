# Persistence

Kinetis connects to MySQL, Postgres, and Redis through clients that never
block the rest of your application while waiting on a query — a request
that's waiting on the database doesn't stop a persistent worker from
making progress on anything else in the meantime.

Because of that, connecting through `PDO`, `ext-mysqli`, or `ext-pgsql`
directly isn't supported — the way those work, a query call only returns
once the database has responded, and that blocks the entire worker
process for as long as it takes, not just the one request. Kinetis's own
clients avoid this by design; you don't need to think about it once
you're using them.

MariaDB works too, everywhere this page says MySQL — `amphp/mysql`
speaks the wire protocol both databases share. The one place a specific
minimum version matters is `kinetis/queue`'s SQL backend; see {doc}`queue`.

```{note}
Core itself has no MySQL/Postgres/Redis dependency of its own —
`Kinetis\Persistence\TransactionGuard`/`SqlConnectionFactory` live in the
separate `kinetis/persistence` package, and
`Kinetis\SimpleCache\RedisSimpleCache`/`ClusteredRedisSimpleCache` live in
`kinetis/cache-redis`. `composer require` whichever you need; each is
introduced with its own installation note below at first use.
```

## Connecting

Register a pool once, in your own bootstrap (`public/index.php`), before
`AppScope::boot()`:

```{code-block} php
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;

$app = new AppScope();

$app->instance(MysqlConnectionPool::class, new MysqlConnectionPool(
    MysqlConfig::fromString(
        "host={$config->string('DB_HOST', '127.0.0.1')} " .
        "dbname={$config->string('DB_NAME', 'app')} " .
        "user={$config->string('DB_USER', 'app')} " .
        "password={$config->required('DB_PASSWORD')}",
    ),
));

$app->boot();
```

`$config` is typed `Kinetis\Config\Config` — see {doc}`config` for the full
typed-accessor API. Postgres is the identical pattern with
`PostgresConnectionPool`/`PostgresConfig`.

A controller or service then gets the pool by constructor injection, like
anything else registered on `AppScope`:

```{code-block} php
final readonly class OrderController
{
    public function __construct(
        private MysqlConnectionPool $db,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return $this->db->query('SELECT * FROM orders WHERE customer_id = ?', [$customerId]);
    }
}
```

`RequestScope` delegates to `AppScope` only for explicitly registered ids
(see {doc}`container`) — since `MysqlConnectionPool::class` was registered
via `instance()` above, every request resolves back to that same shared
pool, not a fresh one per request.

`MysqlConnectionPool`/`PostgresConnectionPool` are themselves full
connection pools — `Amp\Sql\Common\SqlCommonConnectionPool` already handles
idle-connection eviction and dead-socket recycling internally. Kinetis's own
`Kinetis\Persistence\Pool` is not used by this integration — wrapping an
already-pooled client in another pool would be pooling a pool. `Pool`
stays available as generic infrastructure for a protocol client that
doesn't already pool itself.

```{note}
`amphp/postgres` is not pure-PHP the way `amphp/mysql` is — it wraps a real
Postgres client library and needs `ext-pgsql` or `pecl-pq` installed to
connect at all. `amphp/mysql` needs no extension. Neither is a hard
Composer requirement; `ext-pgsql` is listed under Kinetis's `suggest`
instead, so installing Kinetis doesn't force a Postgres-specific extension
onto a MySQL-only or Redis-only deployment.
```

{doc}`query-builder` builds on this same registered pool — pass it to
`new Query($db)` instead of calling `->query()` directly.

### Multiple databases: named connections

```{code-block} sh
composer require kinetis/persistence
```

`Kinetis\Persistence\SqlConnectionFactory` builds a
`MysqlConnectionPool`/`PostgresConnectionPool` straight from `Config` —
the same connection-string assembly the example above writes by hand,
available as a one-line call, and aware of {doc}`config`'s named-connection
convention:

```{code-block} php
use Kinetis\Persistence\SqlConnectionFactory;

$default = SqlConnectionFactory::fromConfig($config);          // DB_*
$reporting = SqlConnectionFactory::fromConfig($config, 'db2'); // DB_DB2_*
```

```{code-block} text
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PASSWORD=secret

DB_DB2_CONNECTION=pgsql
DB_DB2_HOST=reporting.internal
DB_DB2_PASSWORD=secret
```

Register each pool under its own id if you want both reachable through
the container:

```{code-block} php
$app->instance(MysqlConnectionPool::class, SqlConnectionFactory::fromConfig($config));
$app->instance('db.reporting', SqlConnectionFactory::fromConfig($config, 'db2'));
```

Only the first is autowireable by constructor type-hinting — a named,
non-default connection is always retrieved explicitly
(`$app->get('db.reporting')`), never injected by type.

## `TransactionGuard` — the piece AMPHP genuinely can't provide

`Kernel` degrades gracefully when `kinetis/persistence` isn't installed
(no dispose hook registered, no error), so an application with no
database at all can skip it entirely.

Connection pooling is a solved problem once you're using AMPHP's clients
directly. What they have no way to know about is Kinetis's `RequestScope`
(see {doc}`container`): if application code begins a transaction and
something throws before it's explicitly committed or rolled back, nothing
closes it — and it leaks into whatever the next thing to borrow that pooled
connection does.

`Kinetis\Persistence\TransactionGuard` is the request-scoped safety net for
exactly this. It's autowired fresh per request, like any other class you
haven't explicitly registered on `AppScope`, and tracks every transaction
it starts.

### The recommended pattern

```{code-block} php
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Persistence\TransactionGuard;
use Amp\Mysql\MysqlConnectionPool;

final readonly class OrderController
{
    public function __construct(
        private TransactionGuard $transactions,
        private MysqlConnectionPool $db,
    ) {}

    #[Post('/orders')]
    public function store(#[Body] CreateOrderRequest $data): array
    {
        return $this->transactions->transaction($this->db, function ($db) use ($data) {
            $db->execute('INSERT INTO orders (...) VALUES (...)', [/* ... */]);
            $db->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', [$data->sku]);

            return ['status' => 'created'];
        });
    }
}
```

`transaction()` commits on success, rolls back on any throw, and always
closes before returning — there's nothing left for the safety net below to
ever find here. This is the pattern you should reach for by default.

### The safety net for everything else

```{code-block} php
public function rollbackDangling(): void
```

For the case the pattern above doesn't cover — a transaction begun
directly via `beginTransaction()` and held open across multiple calls,
that never reaches either `commit()` or `rollback()` before the request
ends — `Kernel` registers `rollbackDangling()` as a `RequestScope` dispose
hook, **unconditionally, on every request**:

```{code-block} php
$scope->onDispose($scope->get(Kinetis\Persistence\TransactionGuard::class)->rollbackDangling(...));
```

This is a genuine no-op for the overwhelming majority of requests that
never open a transaction at all — it costs nothing to wire in universally,
which is exactly why it's unconditional rather than opt-in the way, say,
MCP support is (see {doc}`mcp`). When it does find one to close, it logs
a warning through whatever logger you've registered (see {doc}`logging`)
— a genuine anomaly signal, since it means a transaction was left open
somewhere it shouldn't have been.

Both `beginTransaction()` and `transaction()` work identically for MySQL
and Postgres: both drivers implement the same `Amp\Sql\SqlLink`/
`SqlTransaction` abstraction, so `TransactionGuard` never needs to know
which one it's actually talking to.

## Redis

```{code-block} php
use function Amp\Redis\createRedisClient;

$redis = createRedisClient('redis://localhost:6379');

$redis->set('session:abc123', $payload);
$value = $redis->get('session:abc123');
```

`amphp/redis`'s client already provides everything needed, including
automatic reconnection via `ReconnectingRedisLink`. Redis has no
comparable request-spanning transaction concept the way SQL does, so
nothing like `TransactionGuard` applies here.

## `Psr\SimpleCache\CacheInterface` — a PSR-16 cache

```{code-block} sh
composer require kinetis/cache-redis
```

`Kinetis\SimpleCache\RedisSimpleCache`/`ClusteredRedisSimpleCache` (below)
live in this separate package — core ships only `NullSimpleCache` and the
`CacheInterface` binding itself, so an application with no Redis at all
can skip this entirely; `AppScope::boot()` falls back to `NullSimpleCache`
automatically. Configuring Redis (`REDIS_HOST`/`REDIS_URL`/
`REDIS_CLUSTER`) without this package installed is a clear
`SimpleCacheUnavailableException` naming it at boot time, not a silent
fallback.

A general-purpose PSR-16 cache — not the raw Redis client above, and not
{doc}`caching`'s AOT compilation artifacts, a completely different kind of
"cache" despite the shared word. Resolvable anywhere via constructor
injection with zero setup, like `Config`/`LoggerInterface`:

```{code-block} php
use Psr\SimpleCache\CacheInterface;

final readonly class RateLimiter
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function tooManyAttempts(string $key, int $max): bool
    {
        return ($this->cache->get($key, 0)) >= $max;
    }
}
```

**Optional — Redis is never touched unless configured.** If you set
`REDIS_URL` or `REDIS_HOST`, this connects to Redis automatically with no
further setup. If you set neither, `CacheInterface` resolves to
`NullSimpleCache` — it always misses and never stores, fine for anything
where a cache miss just means recomputing. Features where a silent no-op
would mean silently not enforcing anything reject it at construction
instead: `RateLimitMiddleware` (see {doc}`middleware`) and
`kinetis/auth-jwt`'s `RevocationStore` (see {doc}`auth-jwt`) both require
a real cache.

```{code-block} sh
REDIS_URL=redis://:password@localhost:6379/0
# — or —
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_TIMEOUT=5
```

`REDIS_URL`, if set, wins outright over the discrete parts. Values are
serialized with the same `Amp\Serialization\NativeSerializer`
`Amp\Redis\RedisCache` itself uses internally, so any serializable PHP
value — not just strings — can be stored, per the PSR-16 contract.

Both `fromConfig()` and `buildRedisConfig()` take an optional
`string $connection = 'default'`, following {doc}`config`'s named-connection
convention:

```{code-block} php
$default = RedisSimpleCache::fromConfig($config);            // REDIS_*
$sessions = RedisSimpleCache::fromConfig($config, 'sessions'); // REDIS_SESSIONS_*
```

To make `CacheInterface` use a named connection instead of `'default'`,
register it yourself before `boot()` — your own registration is always
kept, never overwritten:

```{code-block} php
$app->instance(CacheInterface::class, RedisSimpleCache::fromConfig($config, 'sessions'));
$app->boot();
```

`clear()` flushes the **entire currently selected Redis database** — not
just keys this cache wrote. Correct when, as recommended above,
`REDIS_DATABASE` points at a database dedicated to Kinetis's cache; one
shared with unrelated data loses it too.

## Connecting over TLS

Add `REDIS_TLS=true` to any of the connections above — single-node or
cluster — to connect over TLS:

```{code-block} sh
REDIS_HOST=cache.example.com
REDIS_PORT=6380
REDIS_TLS=true
REDIS_TLS_CA_FILE=/etc/ssl/certs/redis-ca.crt
```

`REDIS_TLS_CA_FILE` points at a CA certificate to verify the server
against; omit it to use the system's default trust store. Set
`REDIS_TLS_VERIFY_PEER=false` to skip verification entirely — useful
against a self-signed certificate in development, not recommended in
production.

## Redis Cluster

Set `REDIS_CLUSTER=true` and `REDIS_CLUSTER_SEEDS` (a comma-separated list
of `host:port` addresses) instead of `REDIS_HOST`/`REDIS_URL`:

```{code-block} sh
REDIS_CLUSTER=true
REDIS_CLUSTER_SEEDS=10.0.0.1:6379,10.0.0.2:6379,10.0.0.3:6379
REDIS_PASSWORD=
```

Multiple seeds let Kinetis discover the cluster's layout even if one
particular seed happens to be down. Every key is routed to whichever node
actually owns it; `REDIS_TLS`/`REDIS_PASSWORD` apply to every node the
same way. Redis Cluster only supports database 0, so there's no
`REDIS_DATABASE` option here.

`CacheInterface` resolves to the same interface either way — application
code never needs to know whether it's talking to a single node or a
cluster.

```{note}
`getMultiple()`/`deleteMultiple()`/`clear()` each dispatch several Redis
commands concurrently internally. Don't call any of them from inside a
task you're already running through `concurrently()` yourself — nesting
one Fiber-driven event loop run inside another isn't supported.
```

## See also

- {doc}`concurrency` — `concurrently()`, and how AMPHP's `Amp\Future`-based
  clients compose with `Kinetis\Async`'s own Fiber-suspension primitives on
  the same Revolt loop.
- {doc}`container` — how `TransactionGuard` (and any other class you
  haven't explicitly registered) actually gets resolved per request.
- {doc}`logging` — registering the logger `rollbackDangling()` warns
  through.
- {doc}`config` — `$config` above, typed environment access in full, and
  the named-connection convention `SqlConnectionFactory`/`RedisSimpleCache`
  both build on.
- {doc}`caching` — the *other* "cache" in this codebase: build-time AOT
  compilation of routes/validation/OpenAPI, unrelated to `CacheInterface`
  above beyond the shared word.
- {doc}`query-builder` — a thin, parameterized SQL builder on top of the
  same MySQL/Postgres clients, composing directly with `TransactionGuard`.
  A separate `kinetis/query-builder` package, not core.
