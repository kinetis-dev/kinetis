# Appendix: Databases

The complete contract behind {doc}`persistence`: which package owns what,
using the packages without Kinetis, and how the drivers, transactions and
`TransactionGuard` behave. The guides — {doc}`persistence`,
{doc}`query-builder` and {doc}`orm` — cover everyday use, and
{doc}`appendix-packages` lists each package's classes.

(database-reference-packages)=
## Packages and wiring

`kinetis/persistence` holds the drivers, `SqlConnectionFactory`,
`TransactionGuard` and the `Contract\SqlLink`/`MysqlLink`/`PostgresLink`
contracts, and depends on no Kinetis package. `kinetis/database-bridge`
wires it into a Kinetis application.

`Kinetis\DatabaseBridge\ConnectionFactory::fromConfig()` reads a
connection's `DB_*` keys into a `ConnectionDefinition`, validating every
key before a driver is constructed, and builds the client through
`SqlConnectionFactory::create()` with Kinetis telemetry as its
instrumentation. Its `$driver` argument overrides `DB_DRIVER` for one
call. `ConnectionFactory::singleSession()` builds the
{ref}`single-session client <database-reference-single-session>` from the
same keys.

The bridge composes with each database package on that package's terms:

- `kinetis/persistence` receives the connection configuration, SQL
  telemetry, the default link binding and its close on application
  disposal, and the lazy request-scoped `TransactionGuard`.
- `kinetis/migrations` requires the bridge and registers its own
  `migrate*` commands, which connect through
  `ConnectionFactory::singleSession()` ({doc}`migrations`).
- `kinetis/query-builder` needs no binding. A `Query` is mutable and
  holds one statement, so code constructs `new Query($link)` per
  statement over the bound link; a `Query` is never registered as a
  shared or request-scoped service.
- `kinetis/orm` receives its entity metadata through the bridge's AOT
  discovery section, one `OrmFactoryRegistry` for the worker with a
  factory per connection, and a lazy request-scoped
  `EntityManagerRegistry` closed with its scope; `OrmFactory` and
  `EntityManager` are their default-connection entries ({doc}`orm`).

### The default link's lifetime

With `DB_CONNECTION` set, the bridge's package bootstrap builds the
default client, registers `close()` on `AppScope::onDispose()` and only
then binds it under its dialect contract, `MysqlLink` or `PostgresLink`.
Registering the close first is what makes a later bootstrap failure, or
a failing `boot()`, still close a connection that is already open
({ref}`container-app-disposal`).

The callback holds that exact object, so ownership follows whoever built
the link:

- **The bridge built it.** It is closed when the application scope is
  disposed.
- **`bootstrap.php` bound its own.** The bridge's callback still closes
  the link the bridge built, if it built one; the replacement is
  application-owned and nothing here closes it. Register its own
  `onDispose()` if it needs one.
- **No `DB_CONNECTION`.** No link is built and no link contract is
  bound, so there is nothing to register and nothing to close. "No
  database" is a configuration, not an error.
- **A named connection.** Explicit application wiring throughout,
  including its lifetime, except for a connection an entity names that
  `bootstrap.php` does not bind as `db.<name>`: the ORM wiring builds that
  one on first use and closes it when the application scope is disposed.

The bridge also binds the dialect-neutral `SqlLink` as an uncached alias
that resolves the dialect contract on every lookup. Both types inject the
same object; the alias opens no connection and registers no close of its
own. A dialect link the application binds in `bootstrap.php` is what
`SqlLink` returns from then on, even if `SqlLink` was resolved earlier,
and an application's own `SqlLink` binding replaces the alias alone.

Request-scoped cleanup is unchanged and separate: `TransactionGuard`'s
`rollbackDangling()` and `EntityManagerRegistry`'s `close()` are registered on
the scope that resolved them and run at the end of that unit of work,
not at application disposal.

### The request-scoped `TransactionGuard`

The bridge's package bootstrap registers an
`AppScope::onRequestScopeCreated()` initializer (see {doc}`container`)
that binds `TransactionGuard` on every `RequestScope`. The first
resolution in a scope builds the guard and registers its
`rollbackDangling()` on that scope's disposal; a scope that never
resolves it builds none. Every entry point takes its scopes from
`AppScope::createRequestScope()`, so each unit of work is covered:

