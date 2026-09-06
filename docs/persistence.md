# Persistence

Kinetis connects to MySQL, Postgres, and Redis through clients matched to
the runtime actually serving your application. Under a persistent worker
(FrankenPHP or RoadRunner), queries suspend only their own request's
Fiber — a request waiting on the database doesn't stop the worker's
request from making progress on anything else it has in flight. Under
PHP-FPM, where a worker
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
specific minimum version matters is `kinetis/queue-sql`; see
{doc}`queue-sql`.

```{note}
Core itself has no MySQL/Postgres/Redis dependency of its own —
`Kinetis\Persistence\TransactionGuard`/`SqlConnectionFactory` live in the
separate `kinetis/persistence` package, and
`Kinetis\SimpleCache\RedisSimpleCache` lives in `kinetis/cache-redis`,
over the standalone `kinetis/redis` transport ({doc}`redis`).
`composer require` whichever you need; each is introduced with its own
installation note below at first use.
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

```{note}
A pooled connection the server closes (an idle socket past
`wait_timeout`, an administrative `KILL`, a network drop) costs exactly
one query. Writing to a socket whose peer is already gone is buffered
locally rather than failing, so the first query on a newly-dead
connection dispatches successfully and only discovers the death while
reading the result — surfacing as an `Exception\ConnectionException`
where the client reports the session gone, and an
`Exception\QueryException` where the server answered. Either way the
caller has to handle it. Retrying it automatically is not an option: at
that point the statement may already have executed, and replaying a
non-idempotent one silently is worse than an error. A transaction pinned
to that connection ends with it, discarding the connection rather than
rolling back on a session that is gone. The next query's dispatch does fail
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
| `auto` (default) | FrankenPHP worker mode or RoadRunner → `native`; PHP-FPM → `pdo`. |
| `native` | mysqli's `MYSQLI_ASYNC` (`Driver\MysqliAsyncClient`) or ext-pgsql's `pg_send_query` (`Driver\PgsqlAsyncClient`): the wire protocol runs at C speed inside the extension, queries overlap across connections, and each waits by suspending only its own Fiber — full `concurrently()` support. The Postgres client also needs `ext-sockets` and refuses to construct without it. |
| `pdo` | One blocking PDO connection (`Driver\PdoMysqlClient`/`PdoPgsqlClient`). `concurrently()` fan-outs still produce correct results; the queries simply run sequentially. |

`fromConfig()`'s `$driver` argument overrides the key for one call.
`kinetis/migrations` passes `'pdo'` for the session-scoped connection
its advisory lock needs (see {doc}`migrations`).

Every driver returns fully-buffered results (part of the `SqlResult`
contract — stop iterating whenever you like, nothing is left to drain),
and parameterized calls go through `execute()`: real server-side binding
on Postgres (`pg_send_query_params`) and PDO, escaped client-side
interpolation on native MySQL (whose async mode has no bind step; the
client pins the connection charset explicitly so escaping is always
performed against a known charset).

One call carries one statement. SQL producing more than one result set
throws `Exception\QueryException` rather than returning the first:
draining the rest would block the event loop on the async drivers, and
an unread result set left on a pooled connection fails whatever borrows
it next. The connection survives either way — `mysqli` discards its own
for the pool to replace, and the others drain what is left. PDO Postgres
is the one case the rule cannot reach: libpq runs a semicolon-separated
string as a single command and reports only its last result. Issue one
`query()`/`execute()` per statement.

A later result set can carry the server's own error rather than rows —
what a stored procedure raising `SIGNAL` after a `SELECT` produces. It
reaches the caller as `Exception\QueryException` like any other server
error, and the span records the failure it is: nothing is built, and
nothing reported, until every result set has been read.

`COPY` is not supported on the native Postgres driver: it puts the
connection into a streaming mode the driver has no protocol for, and the
server holds it there waiting for data that is never coming. The
connection is taken out of service and replaced by the pool, and the
caller gets an `Exception\ConnectionException` — the exchange was lost,
which is a different thing from a statement the server refused. Use a
server-side `COPY` — one that reads or writes a file the server itself
can reach — or ordinary statements.

