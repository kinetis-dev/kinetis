<p align="center">
  <img src="docs/_static/logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>The Kinetis monorepo</strong>
</p>

<p align="center">
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/integration.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/integration.yml/badge.svg" alt="Integration"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/infection.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/infection.yml/badge.svg" alt="Infection"></a>
  <a href="https://sonarcloud.io/summary/overall?id=kinetis-dev_kinetis"><img src="https://sonarcloud.io/api/project_badges/measure?project=kinetis-dev_kinetis&metric=alert_status" alt="Quality Gate"></a>
  <a href="https://sonarcloud.io/summary/overall?id=kinetis-dev_kinetis"><img src="https://sonarcloud.io/api/project_badges/measure?project=kinetis-dev_kinetis&metric=coverage" alt="Coverage"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/php-%E2%89%A58.4-777BB4" alt="PHP 8.4+"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue" alt="MIT License"></a>
</p>

---

This repository is the development monorepo for
[Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications. It hosts the shared CI/CD pipeline and the
documentation site for every package in the ecosystem.

Every change to a package runs the full quality pipeline: PHPUnit,
PHPStan (level 8), Psalm (including taint analysis), Infection mutation
testing with per-package MSI gates, Semgrep, SonarQube Cloud analysis,
and integration tests against real backends — MySQL, MariaDB and
Postgres (TLS and mutual TLS included), Redis single-node and Cluster,
RabbitMQ, OpenSearch, Mailpit, and LocalStack (SQS/S3), plus the runtime
adapters against real FrankenPHP, PHP-FPM and RoadRunner servers. On a
pull request and on a push to `main` alike, the three heaviest workflows
— integration, mutation testing and the SonarQube scan — are filtered to
`packages/**`, so a docs- or tooling-only change runs the rest and skips
them. See
[the CI appendix](https://kinetis.dev/docs/appendix-ci.html) for what
each workflow checks.

## Packages

Each directory under [`packages/`](packages) is its own independent,
installable Composer package with its own `README.md` and test suite:

| Package | What it is | Version |
|---|---|---|
| [`kinetis/framework`](https://github.com/kinetis-dev/framework) | The framework itself — routing, validation, DI, concurrency | [![Version](https://img.shields.io/packagist/v/kinetis/framework?label=)](https://packagist.org/packages/kinetis/framework) |
| [`kinetis/persistence`](https://github.com/kinetis-dev/persistence) | Native async DB drivers (mysqli, pgsql, PDO) and transaction safety nets, usable standalone | [![Version](https://img.shields.io/packagist/v/kinetis/persistence?label=)](https://packagist.org/packages/kinetis/persistence) |
| [`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge) | Kinetis wiring for kinetis/persistence and kinetis/orm — `DB_*` connections, SQL telemetry, a lazy request-scoped `TransactionGuard` and `EntityManager` | [![Version](https://img.shields.io/packagist/v/kinetis/database-bridge?label=)](https://packagist.org/packages/kinetis/database-bridge) |
| [`kinetis/redis`](https://github.com/kinetis-dev/redis) | Non-replaying, deadline-bounded Redis transport with Cluster routing | [![Version](https://img.shields.io/packagist/v/kinetis/redis?label=)](https://packagist.org/packages/kinetis/redis) |
| [`kinetis/cache-redis`](https://github.com/kinetis-dev/cache-redis) | PSR-16 cache backed by Redis, single-node or Cluster, TLS included | [![Version](https://img.shields.io/packagist/v/kinetis/cache-redis?label=)](https://packagist.org/packages/kinetis/cache-redis) |
| [`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder) | A thin, parameterized SQL query builder | [![Version](https://img.shields.io/packagist/v/kinetis/query-builder?label=)](https://packagist.org/packages/kinetis/query-builder) |
| [`kinetis/orm`](https://github.com/kinetis-dev/orm) | A data mapper — attributed entities, typed repositories, and a unit of work with change tracking and a transactional flush | [![Version](https://img.shields.io/packagist/v/kinetis/orm?label=)](https://packagist.org/packages/kinetis/orm) |
| [`kinetis/views`](https://github.com/kinetis-dev/views) | Engine-neutral view rendering and asset URLs | [![Version](https://img.shields.io/packagist/v/kinetis/views?label=)](https://packagist.org/packages/kinetis/views) |
| [`kinetis/views-php`](https://github.com/kinetis-dev/views-php) | Pure PHP templates for kinetis/views | [![Version](https://img.shields.io/packagist/v/kinetis/views-php?label=)](https://packagist.org/packages/kinetis/views-php) |
| [`kinetis/views-latte`](https://github.com/kinetis-dev/views-latte) | Latte templates for kinetis/views | [![Version](https://img.shields.io/packagist/v/kinetis/views-latte?label=)](https://packagist.org/packages/kinetis/views-latte) |
| [`kinetis/views-twig`](https://github.com/kinetis-dev/views-twig) | Twig templates for kinetis/views | [![Version](https://img.shields.io/packagist/v/kinetis/views-twig?label=)](https://packagist.org/packages/kinetis/views-twig) |
| [`kinetis/migrations`](https://github.com/kinetis-dev/migrations) | A thin database migration runner | [![Version](https://img.shields.io/packagist/v/kinetis/migrations?label=)](https://packagist.org/packages/kinetis/migrations) |
| [`kinetis/queue`](https://github.com/kinetis-dev/queue) | A backend-agnostic background job queue — every backend lives in its own separate package | [![Version](https://img.shields.io/packagist/v/kinetis/queue?label=)](https://packagist.org/packages/kinetis/queue) |
| [`kinetis/queue-redis`](https://github.com/kinetis-dev/queue-redis) | Redis backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-redis?label=)](https://packagist.org/packages/kinetis/queue-redis) |
| [`kinetis/queue-sql`](https://github.com/kinetis-dev/queue-sql) | MySQL/Postgres backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-sql?label=)](https://packagist.org/packages/kinetis/queue-sql) |
| [`kinetis/queue-sqs`](https://github.com/kinetis-dev/queue-sqs) | Amazon SQS backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-sqs?label=)](https://packagist.org/packages/kinetis/queue-sqs) |
| [`kinetis/queue-rabbitmq`](https://github.com/kinetis-dev/queue-rabbitmq) | RabbitMQ backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-rabbitmq?label=)](https://packagist.org/packages/kinetis/queue-rabbitmq) |
| [`kinetis/auth`](https://github.com/kinetis-dev/auth) | Bearer/opaque-token authentication middleware | [![Version](https://img.shields.io/packagist/v/kinetis/auth?label=)](https://packagist.org/packages/kinetis/auth) |
| [`kinetis/auth-jwt`](https://github.com/kinetis-dev/auth-jwt) | Stateless JWT authentication (HS256/RS256, revocation, JWKS) | [![Version](https://img.shields.io/packagist/v/kinetis/auth-jwt?label=)](https://packagist.org/packages/kinetis/auth-jwt) |
| [`kinetis/session`](https://github.com/kinetis-dev/session) | Cookie-backed sessions and CSRF protection | [![Version](https://img.shields.io/packagist/v/kinetis/session?label=)](https://packagist.org/packages/kinetis/session) |
| [`kinetis/authorization`](https://github.com/kinetis-dev/authorization) | Unopinionated ability-based authorization — Gate wraps any callable Policy check | [![Version](https://img.shields.io/packagist/v/kinetis/authorization?label=)](https://packagist.org/packages/kinetis/authorization) |
| [`kinetis/storage`](https://github.com/kinetis-dev/storage) | File storage on League\Flysystem, Amp\File-backed local driver | [![Version](https://img.shields.io/packagist/v/kinetis/storage?label=)](https://packagist.org/packages/kinetis/storage) |
| [`kinetis/storage-s3`](https://github.com/kinetis-dev/storage-s3) | Non-blocking S3 (and S3-compatible) file storage | [![Version](https://img.shields.io/packagist/v/kinetis/storage-s3?label=)](https://packagist.org/packages/kinetis/storage-s3) |
| [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) | An ergonomic HTTP client and Revolt-native Symfony HttpClient transport | [![Version](https://img.shields.io/packagist/v/kinetis/revolt-http-client?label=)](https://packagist.org/packages/kinetis/revolt-http-client) |
| [`kinetis/aws-sigv4`](https://github.com/kinetis-dev/aws-sigv4) | PSR-18 client signing requests with AWS Signature V4 | [![Version](https://img.shields.io/packagist/v/kinetis/aws-sigv4?label=)](https://packagist.org/packages/kinetis/aws-sigv4) |
| [`kinetis/mailer`](https://github.com/kinetis-dev/mailer) | Non-blocking mail sending over symfony/mailer transports | [![Version](https://img.shields.io/packagist/v/kinetis/mailer?label=)](https://packagist.org/packages/kinetis/mailer) |
| [`kinetis/search`](https://github.com/kinetis-dev/search) | The shared search transport and one engine-neutral client for both engines | [![Version](https://img.shields.io/packagist/v/kinetis/search?label=)](https://packagist.org/packages/kinetis/search) |
| [`kinetis/search-opensearch`](https://github.com/kinetis-dev/search-opensearch) | Non-blocking OpenSearch client construction | [![Version](https://img.shields.io/packagist/v/kinetis/search-opensearch?label=)](https://packagist.org/packages/kinetis/search-opensearch) |
| [`kinetis/search-elasticsearch`](https://github.com/kinetis-dev/search-elasticsearch) | Non-blocking Elasticsearch client construction | [![Version](https://img.shields.io/packagist/v/kinetis/search-elasticsearch?label=)](https://packagist.org/packages/kinetis/search-elasticsearch) |
| [`kinetis/broadcasting`](https://github.com/kinetis-dev/broadcasting) | Real-time broadcasting over the Pusher Channels protocol, with private/presence channel authorization | [![Version](https://img.shields.io/packagist/v/kinetis/broadcasting?label=)](https://packagist.org/packages/kinetis/broadcasting) |
| [`kinetis/telemetry`](https://github.com/kinetis-dev/telemetry) | OpenTelemetry tracing | [![Version](https://img.shields.io/packagist/v/kinetis/telemetry?label=)](https://packagist.org/packages/kinetis/telemetry) |
| [`kinetis/mcp`](https://github.com/kinetis-dev/mcp) | The native Model Context Protocol server — stdio and HTTP | [![Version](https://img.shields.io/packagist/v/kinetis/mcp?label=)](https://packagist.org/packages/kinetis/mcp) |
| [`kinetis/mcp-docs`](https://github.com/kinetis-dev/mcp-docs) | A standalone MCP server serving the Kinetis documentation — no Kinetis dependency | [![Version](https://img.shields.io/packagist/v/kinetis/mcp-docs?label=)](https://packagist.org/packages/kinetis/mcp-docs) |
| [`kinetis/orbitron`](https://github.com/kinetis-dev/orbitron) | A development-only construction harness — portable Kinetis context and the installed-package inventory, for the coding agent you bring | [![Version](https://img.shields.io/packagist/v/kinetis/orbitron?label=)](https://packagist.org/packages/kinetis/orbitron) |
| [`kinetis/bref-adapter`](https://github.com/kinetis-dev/bref-adapter) | AWS Lambda (Bref) runtime adapter | [![Version](https://img.shields.io/packagist/v/kinetis/bref-adapter?label=)](https://packagist.org/packages/kinetis/bref-adapter) |
| [`kinetis/roadrunner-adapter`](https://github.com/kinetis-dev/roadrunner-adapter) | RoadRunner runtime adapter | [![Version](https://img.shields.io/packagist/v/kinetis/roadrunner-adapter?label=)](https://packagist.org/packages/kinetis/roadrunner-adapter) |
| [`kinetis/skeleton`](https://github.com/kinetis-dev/skeleton) | The smallest runnable Kinetis application — a starting point | [![Version](https://img.shields.io/packagist/v/kinetis/skeleton?label=)](https://packagist.org/packages/kinetis/skeleton) |
| [`kinetis/pingpong`](https://github.com/kinetis-dev/pingpong) | A full demo app: MySQL, queue, events, cron, an MCP tool, live WebSocket updates | [![Version](https://img.shields.io/packagist/v/kinetis/pingpong?label=)](https://packagist.org/packages/kinetis/pingpong) |

## Documentation

[`docs/`](docs) is the Sphinx source for
[kinetis.dev/docs](https://kinetis.dev/docs/), covering every package
in one place. Build it locally with:

```sh
docker run --rm -v "$PWD/docs":/app -w /app python:3.12-slim \
    bash -c "pip install -q -r requirements.txt && sphinx-build -M html . _build -W --keep-going"
```

(the same command CI runs — `-W` fails the build on any Sphinx warning,
not just a hard error).

## License

Kinetis is open-sourced under the [MIT license](LICENSE).