- `Kernel`, for every HTTP request, an MCP message over HTTP included.
- `bin/kinetis`, for every command that has not declared
  `#[Command(bootstrap: false)]`. A bootstrap-free command runs no
  package bootstrap, so its scope carries no initializer and there is no
  bound connection to guard.
- `kinetis/mcp`'s `ScopedMessageHandler`, for every MCP message over
  stdio.
- `kinetis/queue`'s `QueueWorker`, for every popped job, and `SyncQueue`,
  for every `push()`. A job that begins a transaction and returns or
  throws without closing it does not leave that transaction open into
  whatever job the same connection serves next.

Request-scoped wiring a database package needs belongs in the bridge's
request-scope initializer, as the guard's does. Replacing the guard
means registering a later `onRequestScopeCreated()` initializer that
binds the replacement on the scope and registers its own disposal
callback there. A `TransactionGuard` bound on `AppScope` would be one
worker-lifetime guard shared by every unit of work, never a safe
override.

(database-reference-registration)=
## Registering connections in `bootstrap.php`

A registration in `bootstrap.php` wins over the bridge's default
binding, and the link it registers is the application's to close — see
[The default link's lifetime](#the-default-links-lifetime). Register the
default connection to set pool options in code, which win over
`DB_MAX_CONNECTIONS` and `DB_WARM_CONNECTIONS`, and a named connection
under an id of its own:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;

return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlLink::class, ConnectionFactory::fromConfig($config, poolOptions: [
        'maxConnections' => 12,
        'warmConnections' => 12,
    ]));

    $app->instance('db.reporting', ConnectionFactory::fromConfig($config, 'reporting'));
};
```

A named connection reads every key with its name inserted after `DB_`,
following {doc}`config`'s named-connection convention:

```{code-block} text
DB_REPORTING_CONNECTION=pgsql
DB_REPORTING_DRIVER=auto
DB_REPORTING_HOST=reporting.internal
DB_REPORTING_PASSWORD=secret
```

Only the default connection is injected by its contract type, dialect
or `SqlLink`. A named connection is retrieved by its id
(`$app->get('db.reporting')`) and passed explicitly to whatever runs on
it, such as `new Query($link)`. Entities on `reporting` need no
registration: the ORM wiring uses a `db.reporting` binding when there is
one, and otherwise builds the connection from these keys itself
({doc}`orm`'s "Entities on other connections").

(database-reference-standalone)=
## Without Kinetis

`kinetis/persistence`, `kinetis/query-builder` and `kinetis/orm` run in
any PHP process. The host then does what the bridge does in an
application: it builds each client once, gives every unit of work its
own `TransactionGuard` and `EntityManager`, and closes the clients when
the process stops using them.

```{code-block} sh
composer require kinetis/persistence
```

```{code-block} php
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\ConnectionOptions;
use Kinetis\Persistence\SqlConnectionFactory;

// Once per process.
$db = SqlConnectionFactory::create(new ConnectionDefinition(
    dialect: 'pgsql',   // or 'mysql'
    host: 'db.internal',
    database: 'shop',
    user: 'shop',
    password: $password,
    port: 5432,         // the dialect's own when omitted
    driver: 'auto',     // 'auto' (the default), 'native' or 'pdo'
    options: new ConnectionOptions(sslMode: 'verify-full', sslCa: '/etc/ssl/certs/db-ca.pem', maxConnections: 12),
    warmConnections: 0, // connections opened at construction
));
```

The definition rejects an unknown dialect or driver, a port outside
1–65535 and a negative warm count, and `ConnectionOptions` validates its
own fields, each with an `InvalidArgumentException` at construction.
Build one client per connection and keep it for the process's lifetime:
the native clients are connection pools. `close()` takes a client out of
service when the process is done with it, and every later call throws
`Exception\ConnectionException`.

### A guard per unit of work

```{code-block} php
use Kinetis\Persistence\TransactionGuard;

$guard = new TransactionGuard($logger);

try {
    $handler->handle($job, $guard);
} finally {
    $guard->rollbackDangling();
}
```

Calling `rollbackDangling()` from `finally` closes what the unit of work
left open whether it returned or threw. A guard is never shared between
units of work.

### Queries and entities

`new Query($db)` works on a client built this way exactly as in an
application ({doc}`query-builder`). For the ORM, build the metadata and
the factory once, and open and close a manager per unit of work:

```{code-block} php
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;

