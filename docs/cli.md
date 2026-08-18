# CLI

Kinetis ships one binary, `bin/kinetis`, installed as `vendor/bin/kinetis`
once you `composer require kinetis/framework`. Running it with no
arguments, or an unrecognized one, lists every available command:

```{code-block} bash
php vendor/bin/kinetis
```

```{code-block} text
Usage: kinetis <command>

Available commands:
  mcp:serve              Starts the MCP server over stdio
  routes:list            Displays every discovered route and the full global middleware pipeline
  build                  Compiles routes, MCP tools/resources, commands, and OpenAPI data ahead of time
  app:cleanup-sessions   Deletes sessions older than 30 days
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
nothing to register.

Abstract classes, interfaces, traits and enums are skipped: none of them
can be instantiated as a command, controller, tool or listener. And an
attribute is only read from the class it is written on — see
[Where attributes are read from](#where-attributes-are-read-from) below.

Run a command by name:

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
php vendor/bin/kinetis build
```

Removes any existing `.kinetis-cache/` and compiles a fresh one — routing,
MCP, commands, and OpenAPI data. Run this as part of your deploy pipeline
to pre-warm the cache before real traffic arrives — see {doc}`caching`
for exactly what gets written.

Always runs, regardless of `APP_ENV` — safe to run from a CI runner, a
laptop, or any machine that hasn't set that variable. It also runs
**without the application's own configuration**: `build` never executes
`bootstrap.php`, so nothing your bootstrap registers — a database
connection factory demanding `DB_PASSWORD`, a client demanding API keys
— can make it fail. A CI pipeline pre-warms the cache with no
production secrets present.

Your own commands get the same choice: `#[Command]` accepts
`bootstrap: false` for any command that only operates on the project's
static shape and shouldn't require the configuration the application's
services demand. The default (`true`) executes `bootstrap.php` before
dispatch, exactly what a command that talks to real services wants.

Pass `--destroy` to remove `.kinetis-cache/` without rebuilding it:

```{code-block} sh
vendor/bin/kinetis build --destroy
```

## `kinetis mcp:serve`

```{code-block} bash
php vendor/bin/kinetis mcp:serve
```

Starts Kinetis's MCP server over stdio — one JSON-RPC message per line in,
one per line out — the way Claude Desktop, Cursor, and most local MCP
clients launch a server as a subprocess. Your own `App\Mcp\...` tools and
resources are included automatically, alongside Kinetis's own
documentation resources — see {doc}`mcp` for the protocol itself and
{doc}`caching` for how production caching applies here.

## `kinetis routes:list`

```{code-block} bash
php vendor/bin/kinetis routes:list
```

```{code-block} text
Global middleware (outermost to innermost):
  1. Kinetis\Http\Middleware\SecurityHeadersMiddleware
  2. Kinetis\Http\Middleware\ExceptionHandlerMiddleware
  3. Kinetis\Http\Middleware\MaxBodySizeMiddleware
  4. App\Http\Middleware\RequestIdMiddleware

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
the three Kinetis always wires in first — `SecurityHeadersMiddleware`,
`ExceptionHandlerMiddleware`, `MaxBodySizeMiddleware` — then your own
explicitly-registered (`AppScope::middleware()`) and `#[AsGlobalMiddleware]`-discovered
classes, deduplicated (see {doc}`middleware`). Each route's own
`Middleware` column shows its `#[Middleware]` list in the same
class-level-then-method-level order it actually runs in, one middleware
per line — every line but the last ends with `->` to mark it continues on
the next, so a route stacking several classes never forces one
unreasonably wide line. A route with none shows `—`.

A `@name` middleware-group reference (see {doc}`middleware`) is shown
already expanded into the classes that actually run, each annotated with
the group it came from:

```{code-block} text
Method  Path                    Status  Controller                        Middleware
------  ----------------------  ------  --------------------------------  ------------------------------------------------
GET     /orders/{id}/refund     200     App\Http\OrderController::refund  App\Http\Middleware\AuthMiddleware (@admin) ->
                                                                         App\Http\Middleware\RequireAdminMiddleware (@admin)
```

## Package-provided commands and services

Installed packages plug into the same discovery your own application
uses. A package declares its participation in its `composer.json` under
`extra.kinetis`:

