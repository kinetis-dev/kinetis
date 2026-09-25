# Migrations

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/migrations
```

It requires `kinetis/framework`, `kinetis/persistence` and
`kinetis/database-bridge`, and installing it registers the `migrate*`
commands on `vendor/bin/kinetis` (see {doc}`cli`).

The commands always hold one PDO session for their advisory lock, whatever
`DB_DRIVER` says. Install `ext-pdo_mysql` for `DB_CONNECTION=mysql`, or
`ext-pdo_pgsql` for `DB_CONNECTION=pgsql`. An application that selects the
native driver for request work still needs the matching PDO extension for
migrations. Declare that extension in the application's `composer.json` and
install it in the runtime or migration image.
````

A thin runner for versioned schema changes: raw SQL `up()`/`down()`
migrations, tracked in a `kinetis_migrations` table in each database,
run through `migrate*` commands the package registers on
`vendor/bin/kinetis`. No fluent DDL builder, no schema-diffing.

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

For example, a PostgreSQL image whose request path uses native `ext-pgsql`
needs both clients because migrations use PDO:

```{code-block} dockerfile
RUN docker-php-ext-install pgsql pdo_pgsql
```

Its application manifest declares both platform requirements:

```{code-block} json
"require": {
    "ext-pdo_pgsql": "*",
    "ext-pgsql": "*"
}
```

They run without the application's bootstrap, so they work in CI or an
init container with nothing but environment variables, and a connection
registered in `bootstrap.php` does not apply to them. `DB_CONNECTION` is
required: guessing the wrong engine would run migrations against the
wrong database with no warning at all.

`migrate` dispatches `Kinetis\Migrations\Events\MigrationApplied` once
per migration it actually runs, in the order they ran;
`migrate:rollback` dispatches `Events\MigrationRolledBack` when it undoes
one. Each carries the migration's `name` and the `connection` it ran on.
Both are ordinary events — write a `#[Listener]` for whichever one you
need (a deploy notification, for one). See {doc}`events` for the full
catalog.

## Several databases

