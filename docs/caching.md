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

Only the exact name `development`, ignoring case, selects `Development`.
An unset `APP_ENV` and every other name — a deployment's own `staging`
included — is `Production`. Local development sets `APP_ENV=development`
explicitly — see `.env.example` at the project root, or {doc}`config` for
loading it from a `.env` file automatically.

`Kinetis\Runtime\HttpStartup` reads this once per boot — it is the whole
of an application's `public/index.php` — and `bin/kinetis` reads it again
for the CLI. Development discovers from source on every boot; production
takes the artifact path described below.

## What gets cached

These get precomputed:

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
- DTO validation plans.
- The installed packages' bootstrap-class list (declared via
  `extra.kinetis` — see {doc}`cli`), so production never re-reads
  `vendor/composer/installed.json` per request.
- Every installed package's own `CacheableDiscoveryInterface` data —
  declared via `extra.kinetis`'s `discovery` key, also see {doc}`cli`.

`GlobalMiddlewareDiscovery::discoverAll()` performs exactly one
project-wide scan for all three middleware attributes, not three — see
{doc}`middleware`.

MCP tools and resources are part of this cache too — `kinetis/mcp`'s
`McpRegistry` is a `CacheableDiscoveryInterface` class like any other
installed package's, one more entry in the artifact's plugin section.
See {doc}`mcp`.

A tool's generated `inputSchema` is the one place this format needs a
little care. JSON Schema distinguishes the empty object `{}` from the
empty array `[]` — an empty `properties` map is one, an empty `required`
list the other — and a PHP array expresses only the second, so
`JsonSchema` spells `{}` as a live `stdClass`. A compiled artifact
carries scalars, arrays and enum cases, and the build refuses any other
object anywhere in one. `McpRegistry` therefore stores the schema as its
own JSON text, in `inputSchemaJson`: a plain string, and the one notation
that already
carries the distinction, so every empty object and every empty array
comes back the type it went in as, at any depth. That text is the
cache's own representation of the schema, not the bytes a transport
puts on the wire. Text that will not parse, or a document whose root is
not a JSON object, is rejected as an invalid artifact, like a malformed
route entry: the boot falls back to live discovery and recompiles,
rather than serving a tool schema that no longer matches what the
application declares. The full rule is in {doc}`appendix-packages`'s
`McpRegistry` entry.

Environment configuration (`.env`, see {doc}`config`) is not part of this
cache — changing it takes effect immediately, with no rebuild needed.

The on-disk result is one file:

```{code-block} text
.kinetis-cache/
└── compiled.php    routes + global/openapi middleware + named middleware
                    groups + HTTP binding plans + validation plans for
                    DTOs reachable from HTTP routes + command definitions
                    + event listeners grouped by event class + every
                    installed package's own CacheableDiscoveryInterface
                    data + the package bootstrap-class list
```

Plain PHP returning a literal array, so a boot `require`s it and has the
data with no decoding step, and OPcache's shared opcode cache — keyed by
realpath, shared across every worker process on a host — skips
re-parsing it from the second request on.

One file rather than one per section, because a boot needs the same
compile pass throughout: an HTTP boot reconstructs routes, event
listeners and plugin data, and the CLI reconstructs commands, event
listeners and plugin data. Reading them from separate files makes
"routes from one build, listeners from another" a state a mid-deploy
request can land in. Reading them from one file makes it unrepresentable.

Reconstruction is still only what an entry point uses — an HTTP boot
never builds a `CommandRegistry` — but never from a file the rest of the
artifact didn't come with.

### Publishing atomically

`CacheStore::write()` never modifies the live file. It renders the whole
artifact into a uniquely-named temporary file beside it, `require`s that
file back to confirm it returns the array it was rendered from, and only
then `rename()`s it onto `compiled.php` — atomic within one directory on
POSIX, a directory-entry swap rather than a data copy. A reader sees the
complete previous artifact or the complete new one, never a partial
write. A publish that fails at any step leaves whatever was already
there untouched, and removes its own temporary file.

The rename is followed by `opcache_invalidate()` where OPcache is
loaded. It reaches the calling process's own OPcache and nothing else. A
boot that compiles in memory and publishes the result goes on serving
that in-memory bundle, so invalidation is not what makes the fallback
publish usable. What it covers is narrower: this process may already
have required a stale or rejected artifact from this path, and clearing
that entry makes a later include by this same process eligible to see
the replacement.