Every parameterized call passes one **pre-flight** first — before the
driver opens a telemetry span, asks its pool for a connection, opens one,
sets that connection's charset and collation, or prepares a statement.
Keying, count and value kind are all settled there, so an argument list
outside the contract costs the caller one `Exception\QueryException` and
nothing else: nothing is sent to a server, and nothing is opened to send
it to. A cold or unreachable client refuses the call exactly as a warm
one does.

That ordering is itself the contract, not an optimization. A driver
checking on its way through execution would answer a caller's own
mistake with a `ConnectionException` from a server it was never going to
send to, and would answer the identical call differently depending on
whether its pool happened to be warm already. Transactions run the same
pre-flight, ahead of anything reaching their pinned connection.

`execute()` takes exactly one argument per `?` placeholder, on every
driver: a call carrying more or fewer throws naming both counts. That is
a contract rather than an incidental check on the PDO drivers, whose
prepared statements are memoized (below): a reused statement still
holds what was last bound to it, so a short argument list without the
check would silently execute against the previous call's leftover value
in the position it omitted.

Those arguments are a **list** — keys `0..n-1`, in the order the
placeholders appear. An associative or sparse array is rejected rather
than reindexed: `pdo` binds by iteration order while `native` indexes by
position, so the two would read `['b' => 2, 'a' => 1]` as two different
queries. Reindexing it here would settle that disagreement on an
argument list the call site never wrote, which is the mistake worth
seeing.

Each argument is one of five kinds: `null`, `bool`, `int`, a **finite**
`float`, or `string`. Anything else — an array, a resource, an object,
`INF`, `NAN` — throws `Exception\QueryException` naming the position and
the type, with the whole list read before a single value is encoded or
bound, so a refused call leaves no position bound. Postgres adds one
rule of its own: a string holding a NUL byte is refused, since libpq
carries text parameters as C strings and the value would reach the
server truncated at that byte. MySQL takes one intact, which is what a
`VARBINARY`/`BLOB` column needs. The narrower set is
the one all four drivers agree on, so the same call is refused the same
way whichever driver `DB_DRIVER` picked. Format a `DateTimeInterface`,
an enum or a JSON payload at the call site, where the shape is your
decision rather than the driver's.

Which `?` is a placeholder is decided by one dialect-aware scan of the
SQL text, shared by every driver — the native drivers substitute each
one they find, the PDO drivers count them for the argument check above.
It recognizes `'...'`/`"..."`/`` `...` `` quoting, `--`/`#`/`/* */`
comments, and Postgres's `$$...$$`/`$tag$...$tag$` dollar-quoted strings,
so a `?` inside any of those is data, never a slot. Postgres's own jsonb
containment/existence operators (`?`, `?|`, `?&`) are lexically identical
to a placeholder at the position they appear — write `??`, `??|`, `??&`
to mean the literal operator rather than a bind slot. A `$` that
continues the identifier to its left opens nothing: `col$tag$` is one
Postgres identifier, and a dollar-quoted literal following an identifier
or a keyword has to be separated from it (`col $tag$`), the same way the
server lexes it. A dollar-quote tag is spelled with Postgres's own
unquoted-identifier bytes, non-ASCII included, so `$é$ ... $é$` quotes
its contents exactly as `$body$ ... $body$` does.

Two comment rules match real MySQL rather than a generic reading of the
syntax. `--` only opens a comment against MySQL when the second dash is
followed by whitespace, a control character, or the end of the string —
`5--?` is `5 - - ?`, not a comment (Postgres has no such condition; a
bare `--` always opens one there). MySQL/MariaDB's *executable*
comments (`/*! ... */`, `/*M! ... */`) are scanned as ordinary comments:
their text is copied through verbatim for the connected server to
interpret on its own, and a `?` inside one is data rather than a bind
slot. A query meaning one to be bound fails loudly — on the argument
count, or on the server that executes the comment — rather than binding
something silently. Write the bound value outside the comment.

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
connection on `close()`. A transaction runs on the client's own
connection, so it shares that one cache rather than re-preparing what it
already holds.