// Once per process.
$orm = OrmFactory::create($db, MetadataRegistry::fromClasses([Article::class]));

// Once per unit of work.
$entities = $orm->open();

try {
    $article = $entities->repository(Article::class)->findOrFail($id);
    $article->publish();
    $entities->flush();
} finally {
    $entities->close();
}
```

`Article` is the entity from {doc}`orm`. `OrmFactory::create()` takes the
client, never a transaction, and the factory holds no unit-of-work state,
so one serves the whole process. `close()` never flushes and leaves the
client open. `MetadataRegistry::toArray()` and `fromArray()` let a build
step export the metadata so a worker loads it without scanning a
directory, as the [package README](https://github.com/kinetis-dev/orm#readme)'s
"Metadata" shows. When a `flush()` or an `OrmFactory::transaction()` has
committed is covered in {ref}`orm-flush-outcomes`, and holds without
Kinetis unchanged.

(database-reference-instrumentation)=
## Instrumentation

Either `SqlConnectionFactory` method takes a
`Contract\SqlInstrumentation` as its second argument. Every client it
builds, and every transaction that client begins, reports five moments
through it: a statement dispatched (`queryDispatched()`, with `mysql` or
`postgresql` and the SQL text), sent to the server
(`queryServerStarted()`, again when a pooled driver retries on a fresh
connection), and reaped (`queryReaped()`, with the failure when there is
one); a transaction started, and ended (`transactionEnded()`, with
`commit`, `rollback` or `unknown` — see
{ref}`database-reference-transactions`). A started moment returns an
opaque token that its ended moment receives.

A transaction reports the three query moments for each statement run
through it, against the SQL the caller wrote rather than the text a
driver put on the wire, and reports them around the driver call alone —
so a statement its pre-flight refuses reports none of them, and one the
server answered is reaped before the transaction settles or hands its
connection back. `queryServerStarted()` follows `queryDispatched()`
immediately there, since a transaction's connection is already pinned
and nothing waits between the two. The `BEGIN`, `COMMIT` and `ROLLBACK`
a transaction sends are not query moments at all: the started/ended pair
is what reports the transaction's boundary.

Every moment runs inline, on the Fiber issuing the statement, inside the
driver's own call. An implementation must be synchronous — it never
suspends the Fiber — bounded in time, and free of blocking I/O; anything
it exports goes to separately owned, bounded infrastructure it hands the
data to. A client keeps its instrumentation for the client's whole
lifetime — the process's, under a persistent worker — so an
implementation holds no mutable request or unit-of-work state.
`queryDispatched()` receives the complete SQL text. Bound parameter
values are never passed, but the text can carry literals and is
sensitive: an implementation must not log or export it verbatim.

The client contains whatever the instrumentation throws. A started
moment that fails hands back `null`, the failure is reported once
through `error_log()` naming the moment and both classes but never the
exception's message, and the query result, transaction outcome and
connection release are exactly what they are without instrumentation. A
client built with none reports nothing. `kinetis/database-bridge`'s
clients report through Kinetis telemetry ({doc}`telemetry`).

(database-reference-statements)=
## Statements and arguments

Every driver returns fully-buffered results — part of the `SqlResult`
contract, so a caller stops iterating whenever it likes and nothing is
left to drain. Parameterized calls go through `execute()`: real
server-side binding on PostgreSQL (`pg_send_query_params`) and PDO, and
escaped client-side interpolation on native MySQL, whose async mode has
no bind step. That client pins the connection charset explicitly, so
escaping always runs against a known charset.

One call carries one statement. SQL producing more than one result set
throws `Exception\QueryException` rather than returning the first:
draining the rest would block the event loop on the async drivers, and
an unread result set left on a pooled connection fails whatever borrows
it next. The connection survives either way — `mysqli` discards its own
for the pool to replace, and the others drain what is left. PDO
PostgreSQL is the one case the rule cannot reach: libpq runs a
semicolon-separated string as a single command and reports only its last
result. Issue one `query()`/`execute()` per statement.

A later result set can carry the server's own error rather than rows —
what a stored procedure raising `SIGNAL` after a `SELECT` produces. It
reaches the caller as `Exception\QueryException` like any other server
error, and the span records the failure it is: nothing is built, and
nothing reported, until every result set has been read.

`COPY` is not supported on the native PostgreSQL driver: it puts the
connection into a streaming mode the driver has no protocol for, and the
server holds it there waiting for data that is never coming. The
connection is taken out of service and replaced by the pool, and the
caller gets an `Exception\ConnectionException` — the exchange was lost,
which is a different thing from a statement the server refused. Use a
server-side `COPY` — one that reads or writes a file the server itself
can reach — or ordinary statements.

### The pre-flight

Every parameterized call passes one pre-flight first — before the driver
opens an instrumentation span, asks its pool for a connection, opens one,
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

### Arguments

`execute()` takes exactly one argument per `?` placeholder, on every
driver: a call carrying more or fewer throws naming both counts. That is
a contract rather than an incidental check on the PDO drivers, whose
prepared statements are memoized
({ref}`database-reference-statement-cache`): a reused statement still
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
bound, so a refused call leaves no position bound. PostgreSQL adds one
rule of its own: a string holding a NUL byte is refused, since libpq
carries text parameters as C strings and the value would reach the
server truncated at that byte. MySQL takes one intact, which is what a
`VARBINARY`/`BLOB` column needs. The narrower set is the one all four
drivers agree on, so the same call is refused the same way whichever
driver `DB_DRIVER` picked. Format a `DateTimeInterface`, an enum or a
JSON payload at the call site, where the shape is your decision rather
than the driver's.

### Placeholders

Which `?` is a placeholder is decided by one dialect-aware scan of the
SQL text, shared by every driver — the native drivers substitute each
one they find, the PDO drivers count them for the argument check above.
That scan is `execute()`'s: `query()` takes complete SQL and Kinetis
reads none of it. The scan recognizes `'...'`/`"..."`/`` `...` ``
quoting, `--`/`#`/`/* */` comments, and PostgreSQL's
`$$...$$`/`$tag$...$tag$` dollar-quoted strings, so a `?` inside any of
those is data, never a slot. PostgreSQL's own jsonb
containment/existence operators (`?`, `?|`, `?&`) are lexically
identical to a placeholder at the position they appear — write
`??`, `??|`, `??&` in an `execute()` string to mean the literal operator
rather than a bind slot. The doubling is that scanner's escape rather
than SQL, and the scan is what removes it again. `query()` runs no scan,
so nothing removes a doubling there — and PDO runs a placeholder parser
of its own over whatever string it is handed. A literal `?` operator
belongs in an `execute()` call, where the rule holds on every driver.
A `$` that continues the identifier to its left opens nothing: `col$tag$`
is one PostgreSQL identifier, and a dollar-quoted literal following an
identifier or a keyword has to be separated from it (`col $tag$`), the
same way the server lexes it. A dollar-quote tag is spelled with
PostgreSQL's own unquoted-identifier bytes, non-ASCII included, so
`$é$ ... $é$` quotes its contents exactly as `$body$ ... $body$` does.

