# Database

A Kinetis application reaches MySQL, MariaDB and PostgreSQL through
`kinetis/database-bridge`: configure `DB_*`, inject the connection, and
query it directly, through {doc}`query-builder`, or through {doc}`orm`.
{doc}`migrations` manages the schema, and {doc}`redis` covers Redis and
the PSR-16 cache.

MariaDB works everywhere this page says MySQL. The one place a specific
server version matters is `kinetis/queue-sql`; see {doc}`queue-sql`.

## Connect

```{code-block} sh
composer require kinetis/database-bridge
```

The bridge brings in `kinetis/persistence`, which holds the drivers.
Each driver needs its PHP extension, which Composer suggests rather than
requires: `ext-pdo_mysql` or `ext-pdo_pgsql` wherever `DB_DRIVER=auto`
selects PDO, and `ext-mysqli`, or `ext-pgsql` with `ext-sockets`, under a
persistent worker (see [Driver selection](#driver-selection)).

Configure the default connection:

```{code-block} text
:caption: .env

DB_CONNECTION=mysql
DB_HOST=db.internal
DB_PORT=3306
DB_NAME=shop
DB_USER=shop
DB_PASSWORD=secret
```

`DB_CONNECTION` is `mysql` or `pgsql`, and `DB_PASSWORD` is required once
it is set. {doc}`config` lists every `DB_*` key with its default. Without
`DB_CONNECTION` the bridge builds no connection, and the application
boots without a database.

With it, the bridge's package bootstrap builds the client before
`bootstrap.php` runs and registers it on `AppScope` under its dialect
contract: `Kinetis\Persistence\Contract\MysqlLink` for `mysql`,
`Contract\PostgresLink` for `pgsql`. Connections open on first use, or at
boot with `DB_WARM_CONNECTIONS`. Inject the contract:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Persistence\Contract\MysqlLink;

final readonly class OrderController
{
    public function __construct(private MysqlLink $db) {}

    #[Get('/customers/{customerId}/orders')]
    public function index(int $customerId): array
    {
        return iterator_to_array($this->db->execute(
            'SELECT id, total FROM orders WHERE customer_id = ?',
            [$customerId],
        ));
    }
}
```

Every request resolves the same registered client. Under a persistent
worker that client is the worker's connection pool, and the bridge
closes that exact client when the application scope is disposed — the
worker shutting down, the command finishing, the test ending. A link
your own `bootstrap.php` binds instead is yours: the bridge holds the
one it built, so replacing the binding never leaves the bridge closing
something it does not own, and never closes yours for you. See
{ref}`the disposal contract <container-app-disposal>`, and
{ref}`database-reference-registration` for replacing the binding.

- `execute($sql, $params)` binds exactly one argument per `?`. Each
  argument is `null`, a `bool`, an `int`, a finite `float` or a `string`:
  format dates, enums and JSON before passing them. Pass user input only
  this way.
- `query($sql)` runs SQL that takes no arguments.
- One call runs one statement, and its result is buffered whole: iterate
  it, or call `fetchRow()`, `getRowCount()` or `getLastInsertId()`.
- On PostgreSQL, `execute()` reads a bare `?` as a placeholder: write the
  jsonb operators `?`, `?|` and `?&` as `??`, `??|` and `??&` there. The
  doubling is `execute()`'s own escape and belongs nowhere else.

{ref}`database-reference-statements` holds the complete statement and
argument contract.

## Choose how to query

| You need | Use |
|---|---|
| A statement you write yourself | `execute()` on the injected link |
| Composed filters, joins, pagination, row DTOs, upserts or row locks | {doc}`query-builder`: `new Query($db)` per statement |
| Entities with an identity map, change tracking and a transactional flush | {doc}`orm`: inject `EntityManager` |
| Versioned schema changes | {doc}`migrations` |

The bridge installs none of the three packages; require the ones you
use.

## Transactions

Inject `TransactionGuard` next to the link and run the work in
`transaction()`:

```{code-block} php
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\TransactionGuard;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
        private TransactionGuard $transactions,
    ) {}

    #[Post('/orders')]
    public function store(#[Body] CreateOrder $order): array
    {
        $this->transactions->transaction($this->db, static function (SqlTransaction $tx) use ($order): void {
            $tx->execute('INSERT INTO orders (customer_id, sku) VALUES (?, ?)', [$order->customerId, $order->sku]);
            $tx->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', [$order->sku]);
        });

        return ['status' => 'created'];
    }
}
```

`CreateOrder` is an application request DTO (see
{doc}`routing-validation`).

`transaction()` begins a transaction on one connection and passes it to
the callback. When the callback returns, it commits and returns the
callback's result; when the callback throws, it rolls back and rethrows.
The work is committed only once `transaction()` has returned.

- **Run every statement on `$tx`**, `new Query($tx)` included. A
  statement the Fiber holding the transaction sends on `$db` is refused
  with `Exception\TransactionException` instead of running outside the
  transaction.
- **Type the callback `SqlTransaction`.** A `MysqlLink` still passes a
  `MysqlTransaction`, and the query builder reads the dialect from that
  object.
- **One guard per unit of work.** Each HTTP request, queued job, MCP
  message and bootstrapped command resolves its own `TransactionGuard`; a
  `#[Command(bootstrap: false)]` command runs no package bootstrap, so it
  gets neither a guard nor a connection. Never keep a guard in an
  `AppScope` service.
- **Hold a transaction open across calls only through the guard.**
  `$this->transactions->beginTransaction($this->db)` returns a
  transaction you commit or roll back yourself. If the unit of work ends
  with it still open, the guard closes it when the scope is disposed and
  logs a warning. A transaction begun directly on the link is not
  tracked, and one nothing ends is discarded with its connection.

{ref}`database-reference-transactions` and
{ref}`database-reference-guard` hold the full transaction and cleanup
contract.

### When a write's outcome is unknown

A statement that reached the server may have run even when the call
fails, and the drivers never send it again:

- **A lost connection.** A pooled connection the server closed while it
  sat idle (`wait_timeout`, a `KILL`, a network drop) fails the first
  statement sent on it after that statement was dispatched, with
  `Exception\ConnectionException`, or `Exception\QueryException` where the
  server answered. The next statement opens a fresh connection.
- **A failed `COMMIT`.** A `COMMIT` that throws leaves the transaction's
  outcome unknown, and its connection is discarded.

```{warning}
Treat either failure as unknown, not as a rollback. Retry a read or an
idempotent write; before redoing any other write, establish what the
database holds. Replaying a non-idempotent statement as though it failed
can apply it twice.
```

A deadlock (MySQL error 1213) or a lock-wait timeout (1205) ends the
whole transaction on both MySQL drivers without a `COMMIT`, and discards
its connection. The error number is the `QueryException`'s code, and
retrying the whole `transaction()` is the documented response to a
deadlock. On PostgreSQL a failed statement aborts the transaction: a
callback that catches the failure and returns gets
`Exception\TransactionException` from the commit, not a commit.

### Unique violations

Let a unique key settle a race instead of reading before the write, since
two requests can both find a value free. Catch the violation outside
`transaction()`, which has already rolled the transaction back by then.
`QueryException::isUniqueViolation()` is true for PostgreSQL's SQLSTATE
`23505` and the MySQL family's error 1062, on every driver, and
`getSqlState()` returns the SQLSTATE the server or driver reported, or
`null`. Classifying a failure changes nothing about it: the statement is
not retried and the error is not suppressed. The
{ref}`repository cookbook <query-builder-cookbook>` shows the pattern.

After an unknown write outcome, a retry can meet its own earlier row. If
it raises a unique violation, reread through the injected link after the
outer transaction has rolled back and compare the stored normalized
request with the retry before deciding idempotent replay versus genuine
conflict.

## Driver selection

`DB_DRIVER=auto`, the default, picks the driver from the runtime, and
application code is the same under either:

- **FrankenPHP worker mode and RoadRunner** get the native `ext-mysqli`
  or `ext-pgsql` client. A query suspends only its own Fiber, so the
  worker keeps serving other requests, and `concurrently()` runs queries
  side by side on pooled connections.
- **PHP-FPM, CLI commands, queue workers, PHPUnit and AWS Lambda** get
  one blocking PDO connection per process. `concurrently()` returns the
  same results, with its queries run one after another.

Set the key yourself in two cases:

- **AWS Lambda with the native extension.** Set `DB_DRIVER=native` only
  when the deployment provides the extension (PostgreSQL's `ext-pgsql`
  comes from a layer the deployment provisions) and sizes
  `DB_MAX_CONNECTIONS` per execution environment, since the pool
  multiplies with the function's concurrency.
- **Tests that observe non-blocking I/O.** Return
  `'DB_DRIVER' => 'native'` from `configOverrides()` (see {doc}`testing`).

Don't create `PDO`, `mysqli` or `pg_connect()` handles yourself. Under a
persistent worker a hand-rolled blocking call stalls every request on
that worker; the injected link is the one that waits without blocking.

### Deployment checks

```{important}
- **Native MySQL.** mysqli has no asynchronous connect, so opening a
  connection blocks the worker. Open the pool at boot with
  `DB_WARM_CONNECTIONS` equal to `DB_MAX_CONNECTIONS`, and set
  `DB_CONNECT_TIMEOUT` so an unreachable server bounds the stall (see
  {doc}`performance-tuning`).
- **Native PostgreSQL.** libpq resolves the host name synchronously.
  Point `DB_HOST` at an IP address, or at a name the platform answers from
  cache.
- **PgBouncer and similar proxies.** The PDO drivers use server-side
  prepared statements, which a proxy multiplexing connections in
  transaction pooling mode breaks. Use session pooling, or a proxy
  version that tracks prepared statements itself.
```

{ref}`database-reference-native-io` describes what each native driver
waits on.

## Sizing `maxConnections` under worker mode

Each FrankenPHP worker thread and each RoadRunner worker process builds
its own pool of up to `DB_MAX_CONNECTIONS` connections (default 8), so
keep the worker count times `DB_MAX_CONNECTIONS` below the database's
`max_connections`. PDO holds one connection per process.
{ref}`database-reference-pool-sizing` works through the arithmetic, and
{doc}`performance-tuning` covers tuning under load.

## Options and more connections

TLS, charset, timeouts, compression and pool sizes are
connection-scoped `DB_*` keys, listed in {doc}`config`. A driver refuses
an option it cannot honor when the client is built, naming the option and
the driver; {ref}`database-reference-options` gives the per-driver matrix
and the TLS rules. To set pool options in code or add a named
connection, see {ref}`database-reference-registration`.

## See also

- {doc}`appendix-database` — the full driver, transaction and
  `TransactionGuard` contract.
- {doc}`query-builder` and {doc}`orm` — querying on the injected link.
- {doc}`migrations` — schema changes over the same `DB_*` keys.
- {doc}`concurrency` — `concurrently()`, and the blocking calls to
  replace in a persistent worker.
- {doc}`telemetry` — a span per query and per transaction from the
  clients the bridge builds.
