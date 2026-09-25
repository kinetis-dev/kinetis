<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/migrations</strong>
  <br>
  <strong>A thin database migration runner for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/v/kinetis/migrations?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/dt/kinetis/migrations" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/php-v/kinetis/migrations" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/l/kinetis/migrations" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Raw SQL `up()`/`down()` migrations, tracked in a `kinetis_migrations`
table in each database, run through `migrate*` commands registered on
`vendor/bin/kinetis`. No fluent
DDL builder, no schema-diffing — the same "thin, not an ORM" shape as
[`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder).

```php
// migrations/20260810143000_create_orders_table.php
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

```sh
vendor/bin/kinetis migrate                       # runs every pending migration
vendor/bin/kinetis migrate:rollback              # rolls back the migration applied most recently
vendor/bin/kinetis migrate:status                # lists applied/pending migrations
vendor/bin/kinetis migrate:make "create orders"  # scaffolds a migration file
```

The files directly in `migrations/` belong to the default connection, and
each directory directly inside it, such as `migrations/reporting/`, to the
named connection of that name, read from its `DB_REPORTING_*` keys.
`migrate` and `migrate:status` cover every connection, default first,
one database at a time and stopping at the first failure; with
connection directories present they print `Connection: <name>` before
each connection's lines. `--connection=<name>` narrows a command to one
connection, and `migrate:rollback` requires it once connection
directories exist. Give each connection its own database. See
[Several databases](https://kinetis.dev/docs/migrations.html#several-databases)
for the naming rules, the preflight check and the failure semantics.

The ledger holds one row per applied migration: its name, the SHA-256 of
the file that ran, and the order this database applied it in. Every
command verifies that against the connection's directory first — an
applied migration whose file is gone, moved to another connection's
directory, or whose contents no longer hash to what was recorded, throws
`Exception\MigrationIntegrityException` before any `up()`, `down()` or
ledger write, and restoring the deployed file is what clears it.

## Provides

Installing this package is what opts it in — it registers the
following automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[kinetis.dev/docs/cli.html](https://kinetis.dev/docs/cli.html)):

- **Commands**: `migrate`, `migrate:rollback`, `migrate:status`, and
  `migrate:make` on `vendor/bin/kinetis`. All four run without the
  application's bootstrap (`bootstrap: false`) — they read `DB_*`
  directly, through
  [`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)'s
  connection policy, so they work in bare contexts (CI, an init
  container) with nothing but environment variables. A run holds one PDO
  session, whatever `DB_DRIVER` says, because its advisory lock lives in
  that session.
- **Events**: `Kinetis\Migrations\Events\MigrationApplied` and
  `MigrationRolledBack`, dispatched once per migration `migrate`/
  `migrate:rollback` actually runs, each with the migration's `name` and
  the `connection` it ran on. See
  [kinetis.dev/docs/events.html](https://kinetis.dev/docs/events.html)
  for the full list across every package.

That's the entire `extra.kinetis` surface — no service bindings,
routes, middleware, event listeners it registers itself, or MCP tools.

## Configuration

The `migrate*` commands read the same `DB_*` keys
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
documents (`DB_CONNECTION`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`/
`DB_PORT`, ...) from the environment or `.env`, plus one key of this
package's own:

| Key | Default | Purpose |
|---|---|---|
| `MIGRATE_CONNECTION_NAME` | — | When non-empty, narrows `migrate`, `migrate:status` and `migrate:rollback` to that one connection; the `--connection=<name>` flag wins over it. Unset, `migrate` and `migrate:status` cover every connection. |

Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/migrations
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence), and
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge).
The `migrate*` commands always hold one PDO session for their advisory
lock, whatever `DB_DRIVER` says. Install the matching PDO driver as well:
`ext-pdo_mysql` for `DB_CONNECTION=mysql`, or `ext-pdo_pgsql` for
`DB_CONNECTION=pgsql`. A worker application using the native driver still
needs that PDO extension for migrations.

For example, a PostgreSQL image whose request path uses native `ext-pgsql`
needs both extensions:

```dockerfile
RUN docker-php-ext-install pgsql pdo_pgsql
```

Full documentation:
[kinetis.dev/docs/migrations.html](https://kinetis.dev/docs/migrations.html).

## License

MIT — see [LICENSE](LICENSE).
