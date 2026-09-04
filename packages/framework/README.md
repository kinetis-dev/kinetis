<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>The non-blocking PHP framework for API-first applications</strong>
  <br>
  <strong>Runs on persistent-worker runtimes, with AI agents as first-class API clients</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/framework"><img src="https://img.shields.io/packagist/v/kinetis/framework?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/framework"><img src="https://img.shields.io/packagist/dt/kinetis/framework" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/framework"><img src="https://img.shields.io/packagist/php-v/kinetis/framework" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/framework"><img src="https://img.shields.io/packagist/l/kinetis/framework" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

This is the [Kinetis](https://kinetis.dev/) framework itself, developed
in the [kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis)
monorepo — every satellite package below is split out of that same
monorepo into its own repository.

Kinetis targets the same class of application a modern API is expected to
be: typed request and response contracts, OpenAPI described automatically
rather than hand-maintained, genuinely non-blocking under real concurrent
load, and native support for AI agents as first-class API clients, not an
afterthought bolted on later.

Attribute-driven routing and validation replace config files to keep in
sync with the code:

```php
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class UserController
{
    #[Post('/users', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }
}
```

Request-scope isolation is a hard, enforced guarantee instead of a
convention. And speed comes from two different places, not one: real
Fiber-based concurrency over a Revolt event loop instead of blocking calls
wrapped in the appearance of async, and ahead-of-time compilation
(`kinetis build`) so a request in production pays reflection's cost once,
not on every single one it serves.

Kinetis is designed around [FrankenPHP](https://frankenphp.dev)'s worker
mode — a PHP process that boots once and serves thousands of requests — as
its primary target, though the same application code also runs correctly
under classic PHP-FPM, RoadRunner, and AWS Lambda (via Bref): the runtime
is an adapter Kinetis talks to, not an assumption baked into the
framework itself.

## Highlights

- **Runtime-adapter architecture** — the same `public/index.php` runs
  unmodified under FrankenPHP, PHP-FPM, RoadRunner, or AWS Lambda;
  `RuntimeDetector` picks the right adapter with zero configuration.
- **A two-tier container** (`AppScope` + `RequestScope`) that makes
  request-scope isolation an enforced guarantee, not a convention — backed
  by a PHPStan rule that bans stray `static` state.
- **Attribute-based routing, validation, and OpenAPI** — typed DTOs
  validated before your controller ever runs, with a zero-config Swagger
  UI at `/openapi`.
- **Fiber-based concurrency** (`Kinetis\Async\concurrently()`) over Revolt,
  plus Revolt-native MySQL, Postgres, and Redis clients — no blocking
  drivers, no hand-rolled wire protocols.
- **A native MCP server** ([`kinetis/mcp`](https://github.com/kinetis-dev/mcp)) — stdio and Streamable HTTP
  transports, so an AI agent can call your application's own tools and
  resources the same way it calls anything else. Installing the package
  is the whole setup.
- **A PSR-14 event dispatcher** — attribute-driven listener registration,
  with `ShouldQueue` for deferring a listener onto a queue instead of
  running it inline. Core dispatches `Kinetis\Console\Events\CommandFailed`
  when a `vendor/bin/kinetis` command throws; see
  [kinetis.dev/docs/events.html](https://kinetis.dev/docs/events.html)
  for the full list across every package.
- **Production AOT caching** — routes, validation plans, commands, and
  event listeners compiled once (`bin/kinetis build`). A boot-and-die runtime
  skips re-registering everything from scratch on every request; even a
  persistent worker skips the per-dispatch reflection cost that otherwise
  recurs on every single request regardless.

## Installation

```sh
composer require kinetis/framework
```

Requires PHP 8.4 or later. See the [Tutorial](https://kinetis.dev/docs/tutorial.html)
for a complete walkthrough, including running under FrankenPHP.

## Configuration

Core reads from the environment (or a `.env` file at the project root)
via `Kinetis\Config`:

| Key | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | `development` — the exact name, ignoring case — selects live discovery; unset or any other name selects the AOT cache. |
| `MAX_BODY_SIZE` | `2097152` | Request-body cap in bytes, enforced against declared `Content-Length` and actual bytes read. |
| `ROUTE_DISCOVERY_PATHS` | — | Restricts the HTTP-controller scan to comma-separated sub-paths, relative to each PSR-4 base directory. |
| `COMMAND_DISCOVERY_PATHS` | — | The same, for CLI commands. |
| `MIDDLEWARE_DISCOVERY_PATHS` | — | The same, for global middleware and middleware groups. |
| `LISTENER_DISCOVERY_PATHS` | — | The same, for event listeners. |

Each package documents its own keys (`DB_*`, `REDIS_*`, `QUEUE_*`, ...)
in its own README; the full reference across every package is at
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Packages

Kinetis core (`kinetis/framework`) ships as a single package. A few optional pieces live as separate packages, each with its own
dependencies and its own repository — every one links to its own
documentation from its own README:

| Package | What it adds |
|---|---|
| [`kinetis/persistence`](https://github.com/kinetis-dev/persistence) | Request-scoped SQL transaction safety net (`TransactionGuard`) and connection-pool factory for MySQL/Postgres |
| [`kinetis/cache-redis`](https://github.com/kinetis-dev/cache-redis) | Redis-backed PSR-16 `CacheInterface` — single-node, Cluster, and TLS |
| [`kinetis/auth`](https://github.com/kinetis-dev/auth) | Opaque Bearer-token authentication middleware |
| [`kinetis/auth-jwt`](https://github.com/kinetis-dev/auth-jwt) | Stateless JWT authentication (HS256/RS256), with optional per-token revocation |
| [`kinetis/authorization`](https://github.com/kinetis-dev/authorization) | Unopinionated ability-based authorization — `Gate` wraps any callable Policy check |
| [`kinetis/migrations`](https://github.com/kinetis-dev/migrations) | A thin database migration runner — raw SQL `up()`/`down()`, no schema-diffing |
| [`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder) | A thin, parameterized SQL query builder for MySQL/Postgres — not an ORM |
| [`kinetis/queue`](https://github.com/kinetis-dev/queue) | A backend-agnostic background job queue — every backend lives in its own separate package |
| [`kinetis/queue-redis`](https://github.com/kinetis-dev/queue-redis) | A Redis backend for [`kinetis/queue`](https://github.com/kinetis-dev/queue) |
| [`kinetis/queue-sql`](https://github.com/kinetis-dev/queue-sql) | A MySQL/Postgres backend for [`kinetis/queue`](https://github.com/kinetis-dev/queue) |
| [`kinetis/queue-sqs`](https://github.com/kinetis-dev/queue-sqs) | An Amazon SQS backend for [`kinetis/queue`](https://github.com/kinetis-dev/queue) — non-blocking via [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) |
| [`kinetis/queue-rabbitmq`](https://github.com/kinetis-dev/queue-rabbitmq) | A RabbitMQ backend for [`kinetis/queue`](https://github.com/kinetis-dev/queue) |
| [`kinetis/session`](https://github.com/kinetis-dev/session) | Cookie-backed sessions and CSRF protection — file, cache, or SQL storage |
| [`kinetis/storage`](https://github.com/kinetis-dev/storage) | File storage on `League\Flysystem` — a genuinely non-blocking, `Amp\File`-backed local adapter |
| [`kinetis/storage-s3`](https://github.com/kinetis-dev/storage-s3) | S3 (and S3-compatible) storage for [`kinetis/storage`](https://github.com/kinetis-dev/storage)'s `FILESYSTEM_DRIVER=s3` — non-blocking via [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) |
| [`kinetis/mailer`](https://github.com/kinetis-dev/mailer) | Mail sending via `Symfony\Component\Mailer` — API-based transports non-blocking via [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) |
| [`kinetis/broadcasting`](https://github.com/kinetis-dev/broadcasting) | Real-time broadcasting over the Pusher Channels protocol — private/presence channel authorization, non-blocking via [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) |
| [`kinetis/search-opensearch`](https://github.com/kinetis-dev/search-opensearch) | Non-blocking OpenSearch client construction, via [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) |
| [`kinetis/telemetry`](https://github.com/kinetis-dev/telemetry) | OpenTelemetry tracing — request spans, SQL/queue instrumentation |
| [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client) | A Revolt-native Symfony `HttpClientInterface` — usable standalone, no Kinetis required |
| [`kinetis/aws-sigv4`](https://github.com/kinetis-dev/aws-sigv4) | A PSR-18 decorator signing requests with AWS Signature V4 — usable standalone, no Kinetis required |
| [`kinetis/mcp`](https://github.com/kinetis-dev/mcp) | The native Model Context Protocol server — stdio and Streamable HTTP |
| [`kinetis/bref-adapter`](https://github.com/kinetis-dev/bref-adapter) | AWS Lambda (Bref) runtime adapter, for multipart/form-data support Lambda specifically needs |
| [`kinetis/roadrunner-adapter`](https://github.com/kinetis-dev/roadrunner-adapter) | RoadRunner runtime adapter — a persistent worker over RoadRunner's own Goridge/`PSR7Worker` protocol |

## Documentation

The full documentation is hosted at [kinetis.dev/docs](https://kinetis.dev/docs/)
— start with the [Tutorial](https://kinetis.dev/docs/tutorial.html)
or [Core Concepts](https://kinetis.dev/docs/core-concepts.html).

## Development

Kinetis is built and tested exclusively through Docker — running
`php`/`composer` directly on the host is deliberately avoided throughout
this codebase's own development:

```sh
# tests
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php vendor/bin/phpunit

# static analysis (PHPStan level 8)
docker run --rm -v "$PWD":/app -w /app php:8.4-cli-alpine php vendor/bin/phpstan analyse --no-progress
```

## License

Kinetis is open-sourced under the [MIT license](LICENSE).