```{warning}
Server-side prepared statements are scoped to a **database connection**
— which is exactly the cache's lifetime, so direct connections are
always safe. But a proxy that multiplexes one client connection across
several server connections (PgBouncer in transaction pooling mode being
the classic case) breaks that assumption for *any* client using native
prepares, this one included. Behind such a proxy, use session pooling
mode, or a proxy version that tracks prepared statements itself.
```

#### What blocks and what does not on `native`

**MySQL.** mysqli cannot expose its socket to the event loop, so while
its queries are in flight the client polls with a short (1 ms) blocking
window per loop turn — indistinguishable from a blocking wait when the
request's only outstanding work is the database, and at worst a 1 ms
delay per turn for anything else scheduled concurrently. Opening a
connection blocks outright: mysqli has no async connect primitive, so a
connection opened under load stalls the worker thread for a TCP connect,
a TLS handshake and an auth exchange. Warm the whole pool at boot
(`DB_WARM_CONNECTIONS`, which this driver wants anyway — see below) and
set `DB_CONNECT_TIMEOUT`, so an unreachable server bounds the stall
instead of leaving it to the platform.

**Postgres.** ext-pgsql exposes its socket (`pg_socket()`), and the
driver keeps every phase off the loop. Connecting runs
`PGSQL_CONNECT_ASYNC` and drives the handshake from readiness waits,
bounded by `DB_CONNECT_TIMEOUT`. Dispatch puts libpq into nonblocking
mode first, so a parameter larger than its output buffer leaves bytes
queued and the loop pushes them out, instead of one
`pg_send_query_params()` call flushing megabytes synchronously. Queued
output is waited on in both directions: the server sends `NOTICE` and
`NOTIFY` traffic while a statement is still going out, so a readable
socket is consumed before the flush continues, and a client that only
watched for writability would fill both socket buffers and stop.
Disposal ends the connection's transport rather than draining it, which
is why this driver needs `ext-sockets`: closing a libpq connection the
ordinary way reads every outstanding result first, and a statement still
running — or a `COPY` the server is waiting on input for — would be a
wait the whole loop pays.

One thing stays synchronous: libpq resolves the host name itself, inside
the connect call, so a slow DNS resolver stalls the worker thread for as
long as it takes. Point `DB_HOST` at an address, or at a name the
platform resolves from cache, on any deployment where that matters.

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
| `DB_SSLMODE` | `MYSQLI_CLIENT_SSL` + verify flag | `Pdo\Mysql::ATTR_SSL_*` | `sslmode` | `sslmode` |
| `DB_SSL_CA` | `ssl_set()` | `Pdo\Mysql::ATTR_SSL_CA` | `sslrootcert` | `sslrootcert` |
| `DB_SSL_CERT` | `ssl_set()` | `Pdo\Mysql::ATTR_SSL_CERT` | `sslcert` | `sslcert` |
| `DB_SSL_KEY` | `ssl_set()` | `Pdo\Mysql::ATTR_SSL_KEY` | `sslkey` | `sslkey` |
| `DB_CONNECT_TIMEOUT` | `MYSQLI_OPT_CONNECT_TIMEOUT` | `PDO::ATTR_TIMEOUT` | `connect_timeout` | `connect_timeout` |
| `DB_APP_NAME` | — | — | `application_name` | `application_name` |
| `DB_COMPRESSION` | `MYSQLI_CLIENT_COMPRESS` | `Pdo\Mysql::ATTR_COMPRESS` | — | — |

`Pdo\Mysql::ATTR_*`, not the equivalent, deprecated-as-of-PHP-8.5
`PDO::MYSQL_ATTR_*` constants — identical underlying values, just without
the deprecation notice.

A "—" is not a silent ignore: setting an option the selected driver
cannot honor throws at construction, naming both the option and the
driver — a config that works on one runtime never silently *means
something different* on another.

The PDO drivers refuse a `;` or a NUL byte in any value they put in a
DSN — host, database, and the Postgres options above. PDO's DSN grammar
has no quoting for either: pdo_mysql splits its DSN on `;`, pdo_pgsql
turns every `;` into a space for libpq, and a NUL ends the C string, so
such a value would be read as further connection parameters.

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

**`$poolOptions`**, an optional `fromConfig()` argument, carries the one
pool-level knob:

