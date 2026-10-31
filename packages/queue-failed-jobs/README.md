<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/queue-failed-jobs</strong>
  <br>
  <strong>Failed jobs kept in MySQL/Postgres for kinetis/queue, with list, retry, forget and prune commands</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/queue-failed-jobs"><img src="https://img.shields.io/packagist/v/kinetis/queue-failed-jobs?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue-failed-jobs"><img src="https://img.shields.io/packagist/dt/kinetis/queue-failed-jobs" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/queue-failed-jobs"><img src="https://img.shields.io/packagist/php-v/kinetis/queue-failed-jobs" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/queue-failed-jobs"><img src="https://img.shields.io/packagist/l/kinetis/queue-failed-jobs" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Records every job the [`kinetis/queue`](https://github.com/kinetis-dev/queue)
worker gives up on in a `kinetis_failed_jobs` table, before the backend
deletes it, for every queue backend and every named queue connection.
Installing the package turns recording on. With it installed,
`queue:work` depends on the failed-jobs database and stops when that
database refuses a write, leaving the job on its queue.

```sh
vendor/bin/kinetis queue:failed --queue=mail
vendor/bin/kinetis queue:retry 3f9c0b2a64d14e8f9a0c51d7e2b46a18
vendor/bin/kinetis queue:retry --all --bound
vendor/bin/kinetis queue:forget 3f9c0b2a64d14e8f9a0c51d7e2b46a18
vendor/bin/kinetis queue:prune --hours=168
```

## The store needs a table

Copy the stub matching your database into your
[`kinetis/migrations`](https://github.com/kinetis-dev/migrations)
`migrations/` directory — `migrations/<name>/` when the records live on a
named database connection — with a timestamp prefix, and run
`vendor/bin/kinetis migrate`:

```text
vendor/kinetis/queue-failed-jobs/resources/migrations/create_kinetis_failed_jobs_table.mysql.php.stub
vendor/kinetis/queue-failed-jobs/resources/migrations/create_kinetis_failed_jobs_table.pgsql.php.stub
```

The table holds each job's arguments in full, `#[Sensitive]` values
included, because a retry rebuilds the job from them. Give it the access
controls the queue's own storage has. No command prints a sensitive
value.

## Configuration

```text
FAILED_JOBS_DB_CONNECTION_NAME=default
```

The database connection the store uses: the
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
`DB_*` keys for `default`, which an unset or blank value selects, and
`DB_{NAME}_*` for any other name.

## Delivery

A job the worker gives up on is held by the backend, recorded, or both —
never neither. A crash between recording and deleting, or an
interrupted `queue:retry`, can leave a duplicate record or push a job
twice, within the queue's at-least-once contract. See
[kinetis.dev/docs/queue-failed-jobs.html](https://kinetis.dev/docs/queue-failed-jobs.html#duplicate-records).

## Installation

```sh
composer require kinetis/queue-failed-jobs
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework), [`kinetis/queue`](https://github.com/kinetis-dev/queue),
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence), and
[`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge). Full documentation:
[kinetis.dev/docs/queue-failed-jobs.html](https://kinetis.dev/docs/queue-failed-jobs.html).

## License

MIT — see [LICENSE](LICENSE).