The files directly in `migrations/` belong to the default connection.
Each directory directly inside `migrations/` belongs to the named
connection of the same name, which reads its own `DB_{NAME}_*` keys
({doc}`config`'s named-connection convention):

```{code-block} text
migrations/
├── 20260810143000_create_orders_table.php         # default: DB_*
└── reporting/
    └── 20260811090000_create_daily_totals.php     # reporting: DB_REPORTING_*
```

```{code-block} text
DB_REPORTING_CONNECTION=pgsql
DB_REPORTING_HOST=reporting.internal
DB_REPORTING_NAME=reporting
DB_REPORTING_USER=reporting
DB_REPORTING_PASSWORD=secret
```

A connection directory's migrations run only on that connection's
database, and its `kinetis_migrations` table records only them.
Discovery stops there: `migrations/reporting/archive/` is neither a
connection nor part of `reporting`.

Every directory directly inside `migrations/` must be named as a
connection: lowercase ASCII letters and digits, starting with a letter
(`^[a-z][a-z0-9]*$`), the names `kinetis/orm` accepts for an entity's
connection. `app` is reserved, because its `DB_APP_NAME` key is also the
default connection's Postgres application name. A `default` directory is
refused, because the default connection's migrations are the files in
`migrations/` itself. A directory breaking these rules stops the command
with an error naming it, rather than leaving a misspelt connection's
database unmigrated. `--connection` and `MIGRATE_CONNECTION_NAME` values
follow the same rules, except that `default` selects the default
connection. The commands check every name before they read any database
configuration. A named connection without its `DB_{NAME}_CONNECTION` key
fails naming that exact key, such as `DB_REPORTING_CONNECTION`.

### Which connections a command covers

`migrate` and `migrate:status` cover every connection: the default
first, then the connection directories in byte order of their names.
With more than one connection, each connection's lines follow a
`Connection: <name>` line:

```{code-block} text
$ vendor/bin/kinetis migrate
Connection: default
Migrated: 20260810143000_create_orders_table
Connection: reporting
Nothing to migrate.
```

A project without connection directories has the default connection
alone, and prints no `Connection:` line.

`--connection=<name>` narrows `migrate`, `migrate:status` and
`migrate:rollback` to one connection. Without the flag, a non-empty
`MIGRATE_CONNECTION_NAME` narrows them the same way. `--connection=default`
selects the files in `migrations/` itself. The plain command is how to
cover every connection; there is no flag for it. A `--connection` with
no value is refused.

```{code-block} sh
vendor/bin/kinetis migrate --connection=reporting
vendor/bin/kinetis migrate:make "create daily totals" --connection=reporting
# Created migrations/reporting/20260811090000_create_daily_totals.php
```

`migrate:make` writes to `migrations/` unless `--connection=<name>`
names a connection; `MIGRATE_CONNECTION_NAME` does not change where it
writes.

### One database at a time

`migrate` runs the connections one after another, never in parallel.
Each one runs on its own session, with its own ledger and advisory
lock, and that session closes before the next connection's opens.

With more than one connection, `migrate` first checks every one of them
the way `migrate:status` does: its `DB_*` keys, its session, and its
ledger against its directory. An unconfigured or unreachable connection,
or one whose ledger fails the check, stops the run before any migration's
`up()`, and `migrate` prints that connection's `Connection: <name>` line
before the error. A check that passes prints nothing. Each connection
checks again under its own lock when its turn comes.

A failure after that, such as a migration's `up()` throwing or a lock
timeout, stops the run at that connection. The connections before it
stay migrated: no transaction spans databases, and nothing rolls them
back. Fix the cause and run `migrate` again; the finished connections
have nothing pending.

`migrate:rollback` never spans databases. With connection directories
present, it needs `--connection=<name>` or `MIGRATE_CONNECTION_NAME`, and
without either it prints its usage to STDERR and exits 1. A project
without connection directories rolls back the default connection.

Give each connection its own database. Two connections pointing at the
same database share its `kinetis_migrations` table, and each one then
sees the other's migrations as applied migrations with no file, which
is not supported.

## From your own code

The commands run a `MigrationRunner` per connection. It takes the link it
runs on, the ledger repository, and that connection's migrations
directory, and runs that one directory on that one link:

```{code-block} php
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;

$db = ConnectionFactory::singleSession($config, 'reporting');

try {
    $runner = new MigrationRunner($db, new SqlMigrationRepository($db), $projectRoot . '/migrations/reporting');

    $runner->migrate();  // runs every pending migration, in filename order; returns their names
    $runner->rollback(); // rolls back the migration applied most recently; returns its name, or null
    $runner->status();   // every migration, with whether it is applied
} finally {
    $db->close();
}
```

The link has to be a single-session client, since the run's lock lives
in that session (see "Concurrent deploys are safe" below). The runner
does not close it; its owner does.

## What the ledger records, and what it checks

`kinetis_migrations` holds one row per applied migration: the
`migration` name, the `checksum` (SHA-256) of the file that ran, and the
`application_order` this database applied it in.

`migrate`, `migrate:rollback` and `migrate:status` all verify each
connection's ledger against that connection's directory before doing
anything else. Every
applied migration must still have a file, and that file must still hash
to the checksum recorded when it ran. The first one that fails either
check throws `Exception\MigrationIntegrityException`, naming the
migration and the reason, before any `up()`, `down()` or ledger write.
Never edit a migration that has been deployed: restore the deployed file
and the commands run again, and write a new migration for the change.
Nothing rewrites the ledger to match a changed file, because only the
file that ran describes what the database holds.

Moving a deployed migration to another connection's directory is the
same violation: the connection that applied it no longer has its file.
Move it back, and write a new migration in the other directory for the
change that belongs there.

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

`migrate` and `migrate:rollback` hold a cross-process advisory lock on
each database for the whole of that database's run, so two deploy instances starting at the same time cannot
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
