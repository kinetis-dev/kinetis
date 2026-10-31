# Failed Jobs

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-failed-jobs
```
````

Keeps every job the {doc}`queue` worker gives up on in a MySQL or
Postgres table, recorded before the backend deletes it, so you can list
those jobs, retry them, forget them, or prune old ones. It works with
every queue backend — Redis, SQL, SQS and RabbitMQ — and with every named
queue connection, and stores the records through
`kinetis/database-bridge`, whatever backend the queue itself uses.

Installing the package turns recording on; there is no setting to
enable it. With the package installed, `queue:work` depends on the
failed-jobs database: it stops when that database refuses a write.

## Create the table

The package ships one {doc}`migrations` stub per dialect:

```{code-block} text
vendor/kinetis/queue-failed-jobs/resources/migrations/create_kinetis_failed_jobs_table.mysql.php.stub
vendor/kinetis/queue-failed-jobs/resources/migrations/create_kinetis_failed_jobs_table.pgsql.php.stub
```

Copy the one matching your database into your `migrations/` directory
with a timestamp prefix, then run `vendor/bin/kinetis migrate`. When the
records live on a named database connection (below), the stub goes in
that connection's directory, `migrations/<name>/`.

## Choose the database

```{code-block} text
FAILED_JOBS_DB_CONNECTION_NAME=default
```

`FAILED_JOBS_DB_CONNECTION_NAME` names the database connection the store
uses: the `DB_*` keys for `default`, which an unset or blank value
selects, and `DB_{NAME}_*` for any other name — `reports` reads
`DB_REPORTS_CONNECTION`, `DB_REPORTS_HOST` and the rest (see
{doc}`persistence`). A name outside the connection-name grammar —
lowercase letters and digits, starting with a letter — is rejected
before any connection is built.

The store opens its own link to that database the first time it is
needed and closes it when the worker or command ends. `queue:work`
builds it before it prints that it started, so missing or invalid `DB_*`
keys stop the worker at startup. A server that cannot be reached is
found at the first job the worker gives up on: the write fails, and the
worker stops with the job still on its queue.

## What a record holds

| Field | Contents |
|---|---|
| id | 32 random hexadecimal characters |
| connection | The `--connection` value `queue:work` ran with, or none for the bound queue |
| queue | The queue the job was popped from |
| class, args | The job class and its constructor arguments, in full |
| maxAttempts | The cap the job was pushed with, or none when it used the worker's default |
| attempts | The attempt that failed last |
| exception | The exception class, and its message cut to 2 KB |
| failed at | When the worker gave up on the job |

No stack trace is stored. **The table holds each job's arguments in
full, `#[Sensitive]` values included**, because a retry rebuilds the job
from them; `#[Sensitive]` governs what is logged, not what is stored.
Give the table the access controls the queue's own storage has. The
MySQL stub stores arguments as `MEDIUMTEXT`, up to 16 MiB; a job whose
arguments the table cannot hold stops every worker that pops it, so keep
the column at least as large as your queue backend's largest message.

## Commands

```{code-block} sh
vendor/bin/kinetis queue:failed                      # oldest first, 50 at most
vendor/bin/kinetis queue:failed --queue=mail --limit=200
vendor/bin/kinetis queue:retry 3f9c0b2a64d14e8f9a0c51d7e2b46a18
vendor/bin/kinetis queue:retry --all --connection=ledger
vendor/bin/kinetis queue:forget 3f9c0b2a64d14e8f9a0c51d7e2b46a18
vendor/bin/kinetis queue:prune --hours=168
```

`queue:failed` and `queue:retry --all` select records with the same
options: `--connection=<name>` for jobs a worker started with that
`--connection` recorded, `--bound` for jobs a worker on the bound queue
recorded, and `--queue=<name>`. Without them, every record is selected.

`queue:failed` prints each record's id, time, connection, queue, class,
attempts, exception and arguments. Arguments marked `#[Sensitive]`
print as `[redacted]`, and when the job class no longer exists every
argument does, since nothing says which ones were sensitive.

`queue:retry` pushes each job back onto the connection and queue it
failed on, with the `maxAttempts` it was pushed with, and then forgets
its record. A record with no connection goes to the bound queue; a named
one goes to that queue connection, built from its `QUEUE_{NAME}_*` keys
as `queue:work --connection` builds it. A record whose retry fails — the
class no longer exists or no longer accepts the stored arguments, the
connection is no longer configured, the push is refused — is reported
and kept, and the command exits 1. `--all` works through every selected
record oldest first, reports how many were retried and how many failed,
and leaves records of jobs that failed after it started for the next
run.

`queue:forget` deletes one record without retrying it. `queue:prune`
deletes every record older than `--hours`, which has no default; nothing
is pruned automatically.

## Duplicate records

A job the worker gives up on is always either still held by the queue
backend or recorded, possibly both, never neither. The worker records
the job before it asks the backend to delete it, and never deletes a
record itself:

- When the record cannot be written, the worker stops without deleting
  the job, and the backend delivers it again once its reservation
  expires.
- When the worker dies between the two steps, or the delete fails, or
  the backend reports the delivery already lost, the record stays and the
  job is delivered again. Each delivery that fails at the cap again adds
  another record, so one job can leave several, including a job whose
  later delivery succeeded.
- `queue:retry` pushes before it forgets. When it is interrupted
  between the two, the record stays, and retrying it again pushes the job
  a second time.

This is the at-least-once contract of {doc}`queue`'s
[A job can run more than once](queue.md#a-job-can-run-more-than-once):
a handler that tolerates running twice tolerates a retried duplicate
too.

`Kinetis\Queue\Events\JobFailedPermanently` fires once the backend has
deleted the job and carries the record's id as `failedJobId` (see
{doc}`events`).

## What is never recorded

- A job whose process dies on every attempt never reaches the worker's
  failure path, so nothing records it; the backend's own recovery keeps
  redelivering it. On SQS a redrive policy covers that case — see
  {doc}`queue-sqs`.
- A stored message that cannot be decoded is deleted and logged as a
  warning, as without this package.
- `SyncQueue` runs jobs inline and records nothing.
- A job removed by `queue:clear`.

## See also

- {doc}`queue` — retries, failures and delivery guarantees.
- {doc}`appendix-queue` — the worker's settlement order with a recorder
  bound.
- {doc}`migrations` — running the stub, and per-connection directories.
- {doc}`appendix-configuration` — `FAILED_JOBS_DB_CONNECTION_NAME`.
