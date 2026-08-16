# Persistence

Kinetis connects to MySQL, Postgres, and Redis through clients matched to
the runtime actually serving your application. Under a persistent worker
(FrankenPHP), queries suspend only their own request's Fiber — a request
waiting on the database doesn't stop the worker's request from making
progress on anything else it has in flight. Under PHP-FPM, where a worker
serves exactly one request at a time from a fresh process, Kinetis uses a
plain blocking PDO connection instead — measured to be the faster choice
there by a wide margin, since nothing else could have used the wait time
anyway and PDO's native protocol handling costs a fraction of the CPU.

You never pick this per call site: `SqlConnectionFactory` selects the
driver from the runtime (see "Driver selection" below), every driver
implements the same Kinetis-owned `Kinetis\Persistence\Contract\SqlLink`/
`MysqlLink`/`PostgresLink` contracts, and
application code, `TransactionGuard`, and the query builder are identical
under all of them. Don't construct `PDO`/`mysqli`/pgsql handles yourself,
though — hand-rolled blocking calls in a persistent worker still block
that whole worker thread; going through the factory is what keeps the
blocking/non-blocking decision where the runtime knowledge lives.

MariaDB works too, everywhere this page says MySQL — mysqli and
PDO-MySQL speak the wire protocol both databases share. The one place a
specific minimum version matters is `kinetis/queue`'s SQL backend; see
{doc}`queue`.

```{note}
Core itself has no MySQL/Postgres/Redis dependency of its own —
`Kinetis\Persistence\TransactionGuard`/`SqlConnectionFactory` live in the
separate `kinetis/persistence` package, and
`Kinetis\SimpleCache\RedisSimpleCache`/`ClusteredRedisSimpleCache` live in
`kinetis/cache-redis`. `composer require` whichever you need; each is
introduced with its own installation note below at first use.
```

## Connecting

Setting `DB_CONNECTION` (plus the other `DB_*` keys — see {doc}`config`)
is the whole wiring: this package's bootstrap class (declared via
`extra.kinetis`, see {doc}`cli`) builds the default connection and binds
it under its dialect contract — `Contract\MysqlLink` for
`DB_CONNECTION=mysql`, `Contract\PostgresLink` for `pgsql` — before
`AppScope::boot()` locks bindings. The contract interface, not a
concrete class, so the factory stays free to pick the right driver per
runtime.

To choose your own pool options instead, register the binding yourself
in `bootstrap.php` — an application registration wins over the
package's:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\SqlConnectionFactory;

return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlLink::class, SqlConnectionFactory::fromConfig($config, poolOptions: ['maxConnections' => 12]));
};
```

A controller or service then gets the client by constructor injection,
like anything else registered on `AppScope`:

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return iterator_to_array($this->db->execute('SELECT * FROM orders WHERE customer_id = ?', [$customerId]));
    }
}
```

`RequestScope` delegates to `AppScope` only for explicitly registered ids
(see {doc}`container`) — since `MysqlLink::class` was registered via
`instance()` above, every request resolves back to that same shared
client, not a fresh one per request.

The async drivers are themselves connection pools — lazily opened
connections up to `maxConnections`, reused across requests under a
persistent worker, with dead connections discarded and replaced.
Kinetis's own `Kinetis\Persistence\Pool` is not used by this
integration — it stays available as generic infrastructure for protocol
clients that don't pool themselves.

```{note}
A pooled connection the server closes (an idle socket past
`wait_timeout`, an administrative `KILL`, a network drop) costs exactly
one query. Writing to a socket whose peer is already gone is buffered
locally rather than failing, so the first query on a newly-dead
connection dispatches successfully and only discovers the death while
reading the result — surfacing as a `QueryException` the caller has to
handle. Retrying it automatically is not an option: at that point the
statement may already have executed, and replaying a non-idempotent one
silently is worse than an error. The next query's dispatch does fail
immediately, and *that* is retried transparently on a fresh connection.
Long-lived workers issuing queries after an idle stretch should expect
this and retry at the application level, or keep connections warm.
```

