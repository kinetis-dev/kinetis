# Configuration

Two independent pieces: loading a `.env` file into the real process
environment, and typed access to whatever's in it.

## `.env` loading

```{code-block} text
:caption: .env, at your project root

APP_ENV=production
DB_HOST=db.internal
DB_PORT=3306
DEBUG=false
```

Both `public/index.php` and `bin/kinetis` call
`Kinetis\Config\EnvFile::safeLoad($projectRoot)` unconditionally, before
`Kinetis\Runtime\AppEnvironment::detect()` — `APP_ENV` itself might be
defined for the first time in `.env`, not already set in the real process
environment.

`.env` is entirely optional and never an error to omit:
`EnvFile::safeLoad()` uses `safeLoad()`, not `load()` — a missing file is the
normal case for a deployment with real environment variables already set
by the platform, not something to throw over. A real environment variable
that's already set always wins over `.env` too, so a checked-in
`.env.example` copied to `.env` locally can never accidentally override a
production secret set through Docker, systemd, or a secrets manager.

```{note}
No `AppEnvironment` check gates this — `.env` loading always runs, in
every environment, not just development. Traditional shared hosting and
plenty of FPM-only deployments give you *only* file access, with no way
to set a real process environment variable at all — no Docker, no systemd
unit you control, sometimes not even FPM pool config access. For that
shape of deployment, `.env` is the only way to configure the app in
production, not a dev-only convenience.
```

## Typed config access

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Http\Attributes\Get;

final readonly class OrderController
{
    public function __construct(
        private Config $config,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        $host = $this->config->string('DB_HOST', 'localhost');
        $debug = $this->config->bool('DEBUG', false);

        // ...
    }
}
```

`Kinetis\Config\Config` is a plain snapshot of the environment, taken once
— not live `getenv()` calls scattered through your business logic.
Environment variables are worker-lifetime configuration, not per-request
state, so there's nothing to keep re-reading for.

| Method | Returns |
|---|---|
| `get(string $key, ?string $default = null): ?string` | The raw value, or `$default` |
| `string(string $key, string $default): string` | Same as `get()`, with a required (non-null) default |
| `int(string $key, int $default): int` | Cast to `int` |
| `float(string $key, float $default): float` | Cast to `float` |
| `bool(string $key, bool $default): bool` | `filter_var($value, FILTER_VALIDATE_BOOLEAN)` |
| `required(string $key): string` | The raw value, or throws |

`required()` is for config with no sane default — a missing database
password should fail fast and clearly, not silently proceed as an empty
string and fail somewhere far less obvious later:

```{code-block} php
$password = $this->config->required('DB_PASSWORD');
// throws Kinetis\Config\Exception\MissingConfigException if unset
```

## Named connections

Any storage technology — Redis, a SQL database, and any future one — can
be configured more than once under a name, alongside the usual
unnamed **default** connection. A name is inserted as a segment right
after the variable's own prefix:

```{code-block} text
REDIS_HOST=cache.internal        # default
REDIS_CACHE2_HOST=cache2.internal # named "cache2"

DB_HOST=db.internal              # default
DB_DB2_HOST=db2.internal         # named "db2"
```

`Config::scopedKey(string $key, string $connection = 'default'): string`
is the one shared helper every technology's connection builder — see
{doc}`persistence` for `RedisSimpleCache`/`SqlConnectionFactory` — uses to
compute which exact variable to read:

```{code-block} php
Config::scopedKey('REDIS_HOST');            // 'REDIS_HOST'
Config::scopedKey('REDIS_HOST', 'cache2');   // 'REDIS_CACHE2_HOST'
```

`'default'` always resolves to the plain, unprefixed key — a connection
you never name behaves exactly as if this feature didn't exist. A named
connection is never resolved automatically by constructor type-hinting;
retrieve it explicitly from the container, or construct it directly,
wherever it's needed.

## Resolving `Config`

`Kinetis\Container\AppScope::boot()` registers a `Config` singleton
automatically — `Config::fromEnvironment()` — unless you've already
registered your own:

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

$app = new AppScope();
$app->instance(Config::class, new Config(['DB_HOST' => 'test-db']));
$app->boot(); // your registration above is kept, not overwritten
```

Resolvable anywhere via constructor injection — including through
`RequestScope`, which delegates to this same `AppScope`-registered
instance automatically (see {doc}`container`), the same as any other
service you never explicitly registered on `RequestScope` itself.

## Registering services before boot: `bootstrap.php`

`public/index.php` and `bin/kinetis` each construct a plain `AppScope`
and call `boot()` on it — with no bindings of your own registered yet.
Two things run before that lock: any installed package's own bootstrap
class (declared via `extra.kinetis` — see {doc}`cli` — the way
`kinetis/persistence` and `kinetis/queue` bind a configured connection
and queue backend with no wiring of yours), then an optional
`bootstrap.php` at your project root, the place to register anything a
controller, command, or job actually needs — and to override any binding
a package made, since your registration runs last:

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\SqlConnectionFactory;

