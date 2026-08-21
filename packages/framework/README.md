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
- **A native MCP server** (`kinetis/mcp`) — stdio and Streamable HTTP
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
| `APP_ENV` | `production` | `development` or `production` — selects live discovery vs. the AOT cache. Anything unrecognized means `production`. |
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
dependencies:

| Package | What it adds |
|---|---|
| [`kinetis/persistence`](https://kinetis.dev/docs/persistence.html) | Request-scoped SQL transaction safety net (`TransactionGuard`) and connection-pool factory for MySQL/Postgres |
| [`kinetis/cache-redis`](https://kinetis.dev/docs/persistence.html) | Redis-backed PSR-16 `CacheInterface` — single-node, Cluster, and TLS |
| [`kinetis/auth`](https://kinetis.dev/docs/auth.html) | Opaque Bearer-token authentication middleware |
| [`kinetis/auth-jwt`](https://kinetis.dev/docs/auth-jwt.html) | Stateless JWT authentication (HS256/RS256), with optional per-token revocation |
| [`kinetis/migrations`](https://kinetis.dev/docs/migrations.html) | A thin database migration runner — raw SQL `up()`/`down()`, no schema-diffing |
| [`kinetis/query-builder`](https://kinetis.dev/docs/query-builder.html) | A thin, parameterized SQL query builder for MySQL/Postgres — not an ORM |
| [`kinetis/queue`](https://kinetis.dev/docs/queue.html) | A backend-agnostic background job queue — every backend lives in its own separate package |
| [`kinetis/queue-redis`](https://kinetis.dev/docs/queue-redis.html) | A Redis backend for `kinetis/queue` |
| [`kinetis/queue-sql`](https://kinetis.dev/docs/queue-sql.html) | A MySQL/Postgres backend for `kinetis/queue` |
| [`kinetis/queue-sqs`](https://kinetis.dev/docs/queue-sqs.html) | An Amazon SQS backend for `kinetis/queue` — non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/storage`](https://kinetis.dev/docs/storage.html) | File storage on `League\Flysystem` — a genuinely non-blocking, `Amp\File`-backed local adapter |
| [`kinetis/storage-s3`](https://kinetis.dev/docs/storage-s3.html) | S3 (and S3-compatible) storage for `kinetis/storage`'s `FILESYSTEM_DRIVER=s3` — non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/mailer`](https://kinetis.dev/docs/mailer.html) | Mail sending via `Symfony\Component\Mailer` — API-based transports non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/revolt-http-client`](https://kinetis.dev/docs/revolt-http-client.html) | A Revolt-native Symfony `HttpClientInterface` — usable standalone, no Kinetis required |
| `kinetis/bref-adapter` | AWS Lambda (Bref) runtime adapter, for multipart/form-data support Lambda specifically needs |
| `kinetis/roadrunner-adapter` | RoadRunner runtime adapter — a persistent worker over RoadRunner's own Goridge/`PSR7Worker` protocol |

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
