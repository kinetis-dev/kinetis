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

Every push runs the full quality pipeline for all packages: PHPUnit,
PHPStan (level 8), Psalm (including taint analysis), Infection mutation
testing with per-package MSI gates, Semgrep, SonarCloud analysis, and
integration tests against real backends — MySQL, MariaDB, Postgres,
Redis (single-node and Cluster, TLS), RabbitMQ, and LocalStack
(SQS/S3).

## Packages

Each directory under [`packages/`](packages) is its own independent,
installable Composer package with its own `README.md` and test suite:

| Package | What it is | Version |
|---|---|---|
| [`kinetis/framework`](packages/framework) | The framework itself — routing, validation, DI, concurrency | [![Version](https://img.shields.io/packagist/v/kinetis/framework?label=)](https://packagist.org/packages/kinetis/framework) |
| [`kinetis/persistence`](packages/persistence) | Native async DB drivers (mysqli, pgsql, PDO) and transaction safety nets | [![Version](https://img.shields.io/packagist/v/kinetis/persistence?label=)](https://packagist.org/packages/kinetis/persistence) |
| [`kinetis/cache-redis`](packages/cache-redis) | PSR-16 cache backed by Redis, single-node or Cluster, TLS included | [![Version](https://img.shields.io/packagist/v/kinetis/cache-redis?label=)](https://packagist.org/packages/kinetis/cache-redis) |
| [`kinetis/query-builder`](packages/query-builder) | A thin, parameterized SQL query builder | [![Version](https://img.shields.io/packagist/v/kinetis/query-builder?label=)](https://packagist.org/packages/kinetis/query-builder) |
| [`kinetis/migrations`](packages/migrations) | A thin database migration runner | [![Version](https://img.shields.io/packagist/v/kinetis/migrations?label=)](https://packagist.org/packages/kinetis/migrations) |
| [`kinetis/queue`](packages/queue) | A backend-agnostic background job queue (Redis, SQL) | [![Version](https://img.shields.io/packagist/v/kinetis/queue?label=)](https://packagist.org/packages/kinetis/queue) |
| [`kinetis/queue-sqs`](packages/queue-sqs) | Amazon SQS backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-sqs?label=)](https://packagist.org/packages/kinetis/queue-sqs) |
| [`kinetis/queue-rabbitmq`](packages/queue-rabbitmq) | RabbitMQ backend for kinetis/queue | [![Version](https://img.shields.io/packagist/v/kinetis/queue-rabbitmq?label=)](https://packagist.org/packages/kinetis/queue-rabbitmq) |
| [`kinetis/auth`](packages/auth) | Bearer/opaque-token authentication middleware | [![Version](https://img.shields.io/packagist/v/kinetis/auth?label=)](https://packagist.org/packages/kinetis/auth) |
| [`kinetis/auth-jwt`](packages/auth-jwt) | Stateless JWT authentication (HS256/RS256, revocation, JWKS) | [![Version](https://img.shields.io/packagist/v/kinetis/auth-jwt?label=)](https://packagist.org/packages/kinetis/auth-jwt) |
| [`kinetis/session`](packages/session) | Cookie-backed sessions and CSRF protection | [![Version](https://img.shields.io/packagist/v/kinetis/session?label=)](https://packagist.org/packages/kinetis/session) |
| [`kinetis/storage`](packages/storage) | File storage on League\Flysystem, non-blocking local driver | [![Version](https://img.shields.io/packagist/v/kinetis/storage?label=)](https://packagist.org/packages/kinetis/storage) |
| [`kinetis/storage-s3`](packages/storage-s3) | Non-blocking S3 (and S3-compatible) file storage | [![Version](https://img.shields.io/packagist/v/kinetis/storage-s3?label=)](https://packagist.org/packages/kinetis/storage-s3) |
| [`kinetis/revolt-http-client`](packages/revolt-http-client) | An ergonomic HTTP client and Revolt-native Symfony HttpClient transport | [![Version](https://img.shields.io/packagist/v/kinetis/revolt-http-client?label=)](https://packagist.org/packages/kinetis/revolt-http-client) |
| [`kinetis/aws-sigv4`](packages/aws-sigv4) | PSR-18 decorator signing requests with AWS Signature V4 | [![Version](https://img.shields.io/packagist/v/kinetis/aws-sigv4?label=)](https://packagist.org/packages/kinetis/aws-sigv4) |
| [`kinetis/mailer`](packages/mailer) | Non-blocking mail sending over symfony/mailer transports | [![Version](https://img.shields.io/packagist/v/kinetis/mailer?label=)](https://packagist.org/packages/kinetis/mailer) |
| [`kinetis/search-opensearch`](packages/search-opensearch) | Non-blocking OpenSearch client construction | [![Version](https://img.shields.io/packagist/v/kinetis/search-opensearch?label=)](https://packagist.org/packages/kinetis/search-opensearch) |
| [`kinetis/telemetry`](packages/telemetry) | OpenTelemetry tracing | [![Version](https://img.shields.io/packagist/v/kinetis/telemetry?label=)](https://packagist.org/packages/kinetis/telemetry) |
| [`kinetis/mcp`](packages/mcp) | The native Model Context Protocol server — stdio and HTTP | [![Version](https://img.shields.io/packagist/v/kinetis/mcp?label=)](https://packagist.org/packages/kinetis/mcp) |
| [`kinetis/bref-adapter`](packages/bref-adapter) | AWS Lambda (Bref) runtime adapter | [![Version](https://img.shields.io/packagist/v/kinetis/bref-adapter?label=)](https://packagist.org/packages/kinetis/bref-adapter) |
| [`kinetis/roadrunner-adapter`](packages/roadrunner-adapter) | RoadRunner runtime adapter | [![Version](https://img.shields.io/packagist/v/kinetis/roadrunner-adapter?label=)](https://packagist.org/packages/kinetis/roadrunner-adapter) |
| [`kinetis/skeleton`](packages/skeleton) | The smallest runnable Kinetis application — a starting point | [![Version](https://img.shields.io/packagist/v/kinetis/skeleton?label=)](https://packagist.org/packages/kinetis/skeleton) |
| [`kinetis/pingpong`](packages/pingpong) | A full demo app: MySQL, queue, events, cron, live WebSocket updates | [![Version](https://img.shields.io/packagist/v/kinetis/pingpong?label=)](https://packagist.org/packages/kinetis/pingpong) |

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