It does not reach a separate serving pool, and that is what decides how
this is deployed. See "Deploying a rebuilt artifact" below.

Concurrent publishers are not serialized. Workers cold-starting against
a missing artifact each compile once and each publish their own complete
copy; whichever rename lands last is what later readers get. Every racing
compile discovers the same classes, so the cost is bounded, one-time
per process, rather than a difference in what gets served.

An artifact that is missing, carries a `CacheFormat::VERSION` this build
does not speak, will not parse, or fails to reconstruct into live objects
is treated as absent: the boot compiles in memory and publishes the
result. Nothing is retained, pinned, or garbage-collected — there is one
file, replaced in place.

The OpenAPI document is not among these. It is generated per request in
development, and in production held in memory by the provider `Kernel`
builds for its own router, for that process's lifetime alone. Nothing is
written anywhere, so a deployment that changes routes or DTOs has
nothing to clear — see {doc}`routing-validation`.

## Two ways to build it

### `bin/kinetis build` — pre-warm ahead of deploy

```{code-block} bash
php vendor/bin/kinetis build
# Compiled routes, MCP tools/resources, commands, and event listeners
# written to /app/.kinetis-cache/compiled.php
```

Run this as part of your deploy step. It compiles from your project's
own source every time and replaces the artifact, whatever was there
before — the published file is an output of this command, never an input
to it. Routes, commands, global
middleware, event listeners, and every installed package's own
`CacheableDiscoveryInterface` data are all found by namespace — see
{doc}`cli` for how.

Before anything is written, the whole compiled artifact is reconstructed
through the same contracts a boot enforces: both the route table and the
command list, the event listeners, and every installed package's own
`CacheableDiscoveryInterface` data, once each. A section whose compiled
data its own `fromArray()` rejects fails the command, publishes nothing
and leaves the previous artifact in place, rather than shipping one every
worker rejects and recompiles.

Build it into the image or artifact you deploy, before any worker
starts — see "Deploying a rebuilt artifact" below for why the shared
path matters.

### Lazy, on first request

If `APP_ENV=production` and no cache exists yet, the very first request
compiles and publishes it — safely, even under concurrent PHP-FPM
workers racing to be "first" against an empty cache directory: each one
publishes its own complete artifact (see "Publishing atomically" above),
never a corrupted or partial one. A boot publishes only what it is
already serving: the fresh compile is reconstructed into live objects
first, so one that cannot become them fails the request instead of being
published for the next process to reject and recompile into the
identical failure. That covers the sections this entry point uses;
`kinetis build`, which has no boot of its own to serve, reconstructs the
whole artifact instead. Every request after that, on any worker, just
loads what's already published. Once the artifact exists, live discovery
never runs again: your `Http`/`Console`/`Events` classes, and any
`#[AsGlobalMiddleware]`-attributed class, aren't reflected again until
the cache is rebuilt with `bin/kinetis build`.

A machine that cannot be written to — a read-only mount, a full disk —
does not take the application down. The boot serves from the value it
compiled in memory and writes one line to the error log naming the
artifact it could not publish. Every later boot on that machine pays the
compile again and reports again, so a permanently unwritable cache
directory is visible rather than silent.

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

## Deploying a rebuilt artifact

The cache is not automatically invalidated on redeploy. Rerun
`bin/kinetis build` as part of your deploy step whenever your
registrations or DTO shapes change.

**Build it before workers start.** `compiled.php` belongs in the
immutable artifact or image a deployment ships — built by the CI job or
the image build, then read by workers that start against it. Nothing has
to be invalidated in that shape, because no process has seen the path
before.

`opcache_invalidate()` reaches only the OPcache of the process that
calls it. `kinetis build` runs on the CLI, in its own process, so under
`opcache.validate_timestamps=0` it cannot make a same-path replacement
visible to an FPM pool or a FrankenPHP worker that is already serving —
those keep the previous file's opcodes no matter how many times the
command reports success. If you do run `build` against a live shared
deployment, restart the serving pool or workers afterwards; until then
the new artifact is not guaranteed active. A restart is what a code
change needs under a persistent worker anyway (see
{doc}`runtime-adapters`).

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