Two comment rules match real MySQL rather than a generic reading of the
syntax. `--` only opens a comment against MySQL when the second dash is
followed by whitespace, a control character, or the end of the string —
`5--?` is `5 - - ?`, not a comment (PostgreSQL has no such condition; a
bare `--` always opens one there). MySQL/MariaDB's *executable* comments
(`/*! ... */`, `/*M! ... */`) are scanned as ordinary comments: their
text is copied through verbatim for the connected server to interpret on
its own, and a `?` inside one is data rather than a bind slot. A query
meaning one to be bound fails loudly — on the argument count, or on the
server that executes the comment — rather than binding something
silently. Write the bound value outside the comment.

(database-reference-pools)=
## Pools and sessions

The native clients are connection pools: connections open lazily up to
`maxConnections`, are reused across requests under a persistent worker,
and are discarded and replaced when they die. A connection being opened
counts against the cap for the whole attempt, not only once it is
finished, so a burst of callers arriving at an empty PostgreSQL pool
opens `maxConnections` connections between them rather than one each.

A definition's `warmConnections` opens that many connections at
construction instead of on first use, clamped to `maxConnections`.
Through the bridge it comes from `DB_WARM_CONNECTIONS` or
`$poolOptions['warmConnections']`, and `maxConnections` from
`DB_MAX_CONNECTIONS` or `$poolOptions['maxConnections']`, an explicit
pool option winning over its key. Every driver also exposes the
underlying call, `warmUp(?int $connections = null)`, where `null` warms
the whole pool. Under a persistent worker, warming is load-bearing for
the native MySQL driver: see {doc}`performance-tuning`'s "mysqli's poll
limit".

