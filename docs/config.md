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

`public/index.php`, `bin/kinetis`, and `kinetis/queue`'s `bin/queue` each
construct a plain `AppScope` and call `boot()` on it — with no bindings of
your own registered yet. An optional `bootstrap.php` at your project root
is the place to register anything a controller, command, or job actually
needs before that lock happens:

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use Amp\Mysql\MysqlConnectionPool;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\SqlConnectionFactory;

return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlConnectionPool::class, SqlConnectionFactory::fromConfig($config));
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

## See also

- {doc}`container` — `AppScope`'s registration-lock discipline, and how
  `RequestScope` delegates to a `Config` it never explicitly registered
  itself.
- {doc}`caching` — the AOT cache's reproducible-from-source invariant that
  keeps environment configuration out of it.
- {doc}`appendix` — the `Kinetis\Config` namespace in the full system map.
