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
`QUEUE_CONNECTION_NAME` picks the connection behind `push()` and does not
redirect a transaction you supply.

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
```

Ordinary `QueueInterface` consumers and `pushOn()` callers then share
one backend instance and its one connection pool. Binding only
`SqlQueue::class` leaves the default `QueueInterface` binding in place,
and it builds a second `SqlQueue` with a pool of its own.

{doc}`appendix-queue`'s "Multiple backends" shows the same registration
where several backends run side by side. `pushOn()` takes a raw
{doc}`persistence` transaction; an {doc}`orm` transaction session does
not expose its transaction.

## Visibility timeout

`QUEUE_VISIBILITY_TIMEOUT_SECONDS` (default `300`, at least `1`) is how
long a reserved job belongs to its worker. When a worker dies before
settling a job, the job becomes poppable again once its reservation is
older than this, with its attempt count increased. Constructing
`SqlQueue` directly takes the same value as its `visibilityTimeoutSeconds`
argument.

A reservation is never renewed. Set the timeout above your slowest job:
a job still running when its reservation expires runs again beside the
first. The first worker's late settlement is rejected rather than
touching the new reservation, and the worker reports it as a lost
settlement (see {doc}`queue`'s "When a settlement is lost").

Reservation times are written and compared with each worker's own
clock, not the database's, so keep worker clocks synchronized: skew makes
a reservation expire early or late by the difference.

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
`QUEUE_MAX_ATTEMPTS`, and immediate release.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
DB_REPORTS_CONNECTION=mysql
DB_REPORTS_HOST=127.0.0.1
```

`QUEUE_CONNECTION_NAME` picks which scoped block of `DB_*` keys a worker
reads, and `default`, or leaving it unset, reads the plain keys. See
{doc}`config`.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — reservation fencing and delivery contracts.
- {doc}`persistence` — connecting to MySQL and Postgres.
- {doc}`migrations` — running the table migration.
