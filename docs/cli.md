# CLI

Kinetis ships one binary, `bin/kinetis`, installed as `vendor/bin/kinetis`
once you `composer require kinetis/framework`. Running it with no
arguments, or an unrecognized one, lists every available command:

```{code-block} bash
php bin/kinetis
```

```{code-block} text
Usage: kinetis <command>

Available commands:
  mcp:serve — Starts the MCP server over stdio
  routes:list — Displays every discovered route and the full global middleware pipeline
  build — Compiles routes, MCP tools/resources, commands, and OpenAPI data ahead of time
  app:cleanup-sessions — Deletes sessions older than 30 days
```

## Writing your own commands

Kinetis deliberately doesn't schedule anything itself — that's your
infrastructure's job (cron, a Kubernetes CronJob, an EventBridge rule,
whatever you already use). What it gives you is a stable, named way to
define a command so that infrastructure — or you, by hand — can actually
run it:

```{code-block} php
namespace App\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;

final readonly class MaintenanceController
{
    #[Command('app:cleanup-sessions', description: 'Deletes sessions older than 30 days')]
    public function cleanupSessions(): void
    {
        // ...
    }

    #[Command('app:send-report', description: 'Emails a report to a given address')]
    public function sendReport(CommandArguments $arguments): int
    {
        $email = $arguments->get(0);

        if ($email === null) {
            fwrite(STDERR, "Usage: app:send-report <email>\n");
            return 1;
        }

        // ...
        return 0;
    }
}
```

Any class anywhere under one of your own PSR-4 roots is picked up
automatically — `App\Console\...`, `App\Domain\Orders\...`, wherever you
keep it. There's no required directory or namespace convention to follow;
organize commands however the rest of your application is organized.
Discovery reaches a class through its PSR-4 file path, so the standard
autoloading layout is what makes a class findable: one class per file,
the file named for the class. A second class declared inside an existing
file isn't PSR-4-autoloadable, so discovery never sees it. There's
nothing to register. Run a command by name:

```{code-block} sh
vendor/bin/kinetis app:cleanup-sessions
vendor/bin/kinetis app:send-report ops@example.com --dry-run
```

A command method takes either no parameters, or exactly one parameter
typed `CommandArguments` — everything else it needs (a database pool, a
mailer, ...) is constructor-injected, exactly like a controller.
`CommandArguments` splits whatever followed the command's own name into
positional values (`get(0)`, `get(1)`, ...) and `--key=value`/bare
`--flag` options (`option('key')`, `hasOption('flag')`).

A command's own return value becomes the process's exit code — an `int`
is used directly; `void`/`null` means success (`0`). This is the actual
signal your scheduler reads to decide whether to alert or retry, so a
command that can fail should say so with a non-zero return rather than
only logging the problem. An uncaught exception is caught once, logged
through whatever `Psr\Log\LoggerInterface` you've registered (see
{doc}`logging`), and also produces exit code `1`.

MCP tools and resources (see {doc}`mcp`) and HTTP routes (see
{doc}`routing-validation`) work the same way — discovered anywhere under
your own PSR-4 roots, with no directory convention required.

## `kinetis build`

```{code-block} bash
php bin/kinetis build
```

Removes any existing `.kinetis-cache/` and compiles a fresh one — routing,
MCP, commands, and OpenAPI data. Run this as part of your deploy pipeline
to pre-warm the cache before real traffic arrives — see {doc}`caching`
for exactly what gets written.

Always runs, regardless of `APP_ENV` — safe to run from a CI runner, a
laptop, or any machine that hasn't set that variable.

Pass `--destroy` to remove `.kinetis-cache/` without rebuilding it:

```{code-block} sh
vendor/bin/kinetis build --destroy
```

## `kinetis mcp:serve`

```{code-block} bash
php bin/kinetis mcp:serve
```

Starts Kinetis's MCP server over stdio — one JSON-RPC message per line in,
one per line out — the way Claude Desktop, Cursor, and most local MCP
clients launch a server as a subprocess. Your own `App\Mcp\...` tools and
resources are included automatically, alongside Kinetis's own
documentation resources — see {doc}`mcp` for the protocol itself and
{doc}`caching` for how production caching applies here.

## `kinetis routes:list`

```{code-block} bash
php bin/kinetis routes:list
```

```{code-block} text
Global middleware (outermost to innermost):
  1. Kinetis\Http\Middleware\ExceptionHandlerMiddleware
  2. App\Http\Middleware\RequestIdMiddleware

Method  Path     Status  Controller                       Middleware
------  -------  ------  -------------------------------  ---------------------------------------
GET     /orders  200     App\Http\OrderController::index  App\Http\Middleware\AuthMiddleware
POST    /orders  201     App\Http\OrderController::store  App\Http\Middleware\AuthMiddleware ->
                                                          App\Http\Middleware\RateLimitMiddleware
```

A read-only display tool — it never touches `.kinetis-cache/` and never
writes anything. Every invocation is a fresh, live discovery of your
current source, regardless of `APP_ENV`, so it always reflects your code
exactly as it stands right now, not whatever a stale compiled cache
happens to hold.

The global middleware section lists the exact order requests run in —
`ExceptionHandlerMiddleware` always first, then your own
explicitly-registered (`AppScope::middleware()`) and `#[AsGlobalMiddleware]`-discovered
classes, deduplicated (see {doc}`middleware`). Each route's own
`Middleware` column shows its `#[Middleware]` list in the same
class-level-then-method-level order it actually runs in, one middleware
per line — every line but the last ends with `->` to mark it continues on
the next, so a route stacking several classes never forces one
unreasonably wide line. A route with none shows `—`.

## Development vs. production

In development, commands are discovered fresh on every invocation, so a
newly-added `#[Command]` method is picked up immediately. In production
(`APP_ENV=production`, the default when unset), the binary loads its
command list from the compiled cache `kinetis build` produces, compiling
one automatically on the first invocation if none exists yet.

## Restricting discovery

Once you're relying on the compiled cache in production, scanning the
whole application on every request is no longer the relevant cost — the
scan only ever runs live in development, or once to build the cache.
Even so, for a large enough codebase, that development-time scan can be
worth bounding. `COMMAND_DISCOVERY_PATHS` (and its siblings
`MCP_DISCOVERY_PATHS`/`ROUTE_DISCOVERY_PATHS`/`MIDDLEWARE_DISCOVERY_PATHS`/
`LISTENER_DISCOVERY_PATHS` for MCP, HTTP, global-middleware, and event-
listener discovery — see {doc}`middleware`/{doc}`events` for the last two)
restricts the scan to one or more comma-separated sub-paths, relative to
each PSR-4 base directory your `composer.json` declares:

```{code-block} text
:caption: .env

COMMAND_DISCOVERY_PATHS=Console
```

With `"App\\": "src/"` in your `autoload.psr-4`, this restricts command
discovery to `src/Console/` — a class anywhere else under `src/` is no
longer scanned. Most small and medium applications never need this; it's
meant for a team that has measured a real, unacceptable scan cost and
wants a deliberate, git-tracked restriction instead of relying on every
developer to remember one. Kinetis's own built-in commands, tools,
resources, middleware, and listeners (under `Kinetis\Console`/`Kinetis\Mcp`/
`Kinetis\Http`/`Kinetis\Events`) are unaffected by any of these five
variables either way — they're always found in their own fixed location,
never subject to your application's own discovery scope.