```{code-block} php
$db = SqlConnectionFactory::fromConfig($config, poolOptions: [
    'maxConnections' => 6,
]);
```

`maxConnections` (default 8) bounds an async driver's fan-out width —
connections open lazily up to the cap, and callers beyond it wait for a
free connection inside the pool. A connection being opened counts
against the cap for the whole attempt, not only once it is finished, so
a burst of callers arriving at an empty Postgres pool opens
`maxConnections` connections between them rather than one each. The PDO
drivers are a single lazy connection, trivially within any cap. The
connection-scoped
`DB_MAX_CONNECTIONS` key sets the same width from the environment — a
deployment tunes pool sizing without editing bootstrap code — with an
explicit `$poolOptions` value winning over the key when both are set.

`warmConnections` opens that many connections at construction instead
of on first use (clamped to `maxConnections`); the connection-scoped
`DB_WARM_CONNECTIONS` key does the same from the environment, with the
same explicit-value-wins precedence. Every driver also exposes the
underlying call directly — `warmUp(?int $connections = null)`, where
`null` warms the whole pool. Warming makes a wrong database
configuration fail at boot instead of on the first query, and under a
persistent worker (FrankenPHP or RoadRunner) it is **load-bearing for
the native MySQL driver**, not just a latency optimization — see
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

`RoadRunnerAdapter` runs into the identical multiplication, for the same
underlying reason — the bootstrap chain runs once per worker, so each
one builds its own pool — just with worker *processes* standing in for
worker threads: RoadRunner's own `pool.num_workers` is the multiplier
instead of FrankenPHP's `worker.num`, but `num_workers × maxConnections`
against your database's `max_connections` is the exact same budget to
size against either way.

Size `maxConnections` so `num_workers × maxConnections` stays
comfortably under your database's own `max_connections` — not so that
`maxConnections` alone matches your expected total concurrency. If a
single request's `concurrently()` fan-out needs more connections than
that per-worker budget allows, the excess queries queue *inside* the
pool instead, adding latency to that one request — a far softer failure
mode than a rejected connection that can take the whole worker thread
down for good.

## Transactions

`beginTransaction()` pins one connection and returns a `SqlTransaction`
with the same `query()`/`execute()` surface as the client. Every
statement belonging to the transaction goes through that object: a
client-level call while it is open is refused rather than served, on
every driver.

A PDO client holds one connection, so the transaction owns the client
for as long as it runs — `query()`, `execute()` and a second
`beginTransaction()` on the client throw
`Exception\TransactionException` until it ends. An async client pools
connections, so the refusal is scoped to the Fiber holding the
transaction: another Fiber keeps its own connection and can open a
transaction of its own, while the holder's client-level call would land
on a different connection, in autocommit, outside the transaction it
believes it is in.

A transaction also belongs to the Fiber that began it. `query()`,
`execute()`, `commit()` and `rollback()` from any other Fiber throw:
the pinned connection carries one statement at a time, so a second
Fiber dispatching on it would corrupt both. Give that Fiber its own
transaction instead.

`rollback()` on a transaction that has already ended is a no-op, so
`catch (Throwable) { $tx->rollback(); throw $e; }` needs no `isActive()`
check first. `commit()` throws there. A `COMMIT` or `ROLLBACK` the
server refuses throws and discards the connection rather than handing it
back: what is left on it is a transaction of unknown outcome.

The span's outcome attribute says only what the server confirmed:
`commit` for a `COMMIT` it acknowledged, `rollback` for a `ROLLBACK` it
acknowledged, and `unknown` for everything else — a lost connection, a
discarded connection, a finish nothing answered, a transaction the
server ended on its own. A transaction that never sent either statement
is `unknown` too: the work is discarded with the session, which is not
the same as a rollback the server reported.

`isActive()` stays true until the connection has been handed back and
the span closed, which is a moment later than the last statement being
accepted: while a `COMMIT` is on the wire the transaction refuses
further statements but still owns its connection. That window is what
`close()` — and so `TransactionGuard` at request disposal — has to be
able to reach. Closing there takes the connection out from under the
finish, the owning Fiber comes back with an
`Exception\ConnectionException`, and the outcome is recorded once, as
`unknown`.

### What the server does underneath the object

