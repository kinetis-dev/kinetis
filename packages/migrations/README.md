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

Raw SQL `up()`/`down()` migrations, tracked in a `kinetis_migrations`
table, run through `migrate*` commands registered on
`vendor/bin/kinetis`. No fluent
DDL builder, no schema-diffing — the same "thin, not an ORM" shape as
[`kinetis/query-builder`](../query-builder).

```php
// migrations/20260810143000_create_orders_table.php
use Amp\Mysql\MysqlLink;
use Amp\Postgres\PostgresLink;
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
vendor/bin/kinetis migrate                     # runs every pending migration
vendor/bin/kinetis migrate:rollback            # rolls back the most recently applied one
vendor/bin/kinetis migrate:status              # lists applied/pending migrations
vendor/bin/kinetis migrate:make <description>
```

## Provides

Installing this package is what opts it in — it registers the
following automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[kinetis.dev/docs/cli.html](https://kinetis.dev/docs/cli.html)):

- **Commands**: `migrate`, `migrate:rollback`, `migrate:status`, and
  `migrate:make` on `vendor/bin/kinetis`. All four run without the
  application's bootstrap (`bootstrap: false`) — they read `DB_*`
  directly, so they work in bare contexts (CI, an init container) with
  nothing but environment variables.

Nothing else — no service bindings, routes, middleware, event
listeners, or MCP tools.

## Configuration

The `migrate*` commands read the same `DB_*` keys `kinetis/persistence`
documents (`DB_CONNECTION`/`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`/
`DB_PORT`, ...) from the environment or `.env`, plus one key of this
package's own:

| Key | Default | Purpose |
|---|---|---|
| `MIGRATE_CONNECTION_NAME` | `default` | Which named `DB_*` block to migrate; the `--connection=<name>` flag wins over it. |

Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/migrations
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[kinetis.dev/docs/migrations.html](https://kinetis.dev/docs/migrations.html).

## License

MIT — see [LICENSE](../../LICENSE).
