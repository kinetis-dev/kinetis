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

Every framework-owned entry point — `Kinetis\Runtime\HttpStartup` (the
whole of an application's `public/index.php`), `bin/kinetis`, and
`Kinetis\Testing\TestApplication` (see {doc}`testing`) — calls
`Kinetis\Config\EnvFile::safeLoad($projectRoot)` unconditionally, before
`Kinetis\Runtime\AppEnvironment::detect()`. `APP_ENV` itself may be
defined for the first time in `.env` rather than already set in the real
process environment, so detection has to run after the load.

The load writes through `putenv()`, `$_ENV` and `$_SERVER`, which is what
puts a `.env` value in reach of the plain `getenv()` both
`Config::fromEnvironment()` and `AppEnvironment::detect()` read.

A missing `.env` is not an error — the method does nothing and startup
continues. A real environment variable that is already set always wins
over the file, so a checked-in `.env.example` copied to `.env` locally
cannot override a production secret set through Docker, systemd, or a
secrets manager.

```{note}
No `AppEnvironment` check gates this — `.env` loading runs in every
environment. Shared hosting and FPM-only deployments often give you file
access and no way to set a real process environment variable at all. For
that shape of deployment `.env` is the way to configure the application
in production, not a development-only convenience.
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
        return [
            'host' => $this->config->string('DB_HOST', 'localhost'),
            'debug' => $this->config->bool('DEBUG', false),
        ];
    }
}
```

`Kinetis\Config\Config` is a snapshot of the environment taken once, not
live `getenv()` calls scattered through business logic. Environment
variables are worker-lifetime configuration, not per-request state.

| Method | Returns |
|---|---|
| `get(string $key, ?string $default = null): ?string` | The raw value, or `$default` when the key is absent |
| `string(string $key, string $default): string` | Same as `get()`, with a required (non-null) default |
| `int(string $key, int $default): int` | The value as `int`, or `$default` when unset or empty |
| `intOrNull(string $key): ?int` | The value as `int`, or `null` when unset or empty — no default to fall back to |
| `float(string $key, float $default): float` | The value as `float`, or `$default` when unset or empty |
| `bool(string $key, bool $default): bool` | The value as `bool`, or `$default` when unset or empty |
| `required(string $key): string` | The raw value, or throws `Kinetis\Config\Exception\MissingConfigException` when the key is absent |

The four parsing accessors — `int()`, `intOrNull()`, `float()`, `bool()`
— treat an explicitly empty value (`DB_PORT=`) the same as an unset one
and fall back to the default. That is the convention a named-connection
setting uses to turn itself off. Anything else that doesn't parse, or
doesn't fit, throws `Kinetis\Config\Exception\InvalidConfigValueException`
rather than taking whatever a lossy cast would produce.

`get()`, `string()` and `required()` do not parse, and so do not apply
that rule: they return an empty value as the empty string. `DB_PASSWORD=`
satisfies `required('DB_PASSWORD')` and yields `''`. Only an absent key
throws.

`int()`/`intOrNull()` accept an optional sign followed by decimal digits
only — leading zeroes (`"007"`) are fine, but a fraction, an exponent,
surrounding whitespace, an alternate base (`"0x1A"`), or a grouping
separator (`"1_000"`) all throw, as does anything outside the platform's
representable integer range (a huge digit string that would otherwise
saturate toward `PHP_INT_MAX`/`PHP_INT_MIN`).

`float()` accepts ordinary decimal notation (`"5"`, `"5."`, `".5"`,
`"5.5"`) with an optional scientific-notation exponent (`"5e3"`,
`"5.5e-3"`); a leading `+`, leading zeroes, and both a leading and a
trailing dot are accepted. Beyond syntax, two cases are checked rather
than left to a plain `(float)` cast: an exponent large enough to overflow
to infinity throws instead of becoming `INF`, and a nonzero value whose
magnitude underflows to exactly `0.0` (`"1e-400"`) throws too, since once
cast it is indistinguishable from a configured zero. A zero mantissa
(`"0e-400"`) returns `0.0`.

`bool()` accepts `"true"`/`"false"`, `"1"`/`"0"`, `"on"`/`"off"`, and
`"yes"`/`"no"` (case-insensitively) and rejects anything else, rather
than letting an unrecognized value like `"purple"` become `false`.

`Config` validates syntax and representable range. It has no idea whether
a key means a TCP port, a positive duration, or a ratio between 0 and 1.
That domain knowledge belongs to whichever factory or middleware reads
the key: `SqlConnectionFactory` rejects a `DB_PORT` outside 1–65535,
`FormLimits` rejects a non-positive `MAX_BODY_SIZE`, `TracerFactory`
rejects an `OTEL_TRACES_SAMPLER_ARG` outside 0–1, and so on — each with
an `InvalidArgumentException` naming the key, rather than clamping into
range. The reference tables below record each key's own bound.

`intOrNull()` exists for config that means something different when it is
absent than when it is zero — `DB_CONNECT_TIMEOUT` means "no timeout at
all" only when unset, not `0` standing in for it.

`required()` is for config with no sane default. A database password
absent from the environment fails at construction rather than proceeding
as an empty string and failing somewhere less obvious later:

```{code-block} php
$password = $this->config->required('DB_PASSWORD');
// throws Kinetis\Config\Exception\MissingConfigException if the key is absent
```

## Named connections

Any storage technology — Redis, a SQL database, and any future one — can
be configured more than once under a name, alongside the usual unnamed
**default** connection. The name is inserted, uppercased, as a segment
right after the first underscore in the key:

```{code-block} text
REDIS_HOST=cache.internal         # default
REDIS_CACHE2_HOST=cache2.internal # named "cache2"

DB_HOST=db.internal               # default
DB_DB2_HOST=db2.internal          # named "db2"
```

`Config::scopedKey(string $key, string $connection = 'default'): string`
is the shared helper every technology's connection builder — see
{doc}`persistence` for `SqlConnectionFactory` and `RedisSimpleCache` —
uses to compute which exact variable to read:

```{code-block} php
Config::scopedKey('REDIS_HOST');                        // 'REDIS_HOST'
Config::scopedKey('REDIS_HOST', 'cache2');              // 'REDIS_CACHE2_HOST'
Config::scopedKey('FILESYSTEM_S3_BUCKET', 'archive');   // 'FILESYSTEM_ARCHIVE_S3_BUCKET'
```

The segment always lands after the first underscore, so a key with a
longer prefix splits at that same point: `FILESYSTEM_S3_BUCKET` becomes
`FILESYSTEM_ARCHIVE_S3_BUCKET`, not `FILESYSTEM_S3_ARCHIVE_BUCKET`.

`'default'` resolves to the plain, unprefixed key — a connection you
never name behaves exactly as if this feature did not exist.

A named connection is never resolved automatically. Every package
bootstrap reads its selector unscoped — `DB_CONNECTION`,
`QUEUE_CONNECTION`, `FILESYSTEM_DRIVER`, `MAILER_DSN`,
`SEARCH_OPENSEARCH_HOST`, `SEARCH_ELASTICSEARCH_HOST`, `SESSION_DRIVER`,
`BROADCAST_DRIVER` — and
wires the default connection alone. Build a named connection explicitly
in `bootstrap.php` and register it under an id of your own, or construct
it where it is needed.

## Resolving `Config`

`Kinetis\Container\AppScope::boot()` registers a `Config` singleton
automatically — `Config::fromEnvironment()` — unless you have already
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
instance (see {doc}`container`), the same as any other service you never
explicitly registered on `RequestScope` itself.

## Registering services before boot: `bootstrap.php`

Every entry point builds one `Config` from the environment, registers it
on a fresh `AppScope`, and calls `boot()` on that scope once everything
is wired. `boot()` locks the binding set, so anything an application
registers has to run before it.

Three things are bound ahead of your own code: the discovered plugin
instances and `EventListenerRegistry`, `Config` itself, and — on the HTTP
path — `FormLimits` and `TrustedProxies`. Then the bootstrap chain runs,
in this order:

1. Every installed package's own bootstrap class, declared via
   `extra.kinetis` (see {doc}`cli`) — how `kinetis/persistence` and
   `kinetis/queue` bind a configured connection and queue backend with no
   wiring of yours.
2. An optional `bootstrap.php` at your project root.

Yours runs last, so it wins over any package binding for the same id,
`FormLimits` and `TrustedProxies` included.

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\SqlConnectionFactory;

// kinetis/persistence already binds the default DB_* connection under its
// own dialect contract. A named connection is application-owned wiring.
return static function (AppScope $app, Config $config): void {
    $app->instance('db.reporting', SqlConnectionFactory::fromConfig($config, 'reporting'));
};
```

It returns a `callable(AppScope, Config): void`. The `$config` argument
is the same instance the entry point registered on `$app`, handed over
directly so a bootstrap never has to reach into the container for it.

A `#[Command(bootstrap: false)]` command skips this chain entirely —
package bootstraps as well as your `bootstrap.php` — because it operates
on the project's static shape and must not require configuration those
registrations would demand. `kinetis build` in a CI pipeline has no
database credentials and needs none.

`bootstrap.php` is optional: a project without one boots exactly as if
this feature did not exist.

## What's not cached

`Config` and `.env` sit outside the AOT compilation Kinetis builds for
production (see {doc}`caching`). That cache's value is being reproducible
from source alone — delete it, rebuild it, get back the identical
artifact. Environment variables break that: the process that ran
`bin/kinetis build` and the one serving requests later can legitimately
have different values injected into them. Baking `.env` into a compiled
cache file would mean a changed value did nothing until someone rebuilt
the cache.

## Reference: every key in one place

Everything Kinetis and its packages read from the environment, grouped by
subsystem. Keys marked *scoped* follow the named-connection convention
above — `DB_HOST` becomes `DB_REPORTING_HOST` for a connection named
`reporting`. Application-defined keys (a `JWT_SECRET` your own bootstrap
reads via `Config::required()`, for instance) are yours to invent and are
not listed here.

The Default column uses three forms. A literal value — including
*(empty)* for a list — is what the key falls back to. `—` means the key
has no default; what leaving it out does is in the Purpose cell.
*(unset: …)* marks an activation gate: the package or subsystem stays
inert until the key is set, and the cell names what you get until then.
*(required for X)* is the opposite — once X is selected the key has no
default left, and reading it throws naming the key.

### Application (core)

| Key | Default | Purpose |
|---|---|---|
| `APP_ENV` | `production` | `development` — the exact name, ignoring case — selects live discovery; unset or any other name selects the AOT cache (see {doc}`caching`). |
| `OPENAPI_ENVIRONMENTS` | — | Comma-separated `APP_ENV` values where `/openapi.json` and `/openapi` are served, matched ignoring case and surrounding space against the raw `APP_ENV` string (so a `staging` deployment can name itself). An absent `APP_ENV` matches `production`. Unset means neither path is reachable anywhere (see {doc}`routing-validation`). |
| `MAX_BODY_SIZE` | `2097152` | Request-body cap in bytes, enforced against declared `Content-Length` and actual bytes read (see {doc}`middleware`); must be positive. |
| `TRUSTED_PROXIES` | *(empty)* | Comma-separated addresses and CIDR ranges naming the deployment's own edge. Empty trusts no peer, and an entry that is neither an address nor a CIDR range is refused at startup. {doc}`runtime-adapters` owns the forwarded-header policy this configures. |

### Security headers (core)

Each key below carries its header's value verbatim, except the three
HSTS keys, which compose `Strict-Transport-Security` between them. For
the verbatim keys, an empty value omits the header and the literal `off`
— in any case — omits it too, so a header with a built-in default can be
switched off without sending an invalid value. See {doc}`middleware`.

| Key | Default | Purpose |
|---|---|---|
| `SECURITY_FRAME_OPTIONS` | `DENY` | `X-Frame-Options` value. |
| `SECURITY_REFERRER_POLICY` | `strict-origin-when-cross-origin` | `Referrer-Policy` value. |
| `SECURITY_CSP` | — | `Content-Security-Policy` value. |
| `SECURITY_PERMISSIONS_POLICY` | — | `Permissions-Policy` value. |
| `SECURITY_HSTS_MAX_AGE` | — | HSTS max-age in seconds. Unset means the header is not sent; an explicit `0` sends `max-age=0`, RFC 6797's withdrawal of a cached policy, and carries no directives; a negative value throws. |
| `SECURITY_HSTS_INCLUDE_SUBDOMAINS` | `true` | Appends `includeSubDomains` to a positive max-age. |
| `SECURITY_HSTS_PRELOAD` | `false` | Appends `preload` to a positive max-age. |
| `SECURITY_COOP` | — | `Cross-Origin-Opener-Policy` value. |
| `SECURITY_CORP` | — | `Cross-Origin-Resource-Policy` value. |
| `SECURITY_COEP` | — | `Cross-Origin-Embedder-Policy` value. |

`X-Content-Type-Options: nosniff` is always sent and is not configurable.
A header already present on the response is never replaced, so one route
can set its own policy and keep it.

### Discovery restriction

All optional; comma-separated sub-paths relative to each PSR-4 base
directory, for large applications that want a bounded scan (see
{doc}`cli`).

| Key | Restricts the scan for |
|---|---|
| `ROUTE_DISCOVERY_PATHS` | HTTP controllers (`#[Get]`/`#[Post]`/...) |
| `COMMAND_DISCOVERY_PATHS` | CLI commands (`#[Command]`) |
| `MCP_DISCOVERY_PATHS` | MCP tools and resources (`#[McpTool]`/`#[McpResource]`) — read by `kinetis/mcp` |
| `MIDDLEWARE_DISCOVERY_PATHS` | Global middleware (`#[AsGlobalMiddleware]`) and middleware groups (`#[AsMiddlewareGroup]`) |
| `LISTENER_DISCOVERY_PATHS` | Event listeners (`#[Listener]`) |
| `BROADCAST_CHANNEL_DISCOVERY_PATHS` | Channel authorization callbacks (`#[BroadcastChannel]`) — read by `kinetis/broadcasting` |

These are read through `getenv()` at discovery time rather than through
`Config`, so a `.env` value works and a `TestApplication` config override
does not.

### Database (`kinetis/persistence`) — all scoped

`DB_CONNECTION` is what the package bootstrap gates on, and it gates on
the key being *absent*, not blank: `DB_CONNECTION=` activates the package
and then fails on the dialect check.

| Key | Default | Purpose |
|---|---|---|
| `DB_CONNECTION` | *(unset: no database)* | `mysql` or `pgsql`. |
| `DB_HOST` | `127.0.0.1` | Server host. |
| `DB_PORT` | `3306` / `5432` | Per dialect; must be a valid TCP port (1–65535). |
| `DB_NAME` | `app` | Database name. |
| `DB_USER` | `app` | User. |
| `DB_PASSWORD` | *(required for mysql/pgsql)* | Password. An empty value is a valid empty password, not a missing key. |
| `DB_DRIVER` | `auto` | `auto` (native when `frankenphp_handle_request()` exists or `RR_MODE=http` is set, PDO everywhere else — Lambda included), `native`, or `pdo`. |
| `DB_CHARSET` | `utf8mb4` on MySQL | Connection charset; identifier characters only. |
| `DB_COLLATION` | — | MySQL collation (`SET NAMES ... COLLATE`); identifier characters only. Rejected by the Postgres drivers. |
| `DB_SSLMODE` | — | `disable`/`require`/`verify-ca`/`verify-full` on every driver; libpq additionally accepts `allow`/`prefer`, which the MySQL drivers reject as having no opportunistic TLS. |
| `DB_SSL_CA` | — | CA bundle path for the verify modes. |
| `DB_SSL_CERT` | — | Client certificate for mutual TLS; requires `DB_SSL_KEY` and a TLS `DB_SSLMODE`. |
| `DB_SSL_KEY` | — | Client private key; requires `DB_SSL_CERT`. Postgres requires `0600` permissions. |
| `DB_CONNECT_TIMEOUT` | — | Seconds; must be a positive integer. Unset means no client-side connect timeout. |
| `DB_APP_NAME` | — | Postgres `application_name`. Rejected by the MySQL drivers. |
| `DB_COMPRESSION` | — | MySQL protocol compression. On for `1`/`true`/`on`/`yes` (case-insensitively); any other value is off. Rejected by the Postgres drivers. |
| `DB_MAX_CONNECTIONS` | `8` | Async drivers' pool width; must be at least 1 — per worker thread under FrankenPHP, per worker process under RoadRunner (see {doc}`performance-tuning`). |
| `DB_WARM_CONNECTIONS` | `0` | Connections opened at boot instead of first use — load-bearing for the mysqli driver under worker mode; must not be negative. |

### Redis (`kinetis/cache-redis`, `kinetis/queue-redis`) — all scoped

`REDIS_CLUSTER`, `REDIS_URL` and `REDIS_HOST` are checked in that order,
and the first one set decides how the connection is addressed:
`REDIS_CLUSTER=true` selects the cluster client, otherwise `REDIS_URL`
wins over `REDIS_HOST`. A `REDIS_URL` carrying a password or a database
index wins over `REDIS_PASSWORD` and `REDIS_DATABASE` too. With none of
the three set, Redis is off — see {doc}`persistence` for what
`CacheInterface` binds to then.

| Key | Default | Purpose |
|---|---|---|
| `REDIS_CLUSTER` | `false` | Use Redis Cluster mode. Supported by the cache; `kinetis/queue-redis` is single-node only. |
| `REDIS_CLUSTER_SEEDS` | *(required for cluster)* | Comma-separated seed nodes for Cluster bootstrap — `host:port`, or `[ipv6-address]:port` for an IPv6 node. |
| `REDIS_URL` | — | Full `redis://` URI. |
| `REDIS_HOST` | — | Server host. |
| `REDIS_PORT` | `6379` | Port; must be a valid TCP port (1–65535). |
| `REDIS_PASSWORD` | — | Password. |
| `REDIS_DATABASE` | `0` | Database index (single-node only; Cluster has no `SELECT`); must not be negative. |
| `REDIS_TIMEOUT` | `5` | Operation budget, seconds — connect, reply, and cluster redirects together; must be positive. |
| `REDIS_TLS` | `false` | Connect over TLS. |
| `REDIS_TLS_VERIFY_PEER` | `true` | Verify the server certificate, discovered and redirected cluster nodes included. |
| `REDIS_TLS_CA_FILE` | — | CA certificate for verification. |
| `REDIS_CACHE_NAMESPACE` | `default` | Key namespace the cache owns; letters, digits, underscores and dashes. |

### Queue (`kinetis/queue` + backend packages)

Read by `kinetis queue:work` and `kinetis/queue`'s package bootstrap; the
backend-specific keys are scoped by `QUEUE_CONNECTION_NAME`. Setting
`QUEUE_CONNECTION` does two things beyond selecting a backend. It decides
which capabilities the bound backend has beyond `QueueInterface` —
`redis`, `sql`, and `rabbitmq` can clear a queue and `sqs` cannot, so
`kinetis queue:clear` refuses under `QUEUE_CONNECTION=sqs`, see
{doc}`queue`'s "Clearing is a separate capability". And it is what makes
a listener marked `Kinetis\Events\ShouldQueue` actually queue: the
bootstrap binds `ListenerInvokerInterface` to the queued invoker, where
leaving `QUEUE_CONNECTION` unset leaves core's inline default in place
(see {doc}`events`).

| Key | Default | Purpose |
|---|---|---|
| `QUEUE_CONNECTION` | *(unset: no queue)* | `redis` (needs `kinetis/queue-redis`), `sql` (needs `kinetis/queue-sql`), `sqs` (needs `kinetis/queue-sqs`), or `rabbitmq` (needs `kinetis/queue-rabbitmq`). Gates on the key being absent, not blank. |
| `QUEUE_CONNECTION_NAME` | `default` | Which named `REDIS_*`/`DB_*` block the worker uses. |
| `QUEUE_MAX_ATTEMPTS` | `0` | Worker-level default attempts cap (`0` = no retries, and must not be negative); a job's own `push(maxAttempts: ...)` wins. Bounds the attempt count only — retries are immediate, with no backoff (see {doc}`queue`). |
| `QUEUE_POLL_TIMEOUT` | `5` | Seconds `queue:work` waits per poll; must be a positive integer, since a persistent worker needs a bounded wait to periodically check for a shutdown signal. |
| `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | `300` | `kinetis/queue-redis` and `kinetis/queue-sql`: reclaim a crashed worker's reserved job after this long. Must be a positive integer; set it above the slowest job you expect. |
| `QUEUE_SQS_REGION` | *(required for sqs)* | AWS region. |
| `QUEUE_SQS_ENDPOINT` | — | SQS-compatible endpoint (LocalStack). One origin — scheme, host, optional port — and nothing else; unset leaves AsyncAws its regional table and refuses an ambient `AWS_ENDPOINT_URL`. |
| `QUEUE_SQS_PLAINTEXT` | `false` | Allows an `http://` value for `QUEUE_SQS_ENDPOINT`. |
| `QUEUE_SQS_TIMEOUT` | `30` | Seconds bounding each SQS request and each credential lookup; must be positive, and set above the longest long poll the application issues (at most a five-second slice). |
| `QUEUE_SQS_QUEUE_PREFIX` | — | Queue-name prefix for shared AWS accounts. |
| `QUEUE_RABBITMQ_URL` | *(required for rabbitmq)* | `amqp://` URI. |
| `QUEUE_RABBITMQ_QUEUE_PREFIX` | — | Queue-name prefix. |

AWS credentials are never read from `Config` — the SQS (and S3) clients
use AWS's own default credential provider chain.

### Migrations (`kinetis/migrations`)

Read by the `migrate*` commands, which connect through the same `DB_*`
keys as persistence.

| Key | Default | Purpose |
|---|---|---|
| `MIGRATE_CONNECTION_NAME` | `default` | Which named `DB_*` block to migrate; the `--connection=<name>` flag wins over it. |

### File storage (`kinetis/storage` + `kinetis/storage-s3`)

The gate below is on the unscoped key: installing `kinetis/storage` alone
registers nothing, and `FilesystemOperator` binds only once
`FILESYSTEM_DRIVER` is set. `FilesystemFactory::fromConfig()`, called
directly, bypasses that gate — it reads the key scoped and falls back to
`local`. Every other key here is scoped.

| Key | Default | Purpose |
|---|---|---|
| `FILESYSTEM_DRIVER` | *(unset: no filesystem)* | `local`, or `s3` (needs `kinetis/storage-s3`). |
| `FILESYSTEM_ROOT` | *(required for local)* | Local disk root path. Must be non-empty; `/` is valid. |
| `FILESYSTEM_S3_BUCKET` | *(required for s3)* | Bucket name. |
| `FILESYSTEM_S3_REGION` | *(required for s3)* | AWS region. |
| `FILESYSTEM_S3_PREFIX` | — | Key prefix. |
| `FILESYSTEM_S3_ENDPOINT` | — | S3-compatible endpoint (MinIO) — one origin, addressed path-style. |
| `FILESYSTEM_S3_PLAINTEXT` | `false` | Allow an `http://` endpoint. |
| `FILESYSTEM_S3_TIMEOUT` | `60` | Seconds per S3 request — connect, idle and transfer. Must be positive. |

### Mail (`kinetis/mailer`) — scoped

The gate below is on the unscoped `MAILER_DSN`; both keys are scoped when
`MailerFactory::fromConfig()` is called for a named connection.

| Key | Default | Purpose |
|---|---|---|
| `MAILER_DSN` | *(unset: no mailer)* | Symfony Mailer transport DSN (`smtp://...`, `sendgrid+api://...`, ...). |
| `MAILER_TIMEOUT` | `30` | Seconds per API send — idle and total. Must be positive. SMTP ignores it and carries its own timeouts from the DSN. |

### Search (`kinetis/search-opensearch`, `kinetis/search-elasticsearch`) — scoped

Both engine packages read the same keys under their own prefix —
`SEARCH_OPENSEARCH_` or `SEARCH_ELASTICSEARCH_`, written `SEARCH_..._`
below. The gate is on the unscoped `..._HOST`; every key is scoped when
the engine's `fromConfig()` is called for a named connection.

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_..._HOST` | *(unset: no client)* | One `http(s)://host[:port]` origin. No userinfo, path, query or fragment. |
| `SEARCH_..._PLAINTEXT` | `false` | Accept an `http` origin. |
| `SEARCH_..._TIMEOUT` | `30` | Seconds per request — idle and total. Must be positive. |
| `SEARCH_..._MAX_RESPONSE_BYTES` | `8388608` | Largest response body accepted. Must be positive. |
| `SEARCH_..._USERNAME` | — | Basic-auth user. |
| `SEARCH_..._PASSWORD` | — | Basic-auth password. |
| `SEARCH_..._VERIFY_PEER` | `true` | Verify the server certificate. |
| `SEARCH_ELASTICSEARCH_API_KEY` | — | Elasticsearch only: an API key, instead of a username and password. |
| `SEARCH_ELASTICSEARCH_API_KEY_ID` | — | Elasticsearch only: the key's id, when it is held separately from its secret. |

### Sessions (`kinetis/session`)

| Key | Default | Purpose |
|---|---|---|
| `SESSION_DRIVER` | *(unset: no store)* | `file`, `redis`, or `sql`. Any other value throws at boot. |
| `SESSION_LIFETIME` | `7200` | Seconds a session stays readable from its last write; must be positive. Every write refreshes the browser cookie's `Max-Age` alongside the backend's own storage TTL, so the two never drift apart. |
| `SESSION_COOKIE` | `kinetis_session` | Cookie name. A `__Host-`/`__Secure-` prefix requires `SESSION_SECURE`. |
| `SESSION_SAMESITE` | `Lax` | Cookie `SameSite` attribute: `Strict`, `Lax`, or `None`. `None` requires `SESSION_SECURE`. |
| `SESSION_SECURE` | `true` | Cookie `Secure` attribute — `false` only for non-TLS local development. |
| `SESSION_FILES_DIR` | `<system temp>/kinetis-sessions` | The `file` driver's directory. |

The `redis` and `sql` drivers each need their own package installed and
configured, and say which when they are not; {doc}`session` lists what
each one requires.

### MCP (`kinetis/mcp`)

`MCP_DISCOVERY_PATHS`, in the discovery table above, also belongs to this
package.

| Key | Default | Purpose |
|---|---|---|
| `MCP_ALLOWED_ORIGINS` | *(empty)* | Comma-separated exact `Origin` values allowed on `/mcp`, trimmed. Empty rejects with `403` any request that sends an `Origin` header at all; requests without one (CLI clients, server-to-server) always pass. |
| `MCP_HTTP_PUBLIC` | `false` | Serves `/mcp` to unauthenticated callers. Left at `false`, a request no `mcp`-group middleware registered a `CurrentUserInterface` for is answered `401` before anything is dispatched — see {doc}`mcp`'s "Securing the HTTP transport". |

### Telemetry (`kinetis/telemetry`)

| Key | Default | Purpose |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | *(unset: tracing off)* | Collector's OTLP/HTTP base URL. Without it the provider is a no-op, so telemetry is opt-in per environment rather than per install. |
| `OTEL_SERVICE_NAME` | `kinetis` | The `service.name` resource attribute. |
| `OTEL_EXPORTER_OTLP_HEADERS` | — | Export-request headers, `key=value,key2=value2` — where a hosted backend's auth goes. |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | Exactly one of `always_on`, `always_off`, `traceidratio`, `parentbased_always_on`, `parentbased_always_off`, `parentbased_traceidratio`. Any other name throws. |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | Sampling ratio for the two `traceidratio` samplers; must be between 0 and 1. |

### Broadcasting (`kinetis/broadcasting`)

`BROADCAST_DRIVER` and `BROADCAST_ALLOWED_ORIGINS` are read unscoped. The
Pusher connection keys are scoped, and the package bootstrap builds only
the default connection.

| Key | Default | Purpose |
|---|---|---|
| `BROADCAST_DRIVER` | `null` | `null` (`NullBroadcaster`, a silent no-op) or `pusher` — the wire protocol Soketi, Laravel Reverb, and Pusher's hosted service all share. Any other value throws at boot. |
| `BROADCAST_APP_ID` | *(required for pusher)* | App ID. |
| `BROADCAST_KEY` | *(required for pusher)* | App key — also the value a browser client subscribes with. |
| `BROADCAST_SECRET` | *(required for pusher)* | App secret, used to sign trigger requests and private/presence channel authorizations. |
| `BROADCAST_HOST` | `api.pusherapp.com` | Server host the backend publishes to. |
| `BROADCAST_PORT` | `443` | Server port; must be a valid TCP port (1–65535). |
| `BROADCAST_TLS` | `true` | Connect over TLS. |
| `BROADCAST_ALLOWED_ORIGINS` | *(empty)* | Comma-separated exact `Origin` values this route's own guard admits on `POST /broadcasting/auth`, on top of the request's own origin, which always passes. Requests without an `Origin` header (server-side clients) pass too; any other origin is `403`. Not a CORS policy: a cross-origin browser request must also be allowed by the global `CorsMiddleware`. Not connection-scoped — the endpoint is one route. See {doc}`broadcasting`'s "Securing the endpoint". |

The keys above address the server your *backend* publishes to. Where the
WebSocket server is reachable from a *browser* is a separate address, and
no `kinetis/broadcasting` key holds it — the application owns that value
and hands it to its own client code. The `pingpong` example reads it from
its own `BROADCAST_BROWSER_HOST`/`BROADCAST_BROWSER_PORT` keys through
`Config`, the same way any application-defined key works. A browser
client needs that address and the app key, nothing else. See
{doc}`broadcasting`.

## See also

- {doc}`container` — `AppScope`'s registration-lock discipline, and how
  `RequestScope` delegates to a `Config` it never explicitly registered
  itself.
- {doc}`caching` — the AOT cache's reproducible-from-source invariant that
  keeps environment configuration out of it.
- {doc}`appendix` — the `Kinetis\Config` namespace in the full system map.