The `auto` split is measured, not aesthetic: under boot-and-die PHP-FPM,
per-request connection handshakes and per-query client CPU dominate, and
an async client's I/O overlap cannot pay for them (sub-millisecond
queries leave nothing to overlap); under a persistent worker, connections
amortize across requests and native async fan-out keeps its benefits at
native protocol cost.

(database-reference-pool-sizing)=
### Sizing pools under persistent workers

`DB_MAX_CONNECTIONS` defaults to 8, and callers beyond the cap wait
inside the pool for a free connection. It does not apply to PDO, which
holds one connection per process.

Each FrankenPHP worker thread and each RoadRunner worker process runs the
bootstrap chain and builds its own pool, so the ceiling the database sees
is:

```{code-block} text
worker count × DB_MAX_CONNECTIONS
```

FrankenPHP's `worker.num` or RoadRunner's `pool.num_workers` is the
worker count: 128 worker threads with `DB_MAX_CONNECTIONS=256` can open
32,768 connections. Keep that product comfortably below the database's
own `max_connections`. A request whose `concurrently()` fan-out exceeds
its worker's pool queues inside the pool, which adds latency to that one
request, a far softer failure than connections the database rejects.
{doc}`runtime-adapters` covers sizing the workers.

### A connection the server closed

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
rolling back on a session that is gone. The next query's dispatch does
fail immediately, and *that* is retried transparently on a fresh
connection.

(database-reference-pdo-sessions)=
### PDO sessions

A PDO client holds one connection at a time and opens it lazily. A
session it can carry no more work on — abandoned by a transaction, ended
by a terminal MySQL lock failure (1205/1213), left in a result state
that could not be cleared, or failed by a statement or
`beginTransaction()` on the client itself — goes back to the server, and
the next call opens a fresh one. PDO reports a session the server
terminated and an ordinary statement error the same way, so every such
failure gives the session up. The failure still reaches the caller as
`Exception\QueryException`, and the failed statement is never sent
again. An error carried by a later MySQL result set is read to the end
of the result and keeps the session. `close()` is the separate, final ending: it takes the
client itself out of service, and every later call throws
`Exception\ConnectionException`. The two differ wherever a process
outlives one session, which under `auto` is every process that is not a
persistent worker — a `queue:work` CLI worker included.

(database-reference-single-session)=
### Single-session clients

`SqlConnectionFactory::singleSession()` — and
`ConnectionFactory::singleSession()` over a connection's `DB_*` keys —
builds the other policy: a PDO client, whatever the definition's driver
says, pinned to the session it opens, closing rather than reconnecting if
that session is discarded. A failed statement or `beginTransaction()`
does not discard it: the client keeps its session and the caller gets
the failure. It is for work that lives in the session
itself — a session-scoped advisory lock, a temporary table — where a
replacement is a different session holding none of it, and running on
one quietly would be worse than stopping. `kinetis/migrations`' commands
run each connection's migrations on one, closed before the next
connection's opens (see {doc}`migrations`).

(database-reference-statement-cache)=
### Prepared statements on PDO

The PDO drivers run with *native* (non-emulated) prepares, where every
`prepare()` is its own server round trip — so `execute()` memoizes
prepared statements per SQL string for the connection's lifetime. A loop
issuing the same parameterized statement N times costs N+1 round trips
instead of 2N. The cache holds at most 256 statements (workloads that
interpolate values into their SQL text instead of binding reset it on
overflow rather than growing it forever) and goes with the connection it
was built on, so a replacement connection starts an empty one. A
transaction runs on the client's own connection, so it shares that one
cache rather than re-preparing what it already holds. Server-side
prepared statements are scoped to a database connection, which is why
{doc}`persistence` warns against multiplexing proxies.

