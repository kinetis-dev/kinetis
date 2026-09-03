# Caching & AOT Compilation

Production deployments can precompute everything Kinetis would otherwise
derive through reflection on every request — routing, command and
event-listener registration, HTTP parameter binding, and DTO validation
plans — into a build-time artifact, avoiding that cost at
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

Nine things get precomputed:

- The route table.
- Command definitions.
- The `#[AsGlobalMiddleware]`-discovered class list, already priority-sorted.
- The `#[AsOpenApiMiddleware]`-discovered class list, published as the
  built-in `openapi` middleware group, already priority-sorted.
- The `#[AsMiddlewareGroup]`-declared groups, each group's own members
  already priority-sorted.
- The `#[Listener]`-discovered event listener list, grouped by event class,
  already priority-sorted.
- HTTP parameter-binding plans (how each request's data maps onto your
  controller method's parameters).
- DTO validation plans, and the installed packages' bootstrap-class list
  (declared via `extra.kinetis` — see {doc}`cli`), so production never
  re-reads `vendor/composer/installed.json` per request.
- Every installed package's own `CacheableDiscoveryInterface` data —
  declared via `extra.kinetis`'s `discovery` key, also see {doc}`cli`.

`GlobalMiddlewareDiscovery::discoverAll()` performs exactly one
project-wide scan for all three middleware attributes, not three — see
{doc}`middleware`.

MCP tools and resources are part of this cache too — `kinetis/mcp`'s
`McpRegistry` is a `CacheableDiscoveryInterface` class like any other
installed package's, one more entry in `plugins.php`. See {doc}`mcp`.

Environment configuration (`.env`, see {doc}`config`) is not part of this
cache — changing it takes effect immediately, with no rebuild needed.

The on-disk result is four files, always published together as one
*generation*:

```{code-block} text
.kinetis-cache/
├── current                the published pointer — names which
│                          generation below is active
├── gen_1a2b3c4d5e6f7a8b/  one complete generation
│   ├── http.php           routes + global/openapi middleware + named
│   │                      middleware groups + HTTP binding plans +
│   │                      validation plans for DTOs reachable from
│   │                      HTTP routes + the package bootstrap-class
│   │                      list
│   ├── commands.php       command definitions + the package
│   │                      bootstrap-class list (repeated here and
│   │                      in http.php, so whichever one an entry
│   │                      point reads already carries it, with no
│   │                      extra section needed just for that)
│   ├── events.php         event listeners, grouped by event class
│   └── plugins.php        every installed package's own
│                          CacheableDiscoveryInterface data, keyed by
│                          the class that produced it
└── gen_.../               an older, superseded generation — retained,
                           not deleted (see "Publishing a generation
                           atomically" below)
```

Each entry point still reads only the sections it actually consumes, never
all four — an HTTP boot reads `http.php`, `events.php`, and `plugins.php`
(never `commands.php`); the CLI reads `commands.php`, `events.php`, and
`plugins.php` (never `http.php`) — so this stays exactly as lazy as it
looks. What's new is that every file inside one generation directory is
guaranteed to have come from the *same* compile pass: `current` is what
makes that guarantee possible.

`current` is deliberately plain text, not a `.php` file like every
section — the one place `require()` and OPcache would actively work
against correctness rather than for it. Every section lives at a
brand-new path per generation, so OPcache caching it forever
(`opcache.validate_timestamps=0`, a common production setting) is
exactly right — that path's content never changes once written. `current`
is the opposite: the one path reused across every publish, rewritten via
`rename()` each time. Under that same setting, OPcache never re-stats a
`require()`d file to notice a rename happened, so a PHP pointer could
silently keep serving the *first* compiled generation's content
indefinitely, no matter how many times `kinetis build` reports success,
until a process restart or an explicit OPcache invalidation. Reading it
as plain data instead makes every read see the real, current bytes.

### Publishing a generation atomically

Four separate files being individually well-formed isn't the same as the
*set* being safe to read piecemeal — a plain HTTP boot reads `http.php`
first and, later in the same request, `events.php`/`plugins.php` too (see
{doc}`appendix`'s `BootSequence` entry). If a rebuild could replace those
files one at a time in place, a request could read routes from one
compile pass and event listeners from a different one, mid-swap.

`CacheStore::writeAll()` avoids this by never touching an already-
published generation at all. A rebuild writes all four files into a
brand-new, uniquely-named generation directory first; only once every one
of them has succeeded does it atomically switch `current` to name that
generation — the single moment any reader can learn it exists. A
`CacheStore` instance resolves that pointer once, on its first read, and
keeps using the same generation for every later read it makes — so the
`http.php` a request loads and the `events.php`/`plugins.php` it loads
afterward are always from the identical compile pass, even if another
worker or deploy publishes a newer generation in between. A fresh
instance — the next request, or the next `bin/kinetis` invocation —
resolves independently and may see that newer one.

An older generation is never deleted automatically: nothing tells
`CacheStore` when the last reader still pinned to it has finished, so
deleting on a schedule could remove a generation a long-lived persistent
worker is still reading from. `.kinetis-cache/` therefore accumulates a
generation directory per successful `kinetis build` until something
explicitly clears it — `kinetis build --destroy` removes the whole
directory, pointer and every generation alike. A rebuild that fails
partway through (a compile error, an unwritable disk) never publishes at
all: the partially-written generation is deleted before the error
propagates, and whatever was previously active — if anything — is left
exactly as it was.

The OpenAPI document is deliberately not among these. It is generated
per request in development and cached in whatever `CacheInterface` the
application has bound in production, so a deployment that changes routes
or DTOs runs `kinetis openapi:clear` alongside `kinetis build` — see
{doc}`routing-validation`.

## Two ways to build it

### `bin/kinetis build` — pre-warm ahead of deploy

```{code-block} bash
php vendor/bin/kinetis build
# Compiled routes, commands, event listeners, and every installed
# package's own plugin data written to .kinetis-cache/
```

Run this as part of your deploy step. Routes, commands, global
middleware, event listeners, and every installed package's own
`CacheableDiscoveryInterface` data are all found by namespace — see
{doc}`cli` for how.

### Lazy, on first request

If `APP_ENV=production` and no cache exists yet, the very first request
compiles and publishes it — safely, even under concurrent PHP-FPM workers
racing to be "first" against an empty cache directory: each one that
loses the race still publishes its own complete generation (see
"Publishing a generation atomically" above), never a corrupted or partial
one, and every worker's own read of that generation stays internally
consistent regardless of which one "wins." Every request after that, on
any worker, just loads what's already published. Once a generation
exists, live discovery never runs again: your `Http`/`Console`/`Events`
classes, and any `#[AsGlobalMiddleware]`-attributed class, aren't
reflected again until the cache is rebuilt with `bin/kinetis build`.

```{note}
Pre-warming avoids exactly one thing: the extra compile-and-write cost on
whichever request happens to be first. It doesn't make caching *itself*
any faster — a cold `prod-lazy` deployment is consistently slower than a
pre-warmed one for exactly that one request, a one-time tax pre-warming
removes entirely.
```

## Performance characteristics

For a persistent worker (FrankenPHP, Kinetis's primary deployment
target, or RoadRunner — see {doc}`runtime-adapters`),
`Router::register()` only ever runs once
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
