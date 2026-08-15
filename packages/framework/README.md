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
under classic PHP-FPM and AWS Lambda (via Bref): the runtime is an adapter
Kinetis talks to, not an assumption baked into the framework itself.

## Highlights

- **Runtime-adapter architecture** — the same `public/index.php` runs
  unmodified under FrankenPHP, PHP-FPM, or AWS Lambda; `RuntimeDetector`
  picks the right adapter with zero configuration.
- **A two-tier container** (`AppScope` + `RequestScope`) that makes
  request-scope isolation an enforced guarantee, not a convention — backed
  by a PHPStan rule that bans stray `static` state.
- **Attribute-based routing, validation, and OpenAPI** — typed DTOs
  validated before your controller ever runs, with a zero-config Swagger
  UI at `/docs`.
- **Fiber-based concurrency** (`Kinetis\Async\concurrently()`) over Revolt,
  plus Revolt-native MySQL, Postgres, and Redis clients — no blocking
  drivers, no hand-rolled wire protocols.
- **A native MCP server** — stdio and Streamable HTTP transports, so an AI
  agent can call your application's own tools and resources the same way
  it calls anything else.
- **A PSR-14 event dispatcher** — attribute-driven listener registration,
  with `ShouldQueue` for deferring a listener onto a queue instead of
  running it inline.
- **Production AOT caching** — routes, validation plans, and MCP
  registrations compiled once (`bin/kinetis build`). A boot-and-die runtime
  skips re-registering everything from scratch on every request; even a
  persistent worker skips the per-dispatch reflection cost that otherwise
  recurs on every single request regardless.

## Installation

```sh
composer require kinetis/framework
```

Requires PHP 8.4 or later. See [Getting Started](https://docs.kinetis.dev/getting-started.html)
for a complete walkthrough, including running under FrankenPHP.

## Packages

Kinetis core (`kinetis/framework`) ships as a single package. A few optional pieces live as separate packages, each with its own
dependencies:

| Package | What it adds |
|---|---|
| [`kinetis/persistence`](https://docs.kinetis.dev/persistence.html) | Request-scoped SQL transaction safety net (`TransactionGuard`) and connection-pool factory for MySQL/Postgres |
| [`kinetis/cache-redis`](https://docs.kinetis.dev/persistence.html) | Redis-backed PSR-16 `CacheInterface` — single-node, Cluster, and TLS |
| [`kinetis/auth`](https://docs.kinetis.dev/auth.html) | Opaque Bearer-token authentication middleware |
| [`kinetis/auth-jwt`](https://docs.kinetis.dev/auth-jwt.html) | Stateless JWT authentication (HS256/RS256), with optional per-token revocation |
| [`kinetis/migrations`](https://docs.kinetis.dev/migrations.html) | A thin database migration runner — raw SQL `up()`/`down()`, no schema-diffing |
| [`kinetis/query-builder`](https://docs.kinetis.dev/query-builder.html) | A thin, parameterized SQL query builder for MySQL/Postgres — not an ORM |
| [`kinetis/queue`](https://docs.kinetis.dev/queue.html) | A backend-agnostic background job queue — Redis and SQL backends included |
| [`kinetis/queue-sqs`](https://docs.kinetis.dev/queue-sqs.html) | An Amazon SQS backend for `kinetis/queue` — non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/storage`](https://docs.kinetis.dev/storage.html) | File storage on `League\Flysystem` — a genuinely non-blocking, `Amp\File`-backed local adapter |
| [`kinetis/storage-s3`](https://docs.kinetis.dev/storage-s3.html) | S3 (and S3-compatible) storage for `kinetis/storage`'s `FILESYSTEM_DRIVER=s3` — non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/mailer`](https://docs.kinetis.dev/mailer.html) | Mail sending via `Symfony\Component\Mailer` — API-based transports non-blocking via `kinetis/revolt-http-client` |
| [`kinetis/revolt-http-client`](https://docs.kinetis.dev/revolt-http-client.html) | A Revolt-native Symfony `HttpClientInterface` — usable standalone, no Kinetis required |
| `kinetis/bref-adapter` | AWS Lambda (Bref) runtime adapter, for multipart/form-data support Lambda specifically needs |

## Documentation

The full documentation is hosted at [docs.kinetis.dev](https://docs.kinetis.dev/)
— start with [Getting Started](https://docs.kinetis.dev/getting-started.html)
or [Core Concepts](https://docs.kinetis.dev/core-concepts.html).

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