(database-reference-native-io)=
## What blocks on the native drivers

**MySQL.** mysqli cannot expose its socket to the event loop, so while
its queries are in flight the client polls with a short (1 ms) blocking
window per loop turn — indistinguishable from a blocking wait when the
request's only outstanding work is the database, and at worst a 1 ms
delay per turn for anything else scheduled concurrently. Opening a
connection blocks outright: mysqli has no async connect primitive, so a
connection opened under load stalls the worker thread for a TCP connect,
a TLS handshake and an auth exchange.

**PostgreSQL.** ext-pgsql exposes its socket (`pg_socket()`), and the
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
long as it takes.

(database-reference-options)=
## Connection options

Each option is a connection-scoped `DB_*` key ({doc}`config` lists them)
that the selected driver translates. A driver refuses an option it cannot
honor when the client is built, naming the option and the driver, so a
configuration never means something different on another runtime:

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

A TLS connection that verifies the server, with a client certificate for
servers that authenticate the client too:

```{code-block} text
DB_SSLMODE=verify-full
DB_SSL_CA=/etc/ssl/certs/db-ca.pem
DB_SSL_CERT=/etc/ssl/certs/db-client.pem
DB_SSL_KEY=/etc/ssl/private/db-client.key
```

- `DB_SSLMODE` is `disable`, `require` (encrypt without verifying the
  server), `verify-ca` or `verify-full`. `allow` and `prefer` are
  PostgreSQL only, since MySQL clients have no opportunistic TLS.
- On MySQL, a verify mode needs `DB_SSL_CA`, `DB_SSL_CA` needs a verify
  mode, and `verify-ca` verifies the host name too, as `verify-full`
  does.
- `DB_SSL_CERT` and `DB_SSL_KEY` are set together, with a `DB_SSLMODE`
  other than `disable`. Every driver presents them where the server
  requires a client certificate, as MySQL's `REQUIRE X509` and
  PostgreSQL's `clientcert=verify-ca` do.
- MySQL connections use `utf8mb4` unless `DB_CHARSET` says otherwise,
  never the server's default.

Each of these rules fails when the client is built, not on the first
query. `ConnectionOptions` also refuses a charset or collation outside
`[A-Za-z0-9_]`, since both reach a driver as SQL or an API call; an empty
CA, certificate or key path; a connect timeout below one second; and a
`maxConnections` below 1.

```{warning}
PostgreSQL refuses a client key readable beyond its owner: make it
`0600`, or `0640` when owned by root. The refusal comes from libpq at
connect time, so it surfaces as a connection failure rather than a
configuration error, and a deployment that works against MySQL can fail
against PostgreSQL for this reason alone.
```

The MySQL PDO driver sets `Pdo\Mysql::ATTR_*`, not the equivalent
`PDO::MYSQL_ATTR_*` constants deprecated as of PHP 8.5: identical
underlying values, without the deprecation notice.

The PDO drivers refuse a `;` or a NUL byte in any value they put in a
DSN — host, database, and the PostgreSQL options. PDO's DSN grammar has
no quoting for either: pdo_mysql splits its DSN on `;`, pdo_pgsql turns
every `;` into a space for libpq, and a NUL ends the C string, so such a
value would be read as further connection parameters.

(database-reference-transactions)=
## Transactions

`beginTransaction()` pins one connection and returns a transaction with
the same `query()`/`execute()` surface as the client. Its static type
keeps the link's dialect: a `MysqlLink` begins a `MysqlTransaction`, a
`PostgresLink` a `PostgresTransaction`, and code typed against the
generic `SqlLink` gets a `SqlTransaction`. `TransactionGuard` keeps that
type through its own `beginTransaction()` and the `transaction()`
callback, so {doc}`query-builder`'s `new Query($tx)` takes the
transaction as it is; `Query` detects the dialect from the concrete
object rather than the callback's declared parameter type. Every
statement belonging to the transaction goes through that object: a
client-level call while it is open is refused rather than served, on
every driver.

A PDO client holds one connection, so the transaction owns the client
for as long as it runs — `query()`, `execute()` and a second
`beginTransaction()` on the client throw `Exception\TransactionException`
until it ends. An async client pools connections, so the refusal is
scoped to the Fiber holding the transaction: another Fiber keeps its own
connection and can open a transaction of its own, while the holder's
client-level call would land on a different connection, in autocommit,
outside the transaction it believes it is in.