return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlLink::class, SqlConnectionFactory::fromConfig($config));
};
```

It returns a `callable(AppScope, Config): void`, run with `$config`
passed directly rather than resolved from `$app` — `Config` itself isn't
registered on `$app` yet at this point, since `boot()` is what registers
the default one. Every entry point that supports `bootstrap.php` already
has a `Config` on hand to pass in, built the same way:

```{code-block} php
$config = Config::fromEnvironment();
$app->instance(Config::class, $config);
Kinetis\Cache\RoutesFile::loadBootstrap($projectRoot)($app, $config);
$app->boot();
```

Entirely optional — a project with no `bootstrap.php` at its root boots
exactly as if this feature didn't exist.

## What's not cached

`Config` and `.env` are deliberately outside the AOT compilation Kinetis
builds for production (see {doc}`caching`). That cache's entire value
proposition is being reproducible from source code alone — delete it,
rebuild it, and you get back the identical artifact. Environment
variables break that by definition: the process that ran `bin/kinetis
build` and the one serving requests later can legitimately have different
values injected into them. Baking `.env` into a compiled cache file would
mean a changed value silently does nothing until someone remembers to
rebuild the cache.

## Reference: every key in one place

Everything Kinetis and its packages read from the environment, grouped
by subsystem. Keys marked *scoped* follow the named-connection
convention above — `DB_HOST` becomes `DB_REPORTING_HOST` for a
connection named `reporting`. Application-defined keys (a `JWT_SECRET`
your own bootstrap reads via `Config::required()`, for instance) are
yours to invent and aren't listed here.

### Application (core)

| Key | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | `development` or `production` — selects live discovery vs. the AOT cache (see {doc}`caching`). Anything unrecognized means `production`. |
| `MAX_BODY_SIZE` | `2097152` | Request-body cap in bytes, enforced against declared `Content-Length` and actual bytes read (see {doc}`middleware`). |

### Discovery restriction (core)

All optional; comma-separated sub-paths relative to each PSR-4 base
directory, for large applications that want a bounded scan (see
{doc}`cli`).

| Key | Restricts the scan for |
|---|---|
| `ROUTE_DISCOVERY_PATHS` | HTTP controllers (`#[Get]`/`#[Post]`/...) |
| `COMMAND_DISCOVERY_PATHS` | CLI commands (`#[Command]`) |
| `MCP_DISCOVERY_PATHS` | MCP tools and resources (`#[McpTool]`/`#[McpResource]`) |
| `MIDDLEWARE_DISCOVERY_PATHS` | Global middleware (`#[AsGlobalMiddleware]`) and middleware groups (`#[AsMiddlewareGroup]`) |
| `LISTENER_DISCOVERY_PATHS` | Event listeners (`#[Listener]`) |

### Database (`kinetis/persistence`) — all scoped

| Key | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | *(required)* | `mysql` or `pgsql`. |
| `DB_HOST` | `127.0.0.1` | Server host. |
| `DB_PORT` | `3306` / `5432` | Per dialect. |
| `DB_NAME` | `app` | Database name. |
| `DB_USER` | `app` | User. |
| `DB_PASSWORD` | *(required)* | Password. |
| `DB_DRIVER` | `auto` | `auto` (native under FrankenPHP worker mode, PDO otherwise), `native`, or `pdo`. |
| `DB_CHARSET` | `utf8mb4` (MySQL) | Connection charset. |
| `DB_COLLATION` | — | MySQL collation (`SET NAMES ... COLLATE`). |
| `DB_SSLMODE` | — | `disable`/`require`/`verify-ca`/`verify-full` on every driver; libpq additionally accepts `allow`/`prefer`. |
| `DB_SSL_CA` | — | CA bundle path for the verify modes. |
| `DB_SSL_CERT` | — | Client certificate for mutual TLS; requires `DB_SSL_KEY`. |
| `DB_SSL_KEY` | — | Client private key; requires `DB_SSL_CERT`. Postgres requires `0600` permissions. |
| `DB_CONNECT_TIMEOUT` | — | Seconds. |
| `DB_APP_NAME` | — | Postgres `application_name`. |
| `DB_COMPRESSION` | — | MySQL protocol compression. |
| `DB_MAX_CONNECTIONS` | `8` | Async drivers' pool width — per worker thread under FrankenPHP (see {doc}`performance-tuning`). |
| `DB_WARM_CONNECTIONS` | `0` | Connections opened at boot instead of first use — load-bearing for the mysqli driver under worker mode. |
| `DB_OPTIONS` | — | Legacy key=value string, translated where canonical equivalents exist. |

### Redis (`kinetis/cache-redis`) — all scoped