A transaction can end where the server is while the object still
believes it is open, and the statements that follow would then run in
autocommit. Each driver settles it from what it can see locally, without
a probe round trip, after every statement — succeeded or failed — and
before the next one:

- **Postgres, both drivers** — libpq tracks the transaction status the
  server sent with its last message, so an implicitly ended transaction
  is visible directly. A failed statement aborts the whole transaction
  there rather than ending it: the server answers a later `COMMIT` with
  a rollback and no error, so Kinetis ends it as the rollback it is and
  throws `Exception\TransactionException` rather than reporting a commit
  that did not happen.
- **PDO MySQL** — `PDO::inTransaction()` reads the same status flag,
  which is what makes MySQL's implicit commit on DDL (see
  {doc}`migrations`) visible.
- **Native mysqli** — mysqli exposes no transaction-status accessor, so
  an implicit commit cannot be seen at all: keep DDL and raw `COMMIT`,
  `ROLLBACK` and `SAVEPOINT` statements out of a transaction on this
  driver.

Where a driver can see it, `isActive()` both reports the transaction
gone and settles it — connection handed back, nothing left to close.

Both MySQL drivers add one rule the status flag is not allowed to
decide. A deadlock (error 1213) is always resolved by rolling the losing
transaction back whole. A lock-wait timeout (1205) rolls back only the
statement — unless `innodb_rollback_on_timeout` is on, where it rolls
back the whole transaction too, and nothing in the error says which
setting is live. Both therefore end the transaction, discard its
connection rather than handing it to the next caller, and record the
outcome as `unknown`. The server's own error number is the
`Exception\QueryException`'s code, so a caller that wants to retry can
still tell the two apart:

```{code-block} php
try {
    $guard->transaction($db, $work);
} catch (QueryException $e) {
    if ($e->getCode() === 1213) {
        // Deadlock: the server rolled it back whole; retrying is the
        // documented response.
    }
}
```

`close()` is the lifecycle escape hatch — what `TransactionGuard` runs
at scope disposal — and is callable from any Fiber. On the owning Fiber
it is an ordinary rollback. From another it ends the transaction and
takes the connection out of service rather than sending a `ROLLBACK`
down one the owner may be using: the pool replaces it, and the server
rolls the work back with the session. A statement still in flight when
that happens is settled where it stands, with a
`Exception\ConnectionException` saying the connection went before the
server acknowledged anything — the statement may well have run.
Closing a PDO client does the same to the transaction holding it, since
both run on the client's one connection.

### A transaction nothing ends

Calling `beginTransaction()` on a link makes ending the transaction the
caller's own job, and an exception path that drops the object without
reaching `commit()`, `rollback()` or `close()` leaves nobody holding it:
a driver keeps a transaction's owner Fiber, never the transaction. The
last reference going away is where such a transaction ends. Its
connection is discarded, the Fiber's client-level ownership goes with
it, and the span closes with the outcome `unknown`.

Nothing goes on the wire there. That cleanup runs in a destructor, which
cannot suspend and so cannot wait for an answer; a `ROLLBACK` dispatched
with nobody to read the reply would sit on a connection about to serve
someone else. The server rolls the work back as the session goes, which
is not a `ROLLBACK` it acknowledged — and the span says so rather than
claiming one.

What that costs is the connection: an async client's pool opens a
replacement, and a PDO client, holding one connection and never
reopening it, closes. `TransactionGuard::transaction()` costs neither —
it ends the transaction on every path out of the work, so the connection
goes back to the pool with the outcome the server confirmed. Use the
guard; the discard is a safety net for a connection, not a way to end a
transaction.

## `TransactionGuard` — the request-scoped safety net

`Kernel` degrades gracefully when `kinetis/persistence` isn't installed
(no dispose hook registered, no error), so an application with no
database at all can skip it entirely.

Connection pooling is the drivers' own job. What no driver can know
about is Kinetis's `RequestScope` (see {doc}`container`): if application
code begins a transaction and something throws before it's explicitly
committed or rolled back, nothing commits or rolls it back, and it holds
its connection — and the locks on it — for as long as anything still
references it. Dropped, it ends the only way a destructor can, by
discarding that connection.

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
through the guard's own `beginTransaction()` and held open across
multiple calls, that never reaches either `commit()` or `rollback()`
before the unit of work ends —
`Kinetis\Container\TransactionGuardHook::registerIfAvailable()`
registers `rollbackDangling()` as a `RequestScope` dispose hook:

