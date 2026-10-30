# Queue (SQL)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-sql
```
````

Adds MySQL or Postgres as a backend for {doc}`queue`, storing jobs in a
database you already run. Switching to it changes configuration, not
application code.

```{code-block} text
QUEUE_CONNECTION=sql
DB_CONNECTION=mysql   # or "pgsql"
DB_HOST=127.0.0.1
DB_NAME=app
DB_USER=app
DB_PASSWORD=secret
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

`pop()` reserves a job with `SELECT ... FOR UPDATE SKIP LOCKED`, so the
server must be **MySQL 8.0+, MariaDB 10.6+ or Postgres 9.5+**. An older
server rejects the clause, and `pop()` fails.

## Configuring

The `DB_*` keys are the ones `kinetis/database-bridge` reads (see
{doc}`persistence`). `QUEUE_VISIBILITY_TIMEOUT_SECONDS`, described below,
is the one key this package adds.

## Create the table

`kinetis/queue-sql` ships one {doc}`migrations` stub per dialect:

```{code-block} text
vendor/kinetis/queue-sql/resources/migrations/create_kinetis_queue_jobs_table.mysql.php.stub
vendor/kinetis/queue-sql/resources/migrations/create_kinetis_queue_jobs_table.pgsql.php.stub
```

Copy the one matching your database into your `migrations/` directory
with a timestamp prefix, then run `vendor/bin/kinetis migrate`.
[SQL mechanisms](appendix-queue.md#sql) describes the columns the backend
runs on.

## Enqueueing inside a transaction

{doc}`queue`'s `QueueInterface::push()` is not enlisted in a transaction
the caller has open: it runs its `INSERT` on the queue's own connection,
so the job is enqueued whether or not the surrounding transaction
commits. `Kinetis\QueueSql\SqlQueue::pushOn()` puts the row on a
transaction you already hold, so the job and the writes it belongs to
land together:

```{code-block} php
use Kinetis\Persistence\Contract\SqlTransaction;

$this->transactions->transaction($this->db, function (SqlTransaction $tx) use ($orderId): void {
    $tx->execute('UPDATE orders SET status = ? WHERE id = ?', ['paid', $orderId]);

    $this->queue->pushOn($tx, new SendReceipt($orderId));
});
```

The row becomes visible and durable only if that transaction commits. A
throw before the commit rolls it back with the caller's other work, and a
`COMMIT` that fails leaves the outcome unknown — see {doc}`persistence`'s
"When a write's outcome is unknown". Push telemetry closes when the
`INSERT` statement completes, so the span reports the enqueue statement
rather than the later commit.

`pushOn()` runs that one statement and nothing else: it never commits,
rolls back, nests a transaction, or falls back to the connection the
queue was built with. Addressing the database that holds
`kinetis_queue_jobs` is therefore the caller's job —
the queue's connection name picks the connection behind `push()` and
does not redirect a transaction you supply.

The signature belongs to this package, not to `QueueInterface`, so a
caller needs the `SqlQueue` itself rather than the `QueueInterface` the
container binds. `SqlQueueFactory::fromConfig()` returns that class.
Build it once and register that one object under both ids:

```{code-block} php
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueSql\SqlQueue;
use Kinetis\QueueSql\SqlQueueFactory;

$queue = SqlQueueFactory::fromConfig($config);

$app->instance(SqlQueue::class, $queue);
$app->instance(QueueInterface::class, $queue);
$app->onDispose($queue->dispose(...));
```

Ordinary `QueueInterface` consumers and `pushOn()` callers then share
one backend instance and its one connection pool. Binding only
`SqlQueue::class` leaves the default `QueueInterface` binding in place,
and it builds a second `SqlQueue` with a pool of its own.

The factory opened that connection, so the queue owns it and the
`onDispose()` line is what closes it when the worker ends — the same
registration the default `QueueInterface` binding makes for the backend
it builds. See {doc}`appendix-queue`'s "Connection ownership", which
also covers a `SqlQueue` constructed directly around a link the
application already has.

{doc}`appendix-queue`'s "Multiple backends" shows the same registration
where several backends run side by side. `pushOn()` takes any
{doc}`persistence` `SqlTransaction`. An {doc}`orm` transaction session
passes its `SessionTransaction` as the callback's second argument —
[Enqueueing a job with the
session](orm.md#enqueueing-a-job-with-the-session) shows it, and what a
job can carry from entities the session inserts.

## Handing off to another backend atomically

`pushOn()` is atomic only for a job that runs from the SQL queue. A
transactional outbox for work that belongs on Redis, SQS or RabbitMQ, or
that is a broadcast, uses a *relay job*: inside the transaction,
`pushOn()` a SQL job whose handler performs the step no transaction can
cover. The SQL row commits or rolls back with the business write; the SQL
queue's retries then carry the hand-off through.

```{code-block} php
use Kinetis\Queue\Job;
use Kinetis\Queue\QueueInterface;

final readonly class ForwardInvoice implements Job
{
    public function __construct(public string $invoiceId) {}

    public function handle(QueueInterface $queue): void
    {
        $queue->push(new GenerateInvoicePdf($this->invoiceId));
    }
}
```

```{code-block} php
$this->transactions->transaction($this->db, function (SqlTransaction $tx) use ($invoiceId): void {
    $tx->execute('UPDATE invoices SET status = ? WHERE id = ?', ['issued', $invoiceId]);

    $this->queue->pushOn($tx, new ForwardInvoice($invoiceId), maxAttempts: 10);
});
```

`handle()` resolves `QueueInterface` from the container, so the relay
reaches Redis, SQS or RabbitMQ only if that is the bound backend. Do not
register the SQL queue under `QueueInterface::class` here, as the section
above does: the relay would push back into the SQL queue. Build it on a
named connection and register it under `SqlQueue::class` alone;
`QueueInterface` stays on the downstream backend, the behavior "Binding
only `SqlQueue::class` leaves the default `QueueInterface` binding in
place" describes above:

```{code-block} text
QUEUE_CONNECTION=redis
QUEUE_LEDGER_CONNECTION=sql
DB_LEDGER_CONNECTION=pgsql
DB_LEDGER_HOST=10.0.0.6
```

```{code-block} php
$queue = SqlQueueFactory::fromConfig($config, 'ledger');

$app->instance(SqlQueue::class, $queue);
$app->onDispose($queue->dispose(...));
```

{doc}`appendix-queue`'s "Multiple backends" covers the keys and
`QUEUE_CONNECTION_NAME`. A SQL queue worker must run, naming that
connection: `vendor/bin/kinetis queue:work --connection=ledger`.

- **Set a retry cap.** `QUEUE_MAX_ATTEMPTS` defaults to `0`, so a relay
  job that fails once, because the downstream backend is down, is given
  up on, and the default `NullLogger` records nothing. Pass `maxAttempts`
  to `pushOn()`, and make the final failure visible with a durable logger
  or a `JobFailedPermanently` listener — see {doc}`queue`'s
  [Retries and failures](queue.md#retries-and-failures).
- **Use one database.** The transaction must address the database that
  holds `kinetis_queue_jobs`. With the SQL queue on a named connection,
  the writes belong on that same database: an ORM entity declares it with
  `#[Entity(connection: 'ledger')]` and runs through
  `OrmFactoryRegistry::factory('ledger')->transaction()` ({doc}`orm`'s
  "Entities on other connections"). A session on `default` cannot enlist a
  queue on another database.
- **Make the downstream step idempotent.** Delivery stays at least once
  across the hop: the relay can run again, including after the downstream
  `push()` had an unknown outcome, so the downstream job may be enqueued
  twice. Idempotency belongs to that job — see {doc}`queue`'s
  [A job can run more than once](queue.md#a-job-can-run-more-than-once).
  A relayed broadcast arrives at least one SQL poll interval after the
  commit and can fan out twice. Many applications prefer a direct
  broadcast after the commit and accept losing the occasional real-time
  hint.
- **Do not re-push after an unknown `COMMIT`.** The queue row shares the
  business write's fate, which is what the pattern buys. Establish what
  the database holds first — see {doc}`persistence`'s
  [When a write's outcome is unknown](persistence.md#when-a-writes-outcome-is-unknown).
  A second `pushOn()` after a commit that did land enqueues a second relay.

## Visibility timeout

`QUEUE_VISIBILITY_TIMEOUT_SECONDS` (default `300`, at least `1`) is how
long a reserved job belongs to its worker. When a worker dies before
settling a job, the job becomes poppable again once its reservation is
older than this, with its attempt count increased. Constructing
`SqlQueue` directly takes the same value as its `visibilityTimeoutSeconds`
argument.

`queue:work` renews the reservation automatically while the job runs, at
half this window, so the setting sizes how long a *crashed* worker's row
waits to come back rather than how long a job may take. A handler that
never yields to the event loop cannot be renewed, and neither can one
whose worker has died. Should a reservation lapse anyway, the first
worker's late settlement is rejected rather than touching the new
reservation, and the worker reports it as a lost settlement (see
{doc}`queue`'s "When a settlement is lost").

Reservation times are written and compared with each worker's own
clock, not the database's — a renewal included — so keep worker clocks
synchronized: skew makes a reservation expire early or late by the
difference.

## Clearing a queue

`SqlQueue` declares `ClearableQueueInterface` (see {doc}`queue`'s
"Clearing is a separate capability"). Clearing deletes the queue's
unreserved rows, delayed ones included, and reports how many. A
reservation past its timeout is left in place, since its worker may
still be running the job.

## Delays and retries

A delayed job becomes available to the first `pop()` after its delay,
measured with the pushing and popping hosts' clocks, so it runs late
while every worker is busy. Retries follow {doc}`queue`: `maxAttempts`,
`QUEUE_MAX_ATTEMPTS` and `QUEUE_RETRY_BASE_DELAY_SECONDS`. A delayed
retry moves the row's own `available_at`, so it waits exactly as a
delayed push does and needs no schema change.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
QUEUE_REPORTS_CONNECTION=sql
DB_REPORTS_CONNECTION=mysql
DB_REPORTS_HOST=127.0.0.1
```

A connection's name scopes its selector, its `DB_*` keys and
`QUEUE_VISIBILITY_TIMEOUT_SECONDS`; `default` reads the plain keys.
`QUEUE_CONNECTION_NAME` makes `reports` the bound `QueueInterface`, and
`queue:work --connection=reports` runs it either way. See {doc}`queue`'s
"Named connections" and {doc}`config`.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — reservation fencing and delivery contracts.
- {doc}`persistence` — connecting to MySQL and Postgres.
- {doc}`migrations` — running the table migration.
