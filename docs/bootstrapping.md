# Bootstrapping

Most applications never write `bootstrap.php`. Installing
`kinetis/database-bridge` and configuring a `DB_*` connection —
`DB_CONNECTION` plus whatever credentials the dialect requires — gets you
a database connection; installing `kinetis/queue-redis` and setting
`QUEUE_CONNECTION=redis` plus `REDIS_HOST` or `REDIS_URL` gets you a queue.
This page is for the two things
that stay yours to wire: an application service that needs to exist
before the container locks, and global middleware that needs
constructor configuration no attribute can supply.

## What happens automatically

Every framework-owned entry point — `public/index.php` via
`Kinetis\Runtime\HttpStartup`, `bin/kinetis`, and
`Kinetis\Testing\TestApplication` — runs the same sequence once per
execution context (see {doc}`core-concepts` for the full boot order):

1. Loads `.env` and builds `Config`, registered on a fresh `AppScope`.
2. Discovers (or, in production, loads from the compiled cache) routes,
   controllers, `#[AsGlobalMiddleware]` classes, and event listeners —
   see {doc}`middleware` and {doc}`cli`. Nothing here needs registering by
   hand.
3. Binds `FormLimits` and `TrustedProxies` from that `Config`.
4. Runs the package/application bootstrap chain (below), then
   `AppScope::boot()`, which locks the container and fills in defaults
   for anything still unregistered — `Config`, a `LoggerInterface`, the
   process's `CacheInterface`, and a handful of others (see
   {doc}`container`).

A project with none of the needs below boots exactly as if `bootstrap.php`
did not exist.

## When you need `bootstrap.php`

An optional `bootstrap.php` at your project root — `return static function
(AppScope $app, Config $config): void { ... };` — covers two jobs neither
discovery nor a package's own bootstrap can do for you:

- **Binding an application service** before the container locks: a named
  connection, or overriding a default `AppScope::boot()` would otherwise
  register (a custom `LoggerInterface`, say).
- **Registering global middleware that needs constructor configuration**:
  `#[AsGlobalMiddleware]` covers a middleware class with no configuration
  of its own, but one that takes constructor arguments — allowed CORS
  origins, a rate-limit policy — has to be bound and registered
  explicitly.

## Boot order

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

return static function (AppScope $app, Config $config): void {
    // ...
};
```

Package bootstraps — every installed package's own class declared via
`extra.kinetis` (see {doc}`cli`), such as `kinetis/database-bridge`
binding the default `DB_*` connection — run first. Your `bootstrap.php`
runs last, so it wins over any package binding for the same id. The
`$config` argument is the exact instance the entry point registered on
`$app`, handed over directly so a bootstrap never has to reach into the
container for it.

A `#[Command(bootstrap: false)]` command skips this whole chain — package
bootstraps included — because it operates on the project's static shape
and must not require configuration those registrations would demand;
`kinetis build` in a CI pipeline has no database credentials and needs
none.

### Binding an application service

An application-owned service built from `Config` is the ordinary case:
nothing but your own `bootstrap.php` knows how to construct it, so
`AppScope` needs an explicit registration before it can be resolved.

```{code-block} php
:caption: bootstrap.php

use App\Payments\PaymentGatewayClient;

return static function (AppScope $app, Config $config): void {
    $app->instance(PaymentGatewayClient::class, new PaymentGatewayClient(
        apiKey: $config->required('PAYMENT_GATEWAY_API_KEY'),
    ));
};
```

One instance, built once at boot, shared by every request this worker
handles — the same `AppScope` ownership {doc}`container` documents for
any other worker-lifetime service. A named database or cache connection
follows the identical shape but is an advanced, less common case; see
{doc}`appendix-configuration`'s "Named connections" for it.

### Registering global middleware

`CorsMiddleware` needs allowed origins no attribute could supply, so it's
never `#[AsGlobalMiddleware]`-attributed — bind it, then register it:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Http\Middleware\CorsMiddleware;

return static function (AppScope $app, Config $config): void {
    $app->bind(CorsMiddleware::class, static fn (): CorsMiddleware => new CorsMiddleware(
        allowedOrigins: ['https://app.example.com'],
    ));
    $app->middleware(CorsMiddleware::class);
};
```

Middleware you write yourself, with no per-application configuration,
should reach for `#[AsGlobalMiddleware]` instead (see
{doc}`middleware`'s "Discoverable global middleware") — nothing to
register here at all.

## Choosing `AppScope` or `RequestScope`

Registering a service is the one newcomer decision `bootstrap.php`
forces: does this instance stay correct for every request the worker
serves, or must it be built fresh — or reset — per request?

- **`AppScope`** (`bind()`/`instance()` above): one instance for the
  worker's whole lifetime, shared by every request it handles. Correct
  for a database connection, a queue client, a stateless service — never
  for anything holding a specific request's identity, input, or
  resources. When one of those holds a resource that has to be released
  at the end, register the release with `$app->onDispose(...)`; see
  {doc}`container`'s "Ending the application's lifetime".
- **`RequestScope`**: nothing to register for the ordinary case. A class
  you never explicitly bind autowires fresh per request, and that
  instance is discarded when the request's scope is disposed. Reach for
  this — by simply constructor-injecting the class where it's needed,
  through a controller or route middleware — for anything request-owned.

Registering a request-owned service on `AppScope` is the mistake this
split exists to prevent: it turns one request's data into state every
later request on that worker sees. When in doubt, register nothing and
let autowiring build it per request — promoting something to `AppScope`
is a deliberate, explicit choice, not a default. See {doc}`container` for
the complete lifecycle, disposal, and static-property rules behind this
split.

`Config` is a worked example of the *first* case, not the second:
`AppScope::boot()` registers one shared instance for you, and
`RequestScope` never builds its own — it only delegates to that same
registration (see {doc}`container`'s "Resolution order"). So a
controller or route middleware constructor-injecting `Config` gets that
one worker-lifetime instance, with no `bootstrap.php` of its own required
unless you want to override the default — see {doc}`config`'s "Resolving
`Config`".

## See also

- {doc}`container` — the full `AppScope`/`RequestScope` lifecycle,
  registration-lock discipline, and disposal rules.
- {doc}`config` — `.env` loading, typed `Config` access, and named
  connections.
- {doc}`appendix-configuration` — the full named-connection mechanics and
  `ConnectionFactory::fromConfig()` registration example.
- {doc}`middleware` — route vs. global middleware, and
  `#[AsGlobalMiddleware]` discovery.
- {doc}`cli` — a package's own `extra.kinetis` bootstrap declaration.
- {doc}`core-concepts` — `HttpStartup`'s complete, step-by-step boot
  sequence.
