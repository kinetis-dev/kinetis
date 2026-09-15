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
