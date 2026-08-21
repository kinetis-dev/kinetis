# Migrations

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/migrations
```
````

A thin runner for versioned schema changes: raw SQL `up()`/`down()`
migrations, tracked in a `kinetis_migrations` table, run through
`migrate*` commands the package registers on `vendor/bin/kinetis` (see
{doc}`cli`). No fluent DDL builder, no schema-diffing.

## Writing a migration

Each file in a `migrations/` directory at your project root returns an
anonymous class implementing `Migration`:

```{code-block} php
:caption: migrations/20260810143000_create_orders_table.php

<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Migrations\Migration;

return new class implements Migration
{
    public function up(MysqlLink|PostgresLink $db): void
    {
        $db->execute(<<<'SQL'
            CREATE TABLE orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL
            )
            SQL);
    }

    public function down(MysqlLink|PostgresLink $db): void
    {
        $db->execute('DROP TABLE orders');
    }
};
```

The timestamp prefix (`YmdHis`) keeps migrations in chronological order
regardless of which branch created the file, and doubles as the name
`kinetis_migrations` tracks it by. A multi-statement migration is multiple
`$db->execute()` calls, not one string with semicolons.

Scaffold one instead of writing the boilerplate by hand:

```{code-block} sh
vendor/bin/kinetis migrate:make "create orders table"
# Created migrations/20260810143000_create_orders_table.php
```

## Running migrations

```{code-block} sh
vendor/bin/kinetis migrate           # runs every pending migration, in filename order
vendor/bin/kinetis migrate:rollback  # rolls back the single most recently applied migration
vendor/bin/kinetis migrate:status    # lists every migration with its applied/pending state
```

`migrate`/`migrate:rollback`/`migrate:status` connect using the same `.env`/environment
convention {doc}`config` describes, plus two variables specific to this
package:

```{code-block} text
DB_CONNECTION=mysql   # or "pgsql" — no default
DB_HOST=127.0.0.1
DB_NAME=app
DB_USER=app
DB_PASSWORD=secret
DB_PORT=3306           # optional
```

`DB_CONNECTION` has no default: guessing the wrong engine would run
migrations against the wrong database with no warning at all.

To run migrations against a database other than the default connection
(see {doc}`config`'s named-connection convention), pass
`--connection=<name>` to any command, or set `MIGRATE_CONNECTION_NAME`
in the environment — the explicit flag wins when both are given:

```{code-block} sh
vendor/bin/kinetis migrate --connection=db2
```

```{code-block} text
MIGRATE_CONNECTION_NAME=db2

DB_DB2_CONNECTION=pgsql
DB_DB2_HOST=reporting.internal
DB_DB2_PASSWORD=secret
```

Omit it and the commands read the plain `DB_*` keys, exactly as above.

`migrate` dispatches `Kinetis\Migrations\Events\MigrationApplied` once
per migration it actually runs, in the order they ran;
`migrate:rollback` dispatches `Events\MigrationRolledBack` when it
undoes one. Both are ordinary events — write a `#[Listener]` for
whichever one you need (a deploy notification, for one). See
{doc}`events` for the full catalog.

## Transactions are not automatic

A migration's `up()`/`down()` runs exactly as written — the runner never
wraps it in a transaction. Postgres supports transactional DDL; MySQL's
DDL statements auto-commit regardless of any surrounding transaction, so
a runner-imposed transaction would be real atomicity on one backend and a
false sense of it on the other. A migration that wants atomicity on
Postgres opens one itself, inside its own `up()`:

```{code-block} php
public function up(MysqlLink|PostgresLink $db): void
{
    $tx = $db->beginTransaction();

    try {
        $tx->execute('...');
        $tx->execute('...');
        $tx->commit();
    } catch (\Throwable $e) {
        $tx->rollback();
        throw $e;
    }
}
```

If a migration's `up()` throws partway through a `migrate` run, every
migration before it in that run is already recorded as applied, and the
failing one is not. The exception propagates, so the run stops there
instead of continuing past a failure.

## Concurrent deploys are safe

`migrate`/`migrate:rollback` hold a cross-process advisory lock (MySQL's
`GET_LOCK()`; Postgres has no equivalent blocking-with-timeout primitive,
so it's `pg_try_advisory_lock()` instead, polled with a short sleep
between attempts) for the whole run, so two deploy instances starting at
the same time can't both compute the same pending set and run it twice —
the second one waits for the first to finish before it even looks at
what's pending.

The lock is scoped to your database session, not a row in a table, so it
releases on its own the moment the connection holding it closes —
gracefully or not — with nothing to clean up by hand if a process is
killed mid-migration. Waiting longer than 10 seconds throws
`Exception\MigrationLockTimeoutException`, most often meaning another
`migrate`/`migrate:rollback` is already running elsewhere; retry once it
finishes.

## See also

- {doc}`query-builder` — a fluent builder for querying the tables these
  migrations create, on the same MySQL/Postgres connections.
- {doc}`persistence` — the connection pool shape the `migrate*`
  commands build internally.
- {doc}`config` — the `.env`/environment convention `migrate` reads its
  connection details from.
