# Caching & AOT Compilation

This page owns build-time compilation and nothing else. The runtime
key-value cache an application reads and writes —
`Psr\SimpleCache\CacheInterface`, Redis-backed — is {doc}`redis`. Those
two pages are the whole subject; there is no separate runtime-cache page
to look for, and the two caches share a word and nothing else.

In production, Kinetis boots from one build-time artifact,
`.kinetis-cache/compiled.php`, instead of reflecting the application on
every boot: routes, commands, middleware, event listeners, parameter
binding and validation plans, and each installed package's discovery
data. Build it in the deploy step, ship it with the code, and start
workers against it.

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

`Kinetis\Runtime\HttpStartup` reads this once per boot, and `bin/kinetis`
reads it again for the CLI. Development discovers from source on every
boot; production uses the artifact.

## Build the artifact in the deploy step

```{code-block} bash
php vendor/bin/kinetis build
# Compiled routes, MCP tools/resources, commands, and event listeners written to /app/.kinetis-cache/compiled.php
```

`kinetis build` compiles from the project's source every time and
replaces the artifact. The published file is an output of the command,
never an input to it. Routes, commands, global middleware, event
listeners and package discovery data are found by namespace — see
{doc}`cli`.

Before it writes, the command reconstructs the whole artifact through the
same checks a boot applies. A section that fails them fails the command,
publishes nothing, and leaves the previous artifact in place.

If the application uses Latte or Twig, follow the build with
`php vendor/bin/kinetis views:warm`. That command runs the normal
application bootstrap, clears only the selected adapter directory,
recursively compiles the configured view root, and fails on a template
compile error. It therefore needs the deployment's complete environment,
unlike `kinetis build`. Pure PHP and development mode report zero work.
See {doc}`views` for the complete contract.

## Deploying a rebuilt artifact

The artifact is not invalidated automatically. Rerun `kinetis build` in
every deploy that changes routes, commands, listeners, middleware or DTO
shapes.

**Build it before workers start.** `compiled.php` belongs in the
immutable artifact or image a deployment ships — built by the CI job or
the image build, then read by workers that start against it. Nothing has
to be invalidated in that shape, because no process has seen the path
before.

`kinetis build` runs on the CLI, in its own process, and can clear only
that process's OPcache. Under `opcache.validate_timestamps=0` it cannot
make a same-path replacement visible to an FPM pool or a FrankenPHP
worker that is already serving — those keep the previous file's opcodes
no matter how many times the command reports success. If you run `build`
against a live shared deployment, restart the serving pool or workers
afterwards; until then the new artifact is not guaranteed active. A
persistent worker needs that restart for a code change anyway (see
{doc}`runtime-adapters`).

## Without a build step

In production with no usable artifact, the first boot compiles in memory,
serves from that result, and publishes it for later boots. Concurrent
PHP-FPM workers racing on an empty cache directory each publish a
complete artifact, never a partial one. An artifact that is missing,
from an older format, or rejected on load is compiled and replaced the
same way.

Once an artifact exists, live discovery never runs again: a new
controller, command, listener or `#[AsGlobalMiddleware]` class is not
seen until `kinetis build` runs.

A machine that cannot write the cache directory — a read-only mount, a
full disk — still serves. Each boot compiles in memory and writes one
line to the error log naming the artifact it could not publish, so a
permanently unwritable directory is visible rather than silent.

```{note}
Pre-warming avoids one cost: the compile-and-write on whichever request
arrives first. A cold deployment that compiles lazily is consistently
slower than a pre-warmed one for exactly that request.
```

## What changes without a rebuild

`.env` and process environment configuration take effect on the next
boot without rebuilding `compiled.php` ({doc}`config`). The OpenAPI
document is generated in memory from the active route table, rather than
stored in the artifact ({doc}`routing-validation`). Latte and Twig view
adapters keep their generated templates in separate directories under
`.kinetis-cache/views/` ({doc}`views`).

{ref}`runtime-reference-aot-artifact` lists the artifact's exact contents,
file format, atomic publication and rejection behavior.

## See also

- {doc}`cli` — how MCP tools/resources and commands are found by
  namespace, with no registration file for either.
- {doc}`runtime-adapters` — why the artifact matters most under PHP-FPM,
  which boots on every request.
- {doc}`routing-validation` / {doc}`mcp` — the live behavior this
  precomputes; nothing about how routes, tools or DTOs are declared
  changes.
- {doc}`redis` — the runtime `Psr\SimpleCache\CacheInterface` cache, as
  above.
- {doc}`appendix-runtime` — the artifact's format and publication
  protocol.