```{note}
Each driver needs its extension: `ext-mysqli` or `ext-pgsql` for the
native async drivers, `ext-pdo_mysql`/`ext-pdo_pgsql` for the PDO
fallbacks. None is a hard Composer requirement — they're listed under
`suggest`, so installing Kinetis doesn't force a MySQL-specific extension
onto a Postgres-only deployment or vice versa.
```

{doc}`query-builder` builds on this same registered client — pass it to
`new Query($db)` instead of calling `->query()` directly.

### Multiple databases: named connections

```{code-block} sh
composer require kinetis/persistence
```

`Kinetis\Persistence\SqlConnectionFactory` builds a driver client
straight from `Config`, aware of {doc}`config`'s named-connection
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

Register each client under its own id if you want both reachable through
the container:

```{code-block} php
$app->instance(MysqlLink::class, SqlConnectionFactory::fromConfig($config));
$app->instance('db.reporting', SqlConnectionFactory::fromConfig($config, 'db2'));
```

Only the first is autowireable by constructor type-hinting — a named,
non-default connection is always retrieved explicitly
(`$app->get('db.reporting')`), never injected by type.

### Driver selection: `DB_DRIVER`

`SqlConnectionFactory::fromConfig()` picks the client implementation via
`DB_DRIVER` (connection-scoped like every other `DB_*` key):

| value | what you get |
|---|---|
| `auto` (default) | FrankenPHP worker mode → `native`; PHP-FPM → `pdo`. |
| `native` | mysqli's `MYSQLI_ASYNC` (`Driver\MysqliAsyncClient`) or ext-pgsql's `pg_send_query` (`Driver\PgsqlAsyncClient`): the wire protocol runs at C speed inside the extension, queries overlap across connections, and each waits by suspending only its own Fiber — full `concurrently()` support. |
| `pdo` | One blocking PDO connection (`Driver\PdoMysqlClient`/`PdoPgsqlClient`). `concurrently()` fan-outs still produce correct results; the queries simply run sequentially. |

Every driver returns fully-buffered results (part of the `SqlResult`
contract — stop iterating whenever you like, nothing is left to drain),
and parameterized calls go through `execute()`: real server-side binding
on Postgres (`pg_send_query_params`) and PDO, escaped client-side
interpolation on native MySQL (whose async mode has no bind step; the
client pins the connection charset explicitly so escaping is always
performed against a known charset).

The `auto` split is measured, not aesthetic: under boot-and-die PHP-FPM,
per-request connection handshakes and per-query client CPU dominate, and
an async client's I/O overlap cannot pay for them (sub-millisecond
queries leave nothing to overlap); under a persistent worker, connections
amortize across requests and native async fan-out keeps its benefits at
native protocol cost.

The PDO drivers run with *native* (non-emulated) prepares, where every
`prepare()` is its own server round trip — so `execute()` memoizes
prepared statements per SQL string for the connection's lifetime. A
loop issuing the same parameterized statement N times costs N+1 round
trips instead of 2N; against a sub-millisecond database that's the
difference between paying the network once or twice per query. The cache holds at most 256 statements (workloads that
interpolate values into their SQL text instead of binding reset it on
overflow rather than growing it forever) and is dropped with the
connection on `close()`.

```{warning}
Server-side prepared statements are scoped to a **database connection**
— which is exactly the cache's lifetime, so direct connections are
always safe. But a proxy that multiplexes one client connection across
several server connections (PgBouncer in transaction pooling mode being
the classic case) breaks that assumption for *any* client using native
prepares, this one included. Behind such a proxy, use session pooling
mode, or a proxy version that tracks prepared statements itself.
```