A transaction also belongs to the Fiber that began it. `query()`,
`execute()`, `commit()` and `rollback()` from any other Fiber throw: the
pinned connection carries one statement at a time, so a second Fiber
dispatching on it would corrupt both. Give that Fiber its own
transaction instead.

`rollback()` on a transaction that has already ended is a no-op, so
`catch (Throwable) { $tx->rollback(); throw $e; }` needs no `isActive()`
check first. `commit()` throws there. A `COMMIT` or `ROLLBACK` the server
refuses throws and discards the connection rather than handing it back:
what is left on it is a transaction of unknown outcome.

The span's outcome attribute says only what the server confirmed:
`commit` for a `COMMIT` it acknowledged, `rollback` for a `ROLLBACK` it
acknowledged, and `unknown` for everything else — a lost connection, a
discarded connection, a finish nothing answered, a transaction the
server ended on its own. A transaction that never sent either statement
is `unknown` too: the work is discarded with the session, which is not
the same as a rollback the server reported.

`isActive()` stays true until the connection has been handed back and
the span closed, which is a moment later than the last statement being
accepted: while a `COMMIT` is on the wire the transaction refuses further
statements but still owns its connection. That window is what `close()` —
and so `TransactionGuard` at the end of a unit of work — has to be able
to reach. Closing there takes the connection out from under the finish,
the owning Fiber comes back with an `Exception\ConnectionException`, and
the outcome is recorded once, as `unknown`.

### What the server does underneath the object

A transaction can end where the server is while the object still
believes it is open, and the statements that follow would then run in
autocommit. Each driver settles it from what it can see locally, without
a probe round trip, after every statement — succeeded or failed — and
before the next one:

- **PostgreSQL, both drivers** — libpq tracks the transaction status the
  server sent with its last message, so an implicitly ended transaction
  is visible directly. A failed statement aborts the whole transaction
  there rather than ending it: the server answers a later `COMMIT` with a
  rollback and no error, so Kinetis ends it as the rollback it is and
  throws `Exception\TransactionException` rather than reporting a commit
  that did not happen.
- **PDO MySQL** — `PDO::inTransaction()` reads the same status flag,
  which is what makes MySQL's implicit commit on DDL (see
  {doc}`migrations`) visible.
- **Native mysqli** — mysqli exposes no transaction-status accessor, so
  an implicit commit cannot be seen at all: keep DDL and raw `COMMIT`,
  `ROLLBACK` and `SAVEPOINT` statements out of a transaction on this
  driver.

Where a driver can see it, `isActive()` both reports the transaction gone
and settles it — connection handed back, nothing left to close.

### MySQL lock failures

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

### Closing from another Fiber

`close()` is the lifecycle escape hatch — what `TransactionGuard` runs at
the end of a unit of work — and is callable from any Fiber. On the owning
Fiber it is an ordinary rollback. From another it ends the transaction
and takes the connection out of service rather than sending a `ROLLBACK`
down one the owner may be using: the pool replaces it, and the server
rolls the work back with the session. A statement still in flight when
that happens is settled where it stands, with an
`Exception\ConnectionException` saying the connection went before the
server acknowledged anything — the statement may well have run. Closing
a PDO client does the same to the transaction holding it, since both run
on the client's one connection.

### A transaction nothing ends

Calling `beginTransaction()` on a link makes ending the transaction the
caller's own job, and an exception path that drops the object without
reaching `commit()`, `rollback()` or `close()` leaves nobody holding it: a
driver keeps a transaction's owner Fiber, never the transaction. The last
reference going away is where such a transaction ends. Its connection is
discarded, the Fiber's client-level ownership goes with it, and the span
closes with the outcome `unknown`.

Nothing goes on the wire there. That cleanup runs in a destructor, which
cannot suspend and so cannot wait for an answer; a `ROLLBACK` dispatched
with nobody to read the reply would sit on a connection about to serve
someone else. The server rolls the work back as the session goes, which
is not a `ROLLBACK` it acknowledged — and the span says so rather than
claiming one.