With none of `REDIS_URL`/`REDIS_HOST`/`REDIS_CLUSTER` set, Redis is
simply off and `CacheInterface` binds to `NullSimpleCache`.

| Key | Default | Purpose |
|---|---|---|
| `REDIS_URL` | — | Full `redis://` URI; wins over the discrete keys. |
| `REDIS_HOST` | — | Server host. |
| `REDIS_PORT` | `6379` | Port. |
| `REDIS_PASSWORD` | — | Password. |
| `REDIS_DATABASE` | `0` | Database index (single-node only; Cluster has no `SELECT`). |
| `REDIS_TIMEOUT` | `5` | Connect timeout, seconds. |
| `REDIS_TLS` | `false` | Connect over TLS. |
| `REDIS_TLS_VERIFY_PEER` | `true` | Verify the server certificate. |
| `REDIS_TLS_CA_FILE` | — | CA certificate for verification. |
| `REDIS_CLUSTER` | `false` | Use Redis Cluster mode. |
| `REDIS_CLUSTER_SEEDS` | — | Comma-separated seed nodes for Cluster bootstrap. |

### Queue (`kinetis/queue` + backend packages)

Read by `kinetis queue:work` and `kinetis/queue`'s package bootstrap;
the backend-specific keys are scoped.

| Key | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | *(required)* | `redis`, `sql`, `sqs` (needs `kinetis/queue-sqs`), or `rabbitmq` (needs `kinetis/queue-rabbitmq`). |
| `QUEUE_CONNECTION_NAME` | `default` | Which named `REDIS_*`/`DB_*` block the worker uses. |
| `QUEUE_MAX_ATTEMPTS` | `0` | Worker-level default attempts cap (`0` = no retries); a job's own `push(maxAttempts: ...)` wins. |
| `QUEUE_POLL_TIMEOUT` | `5` | Seconds per `pop()` wait. |
| `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | — | SQL backend only: reclaim a crashed worker's reserved job after this long; unset means never. |
| `QUEUE_SQS_REGION` | *(required for sqs)* | AWS region. |
| `QUEUE_SQS_ENDPOINT` | — | SQS-compatible endpoint (LocalStack). |
| `QUEUE_SQS_QUEUE_PREFIX` | — | Queue-name prefix for shared AWS accounts. |
| `QUEUE_RABBITMQ_URL` | *(required for rabbitmq)* | `amqp://` URI. |
| `QUEUE_RABBITMQ_QUEUE_PREFIX` | — | Queue-name prefix. |

AWS credentials are deliberately never read from `Config` — the SQS
(and S3) clients use AWS's own default credential provider chain.

### Migrations (`kinetis/migrations`)

Read by the `migrate*` commands, which connect through the same `DB_*`
keys as persistence.

| Key | Default | Purpose |
|---|---|---|
| `MIGRATE_CONNECTION_NAME` | `default` | Which named `DB_*` block to migrate; the `--connection=<name>` flag wins over it. |

### File storage (`kinetis/storage` + `kinetis/storage-s3`) — all scoped

| Key | Default | Purpose |
|---|---|---|
| `FILESYSTEM_DRIVER` | `local` | `local`, or `s3` (needs `kinetis/storage-s3`). |
| `FILESYSTEM_ROOT` | *(required for local)* | Local disk root path. |
| `FILESYSTEM_S3_BUCKET` | *(required for s3)* | Bucket name. |
| `FILESYSTEM_S3_REGION` | *(required for s3)* | AWS region. |
| `FILESYSTEM_S3_PREFIX` | — | Key prefix. |
| `FILESYSTEM_S3_ENDPOINT` | — | S3-compatible endpoint (MinIO). |
| `FILESYSTEM_S3_PATH_STYLE` | `false` | Path-style addressing, needed by most non-AWS S3 services. |

### Mail (`kinetis/mailer`) — scoped

| Key | Default | Purpose |
|---|---|---|
| `MAILER_DSN` | *(required)* | Symfony Mailer transport DSN (`smtp://...`, `sendgrid+api://...`, ...). |

### Search (`kinetis/search-opensearch`) — all scoped

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_OPENSEARCH_HOST` | *(required)* | Base URI of the node. |
| `SEARCH_OPENSEARCH_USERNAME` | — | Basic-auth user. |
| `SEARCH_OPENSEARCH_PASSWORD` | — | Basic-auth password. |
| `SEARCH_OPENSEARCH_VERIFY_PEER` | `true` | Verify the server certificate. |

## See also

- {doc}`container` — `AppScope`'s registration-lock discipline, and how
  `RequestScope` delegates to a `Config` it never explicitly registered
  itself.
- {doc}`caching` — the AOT cache's reproducible-from-source invariant that
  keeps environment configuration out of it.
- {doc}`appendix` — the `Kinetis\Config` namespace in the full system map.