Two runtime notes for `native`: mysqli cannot expose its socket to the
event loop, so while its queries are in flight the client polls with a
short (1 ms) blocking window per loop turn — indistinguishable from a
blocking wait when the request's only outstanding work is the database,
and at worst a 1 ms delay per turn for anything else scheduled
concurrently. ext-pgsql *does* expose its socket (`pg_socket()`), so the
Postgres native driver is fully event-driven with no polling at all.

### Connection options

One canonical, driver-neutral option set — discrete, connection-scoped
keys, each translated by whichever driver gets built:

```{code-block} text
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
DB_SSLMODE=verify-full
DB_SSL_CA=/etc/ssl/certs/db-ca.pem
DB_SSL_CERT=/etc/ssl/certs/db-client.pem
DB_SSL_KEY=/etc/ssl/private/db-client.key
DB_CONNECT_TIMEOUT=5
DB_APP_NAME=myapp
DB_COMPRESSION=false
DB_MAX_CONNECTIONS=12
```

| canonical key | native mysqli | PDO mysql | native pgsql | PDO pgsql |
|---|---|---|---|---|
| `DB_CHARSET` | `set_charset()` | DSN `charset=` | `client_encoding` | `client_encoding` |
| `DB_COLLATION` | `SET NAMES ... COLLATE` | `SET NAMES ... COLLATE` | — | — |
| `DB_SSLMODE` | `MYSQLI_CLIENT_SSL` + verify flag | `MYSQL_ATTR_SSL_*` | `sslmode` | `sslmode` |
| `DB_SSL_CA` | `ssl_set()` | `MYSQL_ATTR_SSL_CA` | `sslrootcert` | `sslrootcert` |
| `DB_SSL_CERT` | `ssl_set()` | `MYSQL_ATTR_SSL_CERT` | `sslcert` | `sslcert` |
| `DB_SSL_KEY` | `ssl_set()` | `MYSQL_ATTR_SSL_KEY` | `sslkey` | `sslkey` |
| `DB_CONNECT_TIMEOUT` | `MYSQLI_OPT_CONNECT_TIMEOUT` | `PDO::ATTR_TIMEOUT` | `connect_timeout` | `connect_timeout` |
| `DB_APP_NAME` | — | — | `application_name` | `application_name` |
| `DB_COMPRESSION` | `MYSQLI_CLIENT_COMPRESS` | `MYSQL_ATTR_COMPRESS` | — | — |

A "—" is not a silent ignore: setting an option the selected driver
cannot honor throws at construction, naming both the option and the
driver — a config that works on one runtime never silently *means
something different* on another.

