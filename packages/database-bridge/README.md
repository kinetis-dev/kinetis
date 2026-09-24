<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/database-bridge</strong>
  <br>
  <strong>Kinetis wiring for kinetis/persistence</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/database-bridge"><img src="https://img.shields.io/packagist/v/kinetis/database-bridge?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/database-bridge"><img src="https://img.shields.io/packagist/dt/kinetis/database-bridge" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/database-bridge"><img src="https://img.shields.io/packagist/php-v/kinetis/database-bridge" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/database-bridge"><img src="https://img.shields.io/packagist/l/kinetis/database-bridge" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

[`kinetis/persistence`](https://github.com/kinetis-dev/persistence)
depends on no Kinetis package. This package connects it to a Kinetis
application: the `DB_*` configuration keys, the default connection
binding, SQL spans through Kinetis telemetry, a lazy request-scoped
`TransactionGuard`, and, with [`kinetis/orm`](https://github.com/kinetis-dev/orm)
installed, compiled entity metadata and a request-scoped `EntityManager`.

```sh
composer require kinetis/database-bridge
```

With `DB_CONNECTION` set, application code constructor-injects the
connection and a `TransactionGuard` with no bootstrap code of its own.
Type the connection as its dialect contract (`MysqlLink` or
`PostgresLink`) or as the dialect-neutral `SqlLink`; both resolve to the
same link:

```php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlTransaction;
use Kinetis\Persistence\TransactionGuard;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
        private TransactionGuard $transactions,
    ) {}

    #[Post('/orders')]
    public function store(): array
    {
        return $this->transactions->transaction($this->db, static function (SqlTransaction $tx): array {
            $tx->execute('UPDATE inventory SET stock = stock - 1 WHERE sku = ?', ['SKU-1']);

            return ['status' => 'created'];
        });
    }
}
```

## Provides

Installing this package is what opts it in — it registers the
following automatically, through the `extra.kinetis` declaration in its
`composer.json` (see
[kinetis.dev/docs/cli.html](https://kinetis.dev/docs/cli.html)):

- **Service binding**: with `DB_CONNECTION` set, the default connection
  is built and bound under its dialect contract
  (`Kinetis\Persistence\Contract\MysqlLink` or `Contract\PostgresLink`)
  before your own `bootstrap.php` runs, and
  `Kinetis\Persistence\Contract\SqlLink` resolves whatever is bound
  under that dialect contract. Your registration wins on the same
  binding: a dialect link you bind is also what `SqlLink` returns, and a
  `SqlLink` you bind replaces only that alias. The connection built here
  is closed when the application scope is disposed; a link your own
  `bootstrap.php` binds stays yours to close. No connection is built and
  no link contract is bound when `DB_CONNECTION` is unset.
  Named connections stay explicit wiring:
  `Kinetis\DatabaseBridge\ConnectionFactory::fromConfig($config, 'reporting')`.
- **Lazy transaction cleanup**: every request scope — an HTTP request, a
  queued job, an MCP message, a command — receives a lazy
  `Kinetis\Persistence\TransactionGuard` binding. Resolving it builds the
  guard and registers `rollbackDangling()` on that scope's disposal; a
  scope that never resolves it builds no guard and registers no cleanup.
- **Telemetry**: every client this package builds reports its queries
  and transactions through Kinetis telemetry, so installing
  [`kinetis/telemetry`](https://github.com/kinetis-dev/telemetry) turns
  them into spans.
- **ORM wiring**, once [`kinetis/orm`](https://github.com/kinetis-dev/orm)
  is installed alongside it (this package does not install it): classes
  marked `#[Entity]` under the project's PSR-4 roots are compiled into the
  AOT cache with the rest of discovery, `Kinetis\Orm\OrmFactory` is bound
  for the worker, and every request scope receives a lazy
  `Kinetis\Orm\EntityManager`, opened on first resolution and closed with
  that scope. Without `DB_CONNECTION`, resolving either throws
  `Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException`. See
  [kinetis.dev/docs/orm.html](https://kinetis.dev/docs/orm.html).

[`kinetis/migrations`](https://github.com/kinetis-dev/migrations)
requires this package and registers its own `migrate*` commands, which
connect through `ConnectionFactory::singleSession()`.
[`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder)
needs no binding: construct `new Query($link)` per statement over the
link this package binds. How each capability composes with the bridge:
[kinetis.dev/docs/persistence.html](https://kinetis.dev/docs/persistence.html).

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`. Every key is
scoped.

| Key | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | *(unset: no connection built)* | `mysql` or `pgsql`. |
| `DB_HOST` | `127.0.0.1` | Server host. |
| `DB_PORT` | `3306` / `5432` | Per dialect; a valid TCP port. |
| `DB_NAME` | `app` | Database name. |
| `DB_USER` | `app` | User. |
| `DB_PASSWORD` | *(required)* | Password. |
| `DB_DRIVER` | `auto` | `auto` (native under FrankenPHP worker mode or RoadRunner, PDO otherwise), `native`, or `pdo`. |
| `DB_CHARSET` | `utf8mb4` (MySQL) | Connection charset. |
| `DB_COLLATION` | — | MySQL collation (`SET NAMES ... COLLATE`). |
| `DB_SSLMODE` | — | `disable`/`require`/`verify-ca`/`verify-full` on every driver; libpq additionally accepts `allow`/`prefer`. |
| `DB_SSL_CA` | — | CA bundle path for the verify modes. |
| `DB_SSL_CERT` | — | Client certificate for mutual TLS; requires `DB_SSL_KEY`. |
| `DB_SSL_KEY` | — | Client private key; requires `DB_SSL_CERT`. Postgres requires `0600` permissions. |
| `DB_CONNECT_TIMEOUT` | — | Seconds. |
| `DB_APP_NAME` | — | Postgres `application_name`. |
| `DB_COMPRESSION` | — | MySQL protocol compression. |
| `DB_MAX_CONNECTIONS` | `8` | Async drivers' pool width — per worker thread under FrankenPHP, per worker process under RoadRunner. |
| `DB_WARM_CONNECTIONS` | `0` | Connections opened at boot instead of first use — load-bearing for the mysqli driver under worker mode. |

Scoped keys follow the named-connection convention — the connection
name inserts after the first segment: `DB_HOST` + `reporting` →
`DB_REPORTING_HOST`. Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/database-bridge
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
and [`kinetis/persistence`](https://github.com/kinetis-dev/persistence),
plus the extension for the driver you use: `ext-mysqli`, `ext-pgsql`
(with `ext-sockets`), `ext-pdo_mysql` or `ext-pdo_pgsql`. Full
documentation:
[kinetis.dev/docs/persistence.html](https://kinetis.dev/docs/persistence.html).

## License

MIT — see [LICENSE](LICENSE).