```{code-block} json
:caption: A package's composer.json

{
    "extra": {
        "kinetis": {
            "scan": "Acme\\Reports\\Console\\",
            "bootstrap": "Acme\\Reports\\PackageBootstrap"
        }
    }
}
```

Both keys are optional. `scan` is a comma-separated list of PSR-4
namespace prefixes (each must sit at or below one of the package's own
declared PSR-4 roots); every class under them joins the same
attribute-driven discovery as your application's own code — `#[Command]`
methods become `kinetis` commands, and `#[Get]`/`#[McpTool]`/
`#[Listener]`/`#[AsGlobalMiddleware]` classes register the same way.
`bootstrap` names a class implementing
`Kinetis\Container\PackageBootstrapInterface`; its `register(AppScope
$app, Config $config)` runs before your application's own
`bootstrap.php`, so a package can bind its services from configuration
alone, and anything your `bootstrap.php` registers afterward wins over a
package's binding for the same id. A package bootstrap should stay inert
when its configuration is absent — wiring, not side effects.

Installing a package is what opts it in — there is no separate
allow-list. If you install a package, you trust what it registers, the
same trust already extended to any code Composer autoloads.

`kinetis/migrations`, `kinetis/queue`, and `kinetis/session` ship their
commands this way:

```{code-block} sh
vendor/bin/kinetis migrate                        # apply pending migrations
vendor/bin/kinetis migrate:rollback               # roll back the last one
vendor/bin/kinetis migrate:status                 # applied/pending listing
vendor/bin/kinetis migrate:make "create users"    # scaffold a migration file
vendor/bin/kinetis queue:work --queue=high,default
vendor/bin/kinetis queue:stats --queue=high,default
vendor/bin/kinetis queue:clear --queue=default --force
vendor/bin/kinetis session:gc                     # delete expired sessions
```

The `migrate*` commands connect through the same `DB_*` keys as
{doc}`persistence`; `--connection=<name>` targets a named `DB_{NAME}_*`
connection block, winning over the `MIGRATE_CONNECTION_NAME` environment
key when both are given. `queue:work` runs the worker loop against the
backend `QUEUE_CONNECTION` selects, checking queues in the given
priority order. Full docs in {doc}`migrations`, {doc}`queue`, and
{doc}`session`.

## Development vs. production

In development, commands are discovered fresh on every invocation, so a
newly-added `#[Command]` method is picked up immediately. In production
(`APP_ENV=production`, the default when unset), the binary loads its
command list from the compiled cache `kinetis build` produces, compiling
one automatically on the first invocation if none exists yet.

## Where attributes are read from

An attribute applies to the class it is written on, and nowhere else.
This is PHP's own rule for class attributes — a subclass never inherits a
parent's — and Kinetis applies the same rule to methods, which PHP leaves
open: `ReflectionClass::getMethods()` returns a parent's methods flattened
in with a class's own, each still carrying whatever attributes were
written on it further up.

So a routed method, a `#[Command]`, an `#[McpTool]` or a `#[Listener]`
must be declared by the class being registered. One inherited from a
parent is rejected at registration rather than silently registered
against a class whose own attributes would then go unread:

```{code-block} php
abstract class BaseController
{
    #[Get('/health')]                 // belongs to BaseController
    public function health(): array { ... }
}

#[Hidden]                             // would never be consulted
final class InternalController extends BaseController {}
```

Share a routed method through a trait instead. PHP reports a trait
method's declaring class as the class that *uses* the trait, so it counts
as that class's own and every attribute on that class applies normally:

```{code-block} php
trait HealthRoute
{
    #[Get('/health')]
    public function health(): array { ... }
}

#[Hidden]
final class InternalController
{
    use HealthRoute;                  // registered, and hidden
}
```

Attributes written on the trait declaration itself are ignored — only its
methods carry through. Extending a base class for ordinary shared
behaviour stays perfectly legal; only an inherited method that carries an
attribute is an error.

## Restricting discovery

Once you're relying on the compiled cache in production, scanning the
whole application on every request is not the relevant cost — the
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
never subject to your application's own discovery scope. The same goes
for anything an installed package registers through `extra.kinetis` (see
above): a package's own declared scan roots are read as given, outside
these variables' reach.