```{code-block} php
Kinetis\Container\TransactionGuardHook::registerIfAvailable($scope);
```

This is the one shared place every entry point that owns a `RequestScope`
for one unit of work wires this in — a plain string class-name check
(`class_exists('Kinetis\Persistence\TransactionGuard')`), so it costs
nothing when `kinetis/persistence` isn't installed, and it's a genuine
no-op for a unit of work that never opens a transaction even when it is.
Every one of these calls it **unconditionally**, not opt-in the way, say,
MCP support is (see {doc}`mcp`):

- `Kernel`, for every HTTP request.
- `bin/kinetis`, for every CLI command that hasn't declared
  `#[Command(bootstrap: false)]` — a bootstrap-free command has no
  database connection to guard in the first place.
- `kinetis/mcp`'s `Transport\StdioTransport` and `Http\McpController`,
  for every MCP message, over stdio and over HTTP alike.
- `kinetis/queue`'s `QueueWorker`, for every popped job's own
  `RequestScope`, and `SyncQueue`, for every `push()`'s own `RequestScope`
  — a job that begins a transaction and returns or throws without closing
  it does not leave that transaction open into whatever job the same
  pooled/native connection serves next.

When it does find something to close, it logs a warning through whatever
logger you've registered (see {doc}`logging`) — a genuine anomaly signal,
since it means a transaction was left open somewhere it shouldn't have
been.

What it finds is what it started: `$guard->beginTransaction($link)` and
`transaction()`, the two calls that put a transaction on its tracked
list. One begun straight off the link is not tracked here or anywhere
else, and ends by being dropped — connection discarded, outcome
`unknown`. Route a transaction you hold open across several calls
through the guard, and disposal rolls it back on the wire and hands the
connection back instead.

Both `beginTransaction()` and `transaction()` work identically for MySQL
and Postgres: all drivers implement the same `Contract\SqlLink`/
`SqlTransaction` abstraction, so `TransactionGuard` never needs to know
which one it's actually talking to.

### What happens when cleanup itself fails

Inspecting a transaction (`isActive()`) or closing one (`close()`) is
itself a network call to a driver — it can fail, and this class is
designed around that possibility rather than assuming it away.

**`rollbackDangling()` is best-effort across the complete tracked set,
not fail-fast.** One transaction's `isActive()` or `close()` throwing
never prevents the rest from being attempted — a cleanup fault on one
connection must not leak transactions/locks on every other tracked one.
Tracking is cleared up front, before any transaction is touched, so a
transaction this call already attempted — successfully or not — is never
retried by a later call. Each failure is logged individually (`error`,
not `warning`), and the first of them is rethrown once every tracked
transaction has been attempted — safe to let propagate, since
`RequestScope::dispose()` already runs every dispose callback to
completion regardless of one throwing (see {doc}`container`), and
rethrows only once all of them have finished.

It closes rather than rolls back because disposal runs in the request's
own context while the Fiber that leaked the transaction may be parked:
`close()` from a foreign Fiber ends the transaction and discards its
connection instead of putting a concurrent `ROLLBACK` on it.

**`transaction()` never lets a rollback failure erase the failure that
triggered cleanup.** If your callback (or `commit()`) throws, and the
resulting rollback attempt *also* throws, the rollback failure is logged
and the original exception — the one your code actually threw — is what
propagates, unchanged. The transaction is untracked either way, whether
the rollback attempt succeeded or failed: `transaction()` only ever makes
one cleanup attempt of its own, and leaving a failed one tracked would
defer a second attempt to `rollbackDangling()` at scope disposal — inside
a `finally` block, where a second failure there would silently replace
the exception already propagating from `transaction()`, undoing the same
guarantee one level up.

**None of this depends on the logger being healthy.** `Psr\Log\LoggerInterface`
gives no no-throw guarantee, and a failing log handler — a broken remote
sink, a full disk — is a real production scenario. Every log call this
class makes is wrapped so an exception from the logger itself is
discarded: it can never be misclassified as a rollback failure, never
prevent a later tracked transaction from being attempted, and never
replace an already-propagating exception the way an unprotected logger
call could.