`DB_SSLMODE` takes libpq's vocabulary on every driver: `disable`,
`require` (encrypt, don't verify the peer), `verify-ca`, and
`verify-full` (verify against `DB_SSL_CA`). The opportunistic `allow`/
`prefer` modes are libpq-only — MySQL clients have no opportunistic
TLS, so those two values throw at construction on the MySQL drivers.
Three more MySQL-side rules, all loud construction errors rather than
silently weakened connections: a verify mode without `DB_SSL_CA` (there
is nothing to verify against), a `DB_SSL_CA` without a verify mode (it
would be silently ignored), and — a mysqlnd behavior, not a choice —
`verify-ca` verifies the hostname too, so it acts as `verify-full`:
stricter than asked, never looser.

#### Mutual TLS: client certificates

Where the server authenticates the client too — MySQL's
`REQUIRE X509`, Postgres's `clientcert=verify-ca` in `pg_hba.conf` —
point `DB_SSL_CERT` and `DB_SSL_KEY` at the client certificate and its
private key. Every driver supports this:

```{code-block} text
DB_SSLMODE=verify-full
DB_SSL_CA=/etc/ssl/certs/db-ca.pem
DB_SSL_CERT=/etc/ssl/certs/db-client.pem
DB_SSL_KEY=/etc/ssl/private/db-client.key
```

Presenting a client certificate is independent of *server*
verification, so it is valid under any mode that performs a handshake,
`require` included. Two rules are construction-time errors rather than
a connection that quietly means something else: the certificate and the
key must be set together (one without the other is unusable), and
either one requires TLS — under `disable`, or with `DB_SSLMODE` unset,
a client certificate would never be presented at all.

```{warning}
Postgres refuses a client key that is readable beyond its owner: it
must be `0600` (or `0640` when owned by root). The message names the
file, but it comes from libpq at connect time, so it surfaces as a
connection failure rather than a configuration error. MySQL imposes no
such requirement — a deployment that works against MySQL can fail
against Postgres for this reason alone.
```

MySQL charset defaults to `utf8mb4` on every driver when `DB_CHARSET`
is unset — never the server's own default, since the native driver's
client-side escaping is charset-dependent and must run against a known
charset.

The legacy `DB_OPTIONS` string is still accepted as a migration path:
key=value pairs whose keys have canonical equivalents (`charset`,
`collate`, `sslmode`, `sslrootcert`, `connect_timeout`,
`applicationName`, `compress`, ...) are translated automatically, with a discrete key winning over a
`DB_OPTIONS` spelling of the same option. Untranslatable keys pass
through raw **only** to the Postgres drivers (libpq natively accepts
free-form connection-string keys and validates them itself at connect
time) and are rejected loudly by the MySQL drivers, which have no
free-form surface to pass them to.

**`$poolOptions`**, an optional `fromConfig()` argument, carries the one
pool-level knob:

```{code-block} php
$db = SqlConnectionFactory::fromConfig($config, poolOptions: [
    'maxConnections' => 6,
]);
```

`maxConnections` (default 8) bounds an async driver's fan-out width —
connections open lazily up to the cap, and callers beyond it wait for a
free connection inside the pool. The PDO drivers are a single lazy
connection, trivially within any cap. The connection-scoped
`DB_MAX_CONNECTIONS` key sets the same width from the environment — a
deployment tunes pool sizing without editing bootstrap code — with an
explicit `$poolOptions` value winning over the key when both are set.

`warmConnections` opens that many connections at construction instead
of on first use (clamped to `maxConnections`); the connection-scoped
`DB_WARM_CONNECTIONS` key does the same from the environment, with the
same explicit-value-wins precedence. Every driver also exposes the
underlying call directly — `warmUp(?int $connections = null)`, where
`null` warms the whole pool. Warming makes a wrong database
configuration fail at boot instead of on the first query, and under
FrankenPHP worker mode it is **load-bearing for the native MySQL
driver**, not just a latency optimization — see
{doc}`performance-tuning`'s "mysqli's poll limit" for why boot-time
connecting is what keeps that driver's sockets pollable at all.

### Sizing `maxConnections` under worker mode

Under `FpmAdapter`, `auto` selects the PDO driver — one connection per
worker process — so `maxConnections` doesn't apply at all there;
`concurrently()` fan-outs run their queries sequentially on that one
connection, which for typical sub-millisecond queries is the faster
trade (measured, not assumed: per-request handshakes and per-query
client CPU dominate under boot-and-die).

Under `FrankenPhpAdapter`'s worker mode it's a genuinely different
shape, not just a bigger version of the same thing: the bootstrap chain
(package bootstraps and `bootstrap.php` alike) runs
once *per worker thread* (see {doc}`runtime-adapters`'s "Sizing
FrankenPHP's worker threads" section), so **every worker thread builds
its own separate pool** — there is no single, process-wide shared pool
the phrase "a persistent worker" might suggest. The real ceiling on
simultaneous database connections is `num_workers × maxConnections`, not
`maxConnections` alone: 128 worker threads each configured with
`maxConnections: 256` can open up to 32,768 real connections, not 256 —
almost certainly far more than your database allows, and every one of
them costs the database real memory and setup work even when the client
survives the rejection.

Size `maxConnections` so `num_workers × maxConnections` stays
comfortably under your database's own `max_connections` — not so that
`maxConnections` alone matches your expected total concurrency. If a
single request's `concurrently()` fan-out needs more connections than
that per-worker budget allows, the excess queries queue *inside* the
pool instead, adding latency to that one request — a far softer failure
mode than a rejected connection that can take the whole worker thread
down for good.

```{warning}
**During an open transaction, run every statement through the
transaction object — never through the client.** The two driver
families give client-level calls opposite semantics there: a PDO
driver is a single connection, so a client `execute()` while a
transaction is open silently *joins* it (and rolls back with it),
while an async driver runs the same call on a *different* pooled
connection, entirely outside the transaction. Code that mixes the two
behaves differently between runtimes under `DB_DRIVER=auto`. The
transaction object pins one connection and is the only portable way to
address it.
```

## `TransactionGuard` — the request-scoped safety net

`Kernel` degrades gracefully when `kinetis/persistence` isn't installed
(no dispose hook registered, no error), so an application with no
database at all can skip it entirely.

Connection pooling is the drivers' own job. What no driver can know
about is Kinetis's `RequestScope` (see {doc}`container`): if application
code begins a transaction and something throws before it's explicitly
committed or rolled back, nothing closes it — and it leaks into whatever
the next thing to borrow that pooled connection does.

`Kinetis\Persistence\TransactionGuard` is the request-scoped safety net for
exactly this. It's autowired fresh per request, like any other class you
haven't explicitly registered on `AppScope`, and tracks every transaction
it starts.

### The recommended pattern

```{code-block} php
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\TransactionGuard;

final readonly class OrderController
{
    public function __construct(
        private TransactionGuard $transactions,
        private MysqlLink $db,
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
and Postgres: all drivers implement the same `Contract\SqlLink`/
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
`REDIS_CLUSTER`) without this package installed binds a cache whose every
operation throws `SimpleCacheUnavailableException` naming the package —
so an application that never touches the cache still boots and runs (a
leftover `REDIS_*` in a `.env` is not a fatal condition), while one that
does use it fails loudly at the first call rather than silently degrading
to `NullSimpleCache`.

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

### Fetch keys in batches, not one at a time

`getMultiple()`/`setMultiple()`/`deleteMultiple()` are worth reaching for
whenever you need several keys. On a single-node cache `getMultiple()`
issues one `MGET`, which costs roughly a tenth of the client CPU per key
that the same keys fetched one `get()` at a time do — one round trip and
one reply parsed, instead of N of each. It is the single largest
performance lever this cache has.

```{code-block} php
// One round trip.
$rows = $this->cache->getMultiple(['user.1', 'user.2', 'user.3']);

// N round trips, each with its own protocol overhead.
foreach ([1, 2, 3] as $id) {
    $rows[] = $this->cache->get("user.{$id}");
}
```

```{note}
The Redis client is `amphp/redis` — a pure-PHP implementation of the
protocol on the Revolt event loop, so its overhead is paid per event-loop
wakeup rather than per command, and amortizes as more commands are in
flight at once. Under a persistent worker that is the normal state: with
around eight or more concurrent requests holding an outstanding Redis
command, per-operation client CPU is at or below what a blocking native
extension costs, and no request blocks the worker thread while it waits.

Under PHP-FPM, where a process handles exactly one request at a time,
there is nothing to amortize against and a single cache operation costs
roughly three times the client CPU a blocking client would — measured,
not estimated. It is a small absolute number, and batching as above
shrinks it far more than any client swap would, but it is worth knowing
if you run a cache-heavy application on PHP-FPM specifically.
```

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

- {doc}`concurrency` — `concurrently()`, and how the persistence drivers'
  Fiber-suspending calls compose with `Kinetis\Async`'s own primitives on
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
- {doc}`performance-tuning` — the worker-threads x connections
  budget, what to observe under load, and tuning by workload shape.
