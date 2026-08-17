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
The three places framework internals log on their own are all genuine
anomaly signals, not routine chatter:

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

### `TransactionGuard::rollbackDangling()`

Registered as an unconditional `RequestScope` dispose hook on every
request (see {doc}`persistence`), so it runs whether or not a request
ever opened a transaction. It logs a `warning` only when it actually
finds an active transaction to roll back — the overwhelming majority of
calls are a genuine no-op, and logging on every one of them regardless
would turn a real anomaly signal into noise.

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

## See also

- {doc}`container` — `AppScope::boot()`'s registration-lock discipline in
  full.
- {doc}`middleware` — `ExceptionHandlerMiddleware`'s place in the global
  pipeline.
- {doc}`persistence` — `TransactionGuard`'s full request-lifecycle role.
- {doc}`mcp` — the JSON-RPC error-handling convention `McpServer` follows.