## Redis

```{code-block} sh
composer require kinetis/redis
```

```{code-block} php
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Endpoint;

$redis = Client::create(Endpoint::parse('localhost:6379'), new ClientOptions());

$redis->execute('SET', 'session:abc123', $payload);
$value = $redis->execute('GET', 'session:abc123');
```

`kinetis/redis` is the transport the cache and the queue below both use:
one operation budget covering connect through reply, a command that is
never re-sent after a connection failure, and Redis Cluster slot routing.
{doc}`redis` documents it in full. Redis has no request-spanning
transaction concept the way SQL does, so nothing like `TransactionGuard`
applies here.

## `Psr\SimpleCache\CacheInterface` — a PSR-16 cache

```{code-block} sh
composer require kinetis/cache-redis
```

`Kinetis\SimpleCache\RedisSimpleCache` (below) lives in this separate
package — core ships only `NullSimpleCache` and the
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
REDIS_CACHE_NAMESPACE=default
```

`REDIS_URL`, if set, wins outright over the discrete parts.
`REDIS_TIMEOUT` is the whole per-operation budget, connect and cluster
redirects included, not a connect timeout. Values are serialized with the
same `Amp\Serialization\NativeSerializer` `Amp\Redis\RedisCache` itself
uses internally, so any serializable PHP value — not just strings — can
be stored, per the PSR-16 contract.

Every key is stored as `kinetis_cache:<namespace>:<key>`, where the
namespace is `REDIS_CACHE_NAMESPACE` (letters, digits, underscores and
dashes; `default` unless set). That prefix is what keeps `clear()` off
keys this cache did not write, and it carries no `{}` hash tag, so keys
still spread across cluster slots.

`fromConfig()` takes an optional `string $connection = 'default'`,
following {doc}`config`'s named-connection convention:

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

`clear()` scans each current master for its own namespace prefix and
unlinks what it finds, so keys written by anything else — including
another namespace of this same cache — survive it. It is neither atomic
nor a snapshot: a key written after its node's scan has passed survives,
and a key migrating between two nodes can be missed. It also costs one
pass over each node's whole keyspace, since `SCAN MATCH` filters
server-side after reading. Use it to reset a cache, not inside request
handling.

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
The Redis client is `amphp/redis`, a pure-PHP implementation of the
protocol on the Revolt event loop. Its overhead is paid per event-loop
wakeup rather than per command, so it amortizes across whatever else is
in flight at the same time. Under a persistent worker that is the normal
state: once around eight concurrent requests hold an outstanding Redis
command, per-operation client CPU settles to roughly a quarter of what a
single isolated command costs, and no request blocks the worker thread
while it waits.

Under PHP-FPM a process handles exactly one request at a time, so there
is nothing to amortize against and every cache operation pays the full
per-wakeup cost. Batching, as above, is the lever that matters there.
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
of seed addresses) instead of `REDIS_HOST`/`REDIS_URL`:

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

Discovery, `MOVED`/`ASK` handling, and the certificate requirement for
nodes a cluster announces by IP address are all {doc}`redis`'s, and
documented there.

Each seed is either `host:port` — a hostname or an IPv4 address, neither
of which ever contains a colon itself — or `[ipv6-address]:port` for an
IPv6 node, bracketed the same way a URL brackets one:

```{code-block} sh
REDIS_CLUSTER_SEEDS=[2001:db8::10]:6379,[2001:db8::11]:6379
```

An unbracketed IPv6 address (`2001:db8::10:6379`) is rejected rather than
guessed at — its own colons make it genuinely ambiguous which one
separates the address from the port. A malformed seed, an empty entry, or
a port outside 1-65535 fails immediately when the cache is configured,
before any connection is attempted.

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
- {doc}`redis` — the transport under the cache: its non-replay and
  deadline contract, cluster discovery, and redirect handling.
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
- {doc}`telemetry` — a span per SQL query and per cache operation, via
  `TracingMysqlLink`/`TracingPostgresLink`/`TracingSimpleCache`.