What that costs is the connection: an async client's pool opens a
replacement, and a PDO client, holding one at a time, opens a fresh one
on its next call. `TransactionGuard::transaction()` costs neither — it
ends the transaction on every path out of the work, so the connection
goes back to the pool with the outcome the server confirmed. Use the
guard; the discard is a safety net for a connection, not a way to end a
transaction.

(database-reference-guard)=
## `TransactionGuard`

Connection pooling is the drivers' own job. What no driver can know about
is where a unit of work — a request, a job, a command — ends: if
application code begins a transaction and something throws before it is
explicitly committed or rolled back, nothing commits or rolls it back,
and it holds its connection — and the locks on it — for as long as
anything still references it.

`Kinetis\Persistence\TransactionGuard` is the safety net for exactly
this. One guard belongs to one unit of work and tracks every transaction
it starts. `transaction()` commits on success, rolls back on any throw,
and always closes before returning, so it leaves nothing for the safety
net to find. `rollbackDangling()`, called when the unit ends, closes a
transaction begun through the guard's own `beginTransaction()`, held open
across calls, that never reached `commit()` or `rollback()`.

When it does find something to close, it logs a warning through the
guard's logger (see {doc}`logging`) — a genuine anomaly signal, since it
means a transaction was left open somewhere it should not have been.

What it finds is what it started: `$guard->beginTransaction($link)` and
`transaction()`, the two calls that put a transaction on its tracked
list. One begun straight off the link is not tracked here or anywhere
else, and ends by being dropped — connection discarded, outcome
`unknown`. Route a transaction held open across several calls through
the guard, and disposal rolls it back on the wire and hands the
connection back instead.

Both methods work identically for MySQL and PostgreSQL: all drivers
implement the same `Contract\SqlLink`/`SqlTransaction` abstraction, so
`TransactionGuard` never needs to know which one it is talking to.

### What happens when cleanup itself fails

Inspecting a transaction (`isActive()`) or closing one (`close()`) is
itself a network call to a driver — it can fail, and this class is
designed around that possibility rather than assuming it away.

**`rollbackDangling()` is best-effort across the complete tracked set,
not fail-fast.** One transaction's `isActive()` or `close()` throwing
never prevents the rest from being attempted — a cleanup fault on one
connection must not leak transactions and locks on every other tracked
one. Tracking is cleared up front, before any transaction is touched, so
a transaction this call already attempted — successfully or not — is
never retried by a later call. Each failure is logged individually
(`error`, not `warning`), and the first of them is rethrown once every
tracked transaction has been attempted. Under the bridge it propagates
from `RequestScope::dispose()`, which runs every dispose callback to
completion regardless of one throwing (see {doc}`container`) and
rethrows only once all of them have finished.

It closes rather than rolls back because the end-of-unit cleanup runs in
the host's own context while the Fiber that leaked the transaction may be
parked: `close()` from a foreign Fiber ends the transaction and discards
its connection instead of putting a concurrent `ROLLBACK` on it.

**`transaction()` never lets a rollback failure erase the failure that
triggered cleanup.** If your callback (or `commit()`) throws, and the
resulting rollback attempt *also* throws, the rollback failure is logged
and the original exception — the one your code actually threw — is what
propagates, unchanged. The transaction is untracked either way, whether
the rollback attempt succeeded or failed: `transaction()` only ever makes
one cleanup attempt of its own, and leaving a failed one tracked would
defer a second attempt to `rollbackDangling()` at the end of the unit of
work — from a `finally` block, where a second failure there would
silently replace the exception already propagating from `transaction()`,
undoing the same guarantee one level up.

**None of this depends on the logger being healthy.**
`Psr\Log\LoggerInterface` gives no no-throw guarantee, and a failing log
handler — a broken remote sink, a full disk — is a real production
scenario. Every log call this class makes is wrapped so an exception from
the logger itself is discarded: it can never be misclassified as a
rollback failure, never prevent a later tracked transaction from being
attempted, and never replace an already-propagating exception the way an
unprotected logger call could.

## See also

- {doc}`persistence` — connecting, transactions and driver selection in
  an application.
- {doc}`appendix-query-builder` — the query builder's complete contract.
- {doc}`container` — request-scope initializers and disposal.
- {doc}`logging` — the logger `rollbackDangling()` reports through.
