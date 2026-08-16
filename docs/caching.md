# Caching & AOT Compilation

Production deployments can precompute everything Kinetis would otherwise
derive through reflection on every request — routing, MCP tool/resource
registration, command registration, HTTP and MCP parameter binding, and
DTO validation plans — into a build-time artifact, avoiding that cost at
request time. This page covers what gets cached, how it's structured, and
what it's worth in practice.

## `APP_ENV`

```{code-block} php
use Kinetis\Runtime\AppEnvironment;

$env = AppEnvironment::detect(); // reads getenv('APP_ENV')
```

```{code-block} bash
APP_ENV=development php -S localhost:8080 public/index.php
```

Unset or unrecognized values default to `Production`. Local development
sets `APP_ENV=development` explicitly — see `.env.example` at the project
root, or {doc}`config` for loading it from a `.env` file automatically.

## What gets cached

Eleven things get precomputed:

- The route table.
- MCP tool/resource definitions.
- Command definitions.
- The `#[AsGlobalMiddleware]`-discovered class list, already priority-sorted.
- The `#[AsMcpMiddleware]`-discovered class list, scoped to `/mcp` only,
  already priority-sorted.
- The `#[AsOpenApiMiddleware]`-discovered class list, scoped to
  `/openapi.json`/`/docs` only, already priority-sorted.
- The `#[AsMiddlewareGroup]`-declared groups, each group's own members
  already priority-sorted.
- The `#[Listener]`-discovered event listener list, grouped by event class,
  already priority-sorted.
- HTTP and MCP parameter-binding plans (how each request's data maps onto
  your controller/tool method's parameters).
- DTO validation plans.
- The installed packages' bootstrap-class list (declared via
  `extra.kinetis` — see {doc}`cli`), so production never re-reads
  `vendor/composer/installed.json` per request.

`GlobalMiddlewareDiscovery::discoverAll()` performs exactly one
project-wide scan for all four middleware attributes, not four — see
{doc}`middleware`.

Environment configuration (`.env`, see {doc}`config`) is not part of this
cache — changing it takes effect immediately, with no rebuild needed.

The on-disk result is five independent files:

```{code-block} text
.kinetis-cache/
├── http.php       routes + global/mcp/openapi middleware + named
│                  middleware groups + HTTP binding plans + validation
│                  plans for DTOs reachable from HTTP routes + the
│                  package bootstrap-class list
├── mcp.php        tool/resource definitions + MCP binding plans +
│                  validation plans for DTOs reachable from MCP tools
├── commands.php   command definitions + the package bootstrap-class
│                  list (repeated so `bin/kinetis` loads one file)
├── events.php     event listeners, grouped by event class
└── openapi.php    the generated OpenAPI document
```

A DTO used by both an HTTP route and an MCP tool appears in both files.

## Two ways to build it

### `bin/kinetis build` — pre-warm ahead of deploy

```{code-block} bash
php bin/kinetis build
# Compiled routes, MCP tools/resources, commands, event listeners, and
# OpenAPI cache written to .kinetis-cache/
```

Run this as part of your deploy step. Routes, MCP tools/resources,
commands, global middleware, and event listeners are all found by
namespace — see {doc}`cli` for how.

### Lazy, on first request

If `APP_ENV=production` and no cache exists yet, the very first request
compiles and writes it — safely, even under concurrent PHP-FPM workers
racing to be "first" against an empty cache directory. Every request after
that, on any worker, just loads what's already there. Once the cache
exists, live discovery never runs again: your `Http`/`Mcp`/`Console`/
`Events` classes, and any `#[AsGlobalMiddleware]`-attributed class, aren't
reflected again until the cache is rebuilt with `bin/kinetis build`.

```{note}
Pre-warming avoids exactly one thing: the extra compile-and-write cost on
whichever request happens to be first. It doesn't make caching *itself*
any faster — a cold `prod-lazy` deployment is consistently slower than a
pre-warmed one for exactly that one request, a one-time tax pre-warming
removes entirely.
```

## Performance characteristics

For a persistent worker (FrankenPHP's primary deployment target — see
{doc}`runtime-adapters`), `Router::register()` only ever runs once
regardless of caching, since boot happens once for the whole worker's
lifetime. What still runs on **every single dispatch**, cached or not, is
`Dispatcher`/`Hydrator`'s parameter-binding and validation-plan derivation
— precomputing that is typically 10-30% faster, with query-parameter and
validated-body-DTO routes seeing more benefit than plain path-parameter
routes.

For a boot-and-die runtime (PHP-FPM), the picture is different.
Reflecting an already-compiled class's metadata is cheap and roughly
constant per method, regardless of that method's body size — a
controller's file size isn't the lever. What matters is that live mode's
`Router::register()` has to autoload *every* registered controller class
on *every* request, because it can't know which route will match until
the whole table is built. The cached path never touches those files at
boot at all — `Router::fromArray()` holds class names as plain strings —
so only the one controller actually dispatched to gets autoloaded, in
either path. For an application with many controller classes, this makes
the cached path several times faster overall: controller-class count and
file size are the real lever at scale, not DTO or binding-plan count.

Cold-start time (container/process startup) dominates the application-level
difference entirely at that scale, but a `prod-lazy` deployment is still
consistently the slowest cold configuration, for the same reason as above:
it's still paying the compile-and-write cost a pre-warmed deployment
already paid ahead of time.

## Cache invalidation

The cache is not automatically invalidated on redeploy. Rerun
`bin/kinetis build` as part of your deploy step whenever your
registrations or DTO shapes change.

## See also

- {doc}`cli` — how MCP tools/resources and commands are found by
  namespace, with no registration file for either.
- {doc}`runtime-adapters` — why this matters enormously for `FpmAdapter`
  and barely at all for `FrankenPhpAdapter`'s own boot cost specifically.
- {doc}`routing-validation` / {doc}`mcp` — the live behavior this
  precomputes; nothing about how routes/tools/DTOs are *declared* changes.
- {doc}`persistence` — the *other* "cache" in this codebase:
  `Psr\SimpleCache\CacheInterface`, a general-purpose runtime cache
  unrelated to the build-time compilation described on this page beyond
  the shared word.
