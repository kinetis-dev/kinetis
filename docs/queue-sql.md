# Queue (SQL)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-sql
```
````

Adds MySQL/Postgres as a backend for {doc}`queue`, riding an existing
database instead of a separate service. Application code that already
pushes and pops jobs through `QueueInterface` needs no changes at all to
switch — only your configuration changes.

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

`pop()` relies on `SELECT ... FOR UPDATE SKIP LOCKED` to guarantee two
workers never receive the same job — that's this backend's actual
version floor: **MySQL 8.0+ or MariaDB 10.6+**. An older server doesn't
support that clause at all, so `pop()` fails outright rather than
degrade quietly.

## Configuring

`DB_*` are the exact keys `kinetis/persistence` already reads — nothing
new to set up beyond a working database connection.

## The queue needs a table

`kinetis/queue-sql` ships two ready-to-copy {doc}`migrations` files — one
per dialect, since the auto-incrementing primary key syntax itself isn't
portable between MySQL and Postgres:

```{code-block} text
vendor/kinetis/queue-sql/resources/migrations/create_kinetis_queue_jobs_table.mysql.php.stub
vendor/kinetis/queue-sql/resources/migrations/create_kinetis_queue_jobs_table.pgsql.php.stub
```

Copy whichever matches your database into your own `migrations/`
directory with a timestamp prefix, then run `vendor/bin/kinetis migrate`.

The table includes a nullable `metadata` column — the instrumentation
propagation channel (see {doc}`telemetry`). A table created from an
earlier stub needs it added:

```{code-block} sql
ALTER TABLE kinetis_queue_jobs ADD COLUMN metadata TEXT NULL;
```

## A crashed worker's job: the visibility timeout

By default, a job that's been popped but whose worker crashes before
`ack()`/`release()` runs stays reserved **forever** — no other worker can
ever pick it up again, since nothing ever clears its `reserved_at`.

`SqlQueue`'s second constructor argument, `$visibilityTimeoutSeconds`,
closes it — the standard "visibility timeout" pattern SQS's own
`VisibilityTimeout` already uses:

```{code-block} php
use Kinetis\QueueSql\SqlQueue;

$queue = new SqlQueue($db, visibilityTimeoutSeconds: 300);
```

A row reserved longer than this becomes poppable again by any worker —
`attempts` is incremented at that point (crediting the crashed attempt,
the same as an explicit `release()` call would), so `maxAttempts` still
eventually gives up on a job whose worker keeps crashing rather than
retrying it forever. `null` (the default) preserves the original
forever-stranded behavior exactly, unchanged.

A value below `1` — `0` or negative — is rejected at construction: it
would make `pop()`'s own query treat a row reserved an instant ago (or
one whose reservation timestamp is in the future relative to now) as
already stale, letting a second worker reclaim an actively-held
reservation immediately instead of after it genuinely goes stale.

`kinetis queue:work` reads this from the optional
`QUEUE_VISIBILITY_TIMEOUT_SECONDS` environment variable (via
`Config::scopedKey()`, so it respects `QUEUE_CONNECTION_NAME` the same as
every other queue setting) — absent means `null`, the same as constructing
`SqlQueue` directly with no second argument:

```{code-block} text
QUEUE_CONNECTION=sql
QUEUE_VISIBILITY_TIMEOUT_SECONDS=300
```

Pick a value comfortably longer than your slowest real job takes to run —
too short reclaims a job that's still being legitimately processed,
producing exactly the duplicate-processing risk a visibility timeout is
meant to bound, not eliminate outright.

## Clearing a queue

`SqlQueue` declares `Kinetis\Queue\ClearableQueueInterface` (see
{doc}`queue`'s "Clearing is a separate capability"). Clearing deletes
every row on the queue whose `reserved_at` is null, and reports how many
rows the `DELETE` removed.

That predicate is narrower than the one `size()` and `pop()` read,
which treats a reservation older than `QUEUE_VISIBILITY_TIMEOUT_SECONDS`
as available again. A row that has outrun the timeout is left alone here
— see {doc}`queue`'s "Clearing is a separate capability" for why a clear
draws the line differently from a reclaim.

`ack()`, `release()` and `fail()` address a row by id alone, without
checking whose reservation it currently holds, so a settlement arriving
after the timeout has already let another worker reclaim the row lands
on that worker's delivery instead. The row carries no token identifying
which reservation wrote it, so this backend can raise no
`Kinetis\Queue\Exception\StaleJobHandleException` — keep the timeout
comfortably longer than your slowest job, as above.

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Checked on this backend's own polling cycle rather than firing at the
exact moment the delay ends, so a delayed job can run slightly later
than its exact target time — typically by a few seconds, not less.

## Retries and giving up

Everything {doc}`queue` documents about `maxAttempts`, `QUEUE_MAX_ATTEMPTS`,
and the log entry written when a job is finally given up on works
identically here — nothing about retry behavior changes by switching to
this backend.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
DB_REPORTS_CONNECTION=mysql
DB_REPORTS_HOST=127.0.0.1
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`QUEUE_CONNECTION_NAME` picks which named block of `DB_*` settings a
worker reads, and `'default'` (or simply not setting it) reads the plain
keys shown earlier in this page.

## If the package isn't installed

Setting `QUEUE_CONNECTION=sql` without having run
`composer require kinetis/queue-sql` produces a clear error telling you
which package to install, rather than a confusing crash.

## See also

- {doc}`queue` — writing jobs, pushing and popping, and everything about
  retries that applies to every backend equally.
- {doc}`persistence` — connecting to MySQL and Postgres directly.
- {doc}`migrations` — running this backend's own required migration.
- {doc}`config` — the named-connection convention used above.
