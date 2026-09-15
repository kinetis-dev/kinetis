# Migrations

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/migrations
```

It requires `kinetis/framework`, `kinetis/persistence` and
`kinetis/database-bridge`, and installing it registers the `migrate*`
commands on `vendor/bin/kinetis` (see {doc}`cli`).
````

A thin runner for versioned schema changes: raw SQL `up()`/`down()`
migrations, tracked in a `kinetis_migrations` table, run through
`migrate*` commands the package registers on `vendor/bin/kinetis`. No
fluent DDL builder, no schema-diffing.

## Writing a migration

Scaffold a migration:

```{code-block} sh
vendor/bin/kinetis migrate:make "create orders table"
# Created migrations/20260810143000_create_orders_table.php
```

Each file in the `migrations/` directory at your project root returns an
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
`kinetis_migrations` tracks it by. A multi-statement migration is
multiple `$db->execute()` calls, not one string with semicolons.

## Running migrations

```{code-block} sh
vendor/bin/kinetis migrate           # runs every pending migration, in filename order
vendor/bin/kinetis migrate:rollback  # rolls back the migration applied most recently
vendor/bin/kinetis migrate:status    # lists every migration with its applied/pending state
```

The commands read the same `DB_*` keys as the application
({doc}`persistence`) from the environment or `.env`:

```{code-block} text
DB_CONNECTION=mysql   # or "pgsql" — no default
DB_HOST=127.0.0.1
DB_NAME=app
DB_USER=app
DB_PASSWORD=secret
```

They run without the application's bootstrap, so they work in CI or an
init container with nothing but environment variables, and a connection
registered in `bootstrap.php` does not apply to them. `DB_CONNECTION` is
required: guessing the wrong engine would run migrations against the
wrong database with no warning at all.

To migrate a database other than the default connection (see
{doc}`config`'s named-connection convention), pass `--connection=<name>`
to any command, or set `MIGRATE_CONNECTION_NAME` in the environment — the
explicit flag wins when both are given:

```{code-block} sh
vendor/bin/kinetis migrate --connection=db2
```

```{code-block} text
MIGRATE_CONNECTION_NAME=db2

DB_DB2_CONNECTION=pgsql
DB_DB2_HOST=reporting.internal
DB_DB2_PASSWORD=secret
```

`migrate` dispatches `Kinetis\Migrations\Events\MigrationApplied` once
per migration it actually runs, in the order they ran;
`migrate:rollback` dispatches `Events\MigrationRolledBack` when it undoes
one. Both are ordinary events — write a `#[Listener]` for whichever one
you need (a deploy notification, for one). See {doc}`events` for the full
catalog.

### From your own code

The commands run a `MigrationRunner`, which takes the link it runs on,
the ledger repository, and the migrations directory:

```{code-block} php
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;

$db = ConnectionFactory::singleSession($config, 'db2');
$runner = new MigrationRunner($db, new SqlMigrationRepository($db), $projectRoot . '/migrations');

$runner->migrate();  // runs every pending migration, in filename order; returns their names
$runner->rollback(); // rolls back the migration applied most recently; returns its name, or null
$runner->status();   // every migration, with whether it is applied
```

The link has to be a single-session client, since the run's lock lives
in that session (see "Concurrent deploys are safe" below).

## What the ledger records, and what it checks

`kinetis_migrations` holds one row per applied migration: the
`migration` name, the `checksum` (SHA-256) of the file that ran, and the
`application_order` this database applied it in.

`migrate`, `migrate:rollback` and `migrate:status` all verify that ledger
against the `migrations/` directory before doing anything else. Every
applied migration must still have a file, and that file must still hash
to the checksum recorded when it ran. The first one that fails either
check throws `Exception\MigrationIntegrityException`, naming the
migration and the reason, before any `up()`, `down()` or ledger write.
Never edit a migration that has been deployed: restore the deployed file
and the commands run again, and write a new migration for the change.
Nothing rewrites the ledger to match a changed file, because only the
file that ran describes what the database holds.

`migrate:rollback` undoes the migration with the highest
`application_order` — the one this database applied most recently, which
is not always the one whose name sorts last. A migration merged from
another branch and applied after a later-timestamped one is the first to
come back off, and a migration rolled back and applied again is the
newest one from then on.

## Transactions are not automatic

A migration's `up()`/`down()` runs exactly as written — the runner never
wraps it in a transaction. PostgreSQL supports transactional DDL; MySQL's
DDL statements auto-commit regardless of any surrounding transaction, so
a runner-imposed transaction would be real atomicity on one backend and a
false sense of it on the other. A migration that wants atomicity on
PostgreSQL opens one itself, inside its own `up()`:

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

```{warning}
Statements a failing `up()` already ran stay applied unless the migration
wrapped them in its own transaction, which on MySQL DDL cannot do. The
migration stays pending, so running `migrate` again re-runs its first
statements against a schema that already has them. Repair the schema by
hand, or make each statement safe to repeat, before running it again.
```

## Concurrent deploys are safe

`migrate` and `migrate:rollback` hold a cross-process advisory lock for
the whole run, so two deploy instances starting at the same time cannot
both compute the same pending set and run it twice: the second waits for
the first to finish before it looks at what is pending. Waiting longer
than 10 seconds throws `Exception\MigrationLockTimeoutException`, most
often meaning another `migrate` or `migrate:rollback` is still running
elsewhere; retry once it finishes.

The lock belongs to the database session, so it releases on its own when
the connection holding it closes — gracefully or not — with nothing to
clean up if a process is killed mid-migration. That is why the commands
connect through `ConnectionFactory::singleSession()`, over PDO whatever
`DB_DRIVER` says. If that session is lost mid-run, a migration
abandoning a transaction being enough, the run stops with
`Kinetis\Persistence\Exception\ConnectionException` rather than carrying
on unlocked. {ref}`database-reference-single-session` describes the
client.

## See also

- {doc}`query-builder` — querying the tables these migrations create, on
  the same MySQL/PostgreSQL connections.
- {doc}`persistence` — the `DB_*` connection the `migrate*` commands
  read.
- {doc}`config` — the `.env`/environment convention and named
  connections.
