<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/migrations</strong>
  <br>
  <strong>A thin database migration runner for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/v/kinetis/migrations" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/dt/kinetis/migrations" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/php-v/kinetis/migrations" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/migrations"><img src="https://img.shields.io/packagist/l/kinetis/migrations" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Raw SQL `up()`/`down()` migrations, tracked in a `kinetis_migrations`
table, run through a standalone `vendor/bin/migrate` binary. No fluent
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
vendor/bin/migrate migrate    # runs every pending migration
vendor/bin/migrate rollback   # rolls back the most recently applied one
vendor/bin/migrate status     # lists applied/pending migrations
vendor/bin/migrate make <description>
```

## Installation

```sh
composer require kinetis/migrations
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/migrations.html](https://docs.kinetis.dev/migrations.html).

## License

MIT — see [LICENSE](../../LICENSE).
