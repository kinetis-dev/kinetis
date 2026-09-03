# Logging

Kinetis's logging is plain [PSR-3](https://www.php-fig.org/psr/psr-3/) —
`Psr\Log\LoggerInterface` — not a Kinetis-specific contract. Any PSR-3
logger works: register it once, and everything inside Kinetis that logs
uses it automatically.

## Registering your own logger

```{code-block} php
use Kinetis\Container\AppScope;
use Psr\Log\LoggerInterface;

$app = new AppScope();
$app->instance(LoggerInterface::class, $yourLogger);
$app->boot();
```

Register it before `boot()`, the same discipline as every other
`AppScope` binding — see {doc}`container`.

If you never register one, `AppScope::boot()` binds a default that
depends on the environment: in development
(`APP_ENV=development`), `Kinetis\Logging\ErrorLogLogger`, a minimal
PSR-3 logger writing through `error_log()` — so an exception during
local development always leaves a trail in the server log with zero
setup; in production, `Psr\Log\NullLogger`, which silently discards
everything. Framework internals resolve `LoggerInterface` through the
container, so something is always bound by the time they run, and your
own registration wins in both environments.

## Where Kinetis logs, and why

Nowhere by default on a successful request — logging every request
regardless of outcome is something you opt into with your own
{doc}`global middleware <middleware>`, not something the framework forces.
Every place framework internals log on their own is a genuine anomaly
signal, not routine chatter:

### `ExceptionHandlerMiddleware`

A global middleware every `Kernel` wires in unconditionally
(see {doc}`middleware`). Resolved through the container, so its
`LoggerInterface` autowires from whatever you registered:

```{code-block} text
error: Unhandled exception while handling {method} {path}: {message}
  method: POST
  path: /users
  message: ...
  exception: <the Throwable itself>
```

The `exception` context key follows PSR-3 convention — Monolog and other
real loggers look for exactly that key to render a stack trace.

A broken `Kinetis\Http\Exception\HttpStatusExceptionInterface`
implementation — `httpStatus()` throwing, or returning a value outside
the 400-599 range the interface requires (see {doc}`middleware`) — logs
the same way, `exception` still the original, and one further context
key, `mappingFailure`, holding a `Middleware\Exception\HttpStatusMappingException`
describing what went wrong trying to map it, chaining the real secondary
cause via `getPrevious()` when there was one.

This log call is best-effort: a registered logger that itself throws
cannot prevent the `500` this middleware exists to guarantee. The same
is true wherever else a framework internal logs from inside a fallback
it must still honor regardless — `Http\OpenApi\DocumentationController`'s
cache read/write-failure warnings, for one, see {doc}`appendix`. See
`Kinetis\Logging\SafeLogger`.

### `TransactionGuard::rollbackDangling()`

Registered as a `RequestScope` dispose hook on every request whenever
`kinetis/persistence` is installed (see {doc}`persistence`), so it runs
whether or not a request ever opened a transaction. It logs a `warning`
only once it has actually
rolled back an active transaction — the overwhelming majority of calls
are a genuine no-op, and logging on every one of them regardless would
turn a real anomaly signal into noise. If closing a transaction fails,
that's logged as an `error` instead, one line per failure — a strictly
more severe signal than the routine `warning`, since it means a
transaction survived the request. See {doc}`persistence`'s "What happens
when cleanup itself fails" for the full failure behavior, including that
this is all independent of the logger itself: an exception the logger
throws is discarded rather than allowed to affect cleanup or misreport
what actually happened.

### `McpServer`'s top-level exception handler

The JSON-RPC transport's counterpart to `ExceptionHandlerMiddleware`: an
unexpected `Throwable` reaching `McpServer::handle()`'s outer catch
becomes a `-32603 Internal error` response, logged the same way (see
{doc}`mcp`). `McpServer` is constructed directly by you — over stdio via
`bin/kinetis mcp:serve`, or passed to `Kernel`'s `$mcp` parameter — rather
than resolved through the container, so pass your logger explicitly:

```{code-block} php
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpServer;

$mcp = new McpServer($registry, new McpDispatcher($app), logger: $app->get(Psr\Log\LoggerInterface::class));
```

`bin/kinetis mcp:serve` already does this for you, pulling whatever's
registered on its own `AppScope`.

### `RequestScope` disposal failures

Every owner that disposes a `RequestScope` after its own unit of work has
already produced a real outcome — `Kernel`, `kinetis/queue`'s
`QueueWorker`/`SyncQueue`, the MCP stdio and streamed-HTTP transports, and
`bin/kinetis` — logs a disposal failure separately from that outcome,
rather than letting it replace or suppress it (see {doc}`container`'s own
general explanation of why, and each owner's own page —
{doc}`middleware`, {doc}`queue`, {doc}`mcp`, {doc}`cli` — for its exact
precedence rule). Every one of these calls goes through
`Kinetis\Logging\SafeLogger::logFrom()`, not `log()`: `log()` alone only
protects the resolved logger's own `log()` call, but
`$container->get(LoggerInterface::class)` is itself evaluated as a plain
argument expression before any containment is ever entered, so a
throwing `LoggerInterface` binding would escape uncaught right at the
point disposal tries to report its own failure. `logFrom()` takes the
resolution itself as a callable instead, so a broken binding is
contained the same way a broken logger's own `log()` call already is —
the same best-effort discipline as every other log call on this page: a
registered logger that itself throws, or fails to resolve at all, cannot
affect the real outcome these calls report on.

## See also

- {doc}`container` — `AppScope::boot()`'s registration-lock discipline in
  full.
- {doc}`middleware` — `ExceptionHandlerMiddleware`'s place in the global
  pipeline.
- {doc}`persistence` — `TransactionGuard`'s full request-lifecycle role.
- {doc}`mcp` — the JSON-RPC error-handling convention `McpServer` follows.
