# Concurrency

A request often needs several independent answers — a row, a count, a
cached value, an upstream call. `concurrently()` starts them together and
returns every result:

```{code-block} sh
composer require kinetis/database-bridge kinetis/query-builder kinetis/cache-redis
```

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Psr\SimpleCache\CacheInterface;

use function Kinetis\Async\concurrently;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
        private CacheInterface $cache,
    ) {}

    #[Get('/orders/{id}/summary')]
    public function summary(int $id): array
    {
        /** @var array{object|array|null, int, mixed} $result */
        $result = concurrently([
            fn () => new Query($this->db)->table('orders')->where('id', '=', $id)->first(),
            fn () => new Query($this->db)->table('order_items')->where('order_id', '=', $id)->count(),
            fn () => $this->cache->get("order.views.{$id}"),
        ]);
        [$order, $itemCount, $views] = $result;

        return ['order' => $order, 'itemCount' => $itemCount, 'views' => (int) $views];
    }
}
```

`concurrently()` is declared `@template T` / `@param list<callable(): T>
$tasks` / `@return list<T>`: one shared type parameter across every task.
Given heterogeneous closures, PHPStan resolves `T` to the union of all three
return types and reports every destructured element as that same union,
not as its own position's type. The `array{...}` shape above states the
tuple PHPStan can't infer on its own, so `$order`, `$itemCount` and
`$views` keep their real per-position types after destructuring.

Each closure is a task. Results come back in task order, whatever order
the tasks finish in. `MysqlLink` is bound by `kinetis/database-bridge`
from the `DB_*` keys ({doc}`persistence`), and `CacheInterface` is bound
to Redis by `kinetis/cache-redis` from `REDIS_*` configuration
({doc}`redis`).

Under FrankenPHP worker mode or RoadRunner, the two queries and the Redis
read overlap, and the call takes roughly as long as the slowest of them.

(concurrency-overlap)=
## When the overlap is real

Tasks overlap only while each one waits through a client that yields to
the event loop. A blocking call inside a task runs to completion before
any other task continues.

| A task waiting on | FrankenPHP worker, RoadRunner | PHP-FPM, AWS Lambda, CLI, queue workers, PHPUnit |
|---|---|---|
| An injected `SqlLink`, `MysqlLink` or `PostgresLink` with `DB_DRIVER=auto` | Native driver: queries overlap on pooled connections | One blocking PDO connection: queries run one after another |
| `RedisSimpleCache` behind `CacheInterface` | Overlaps | Overlaps |
| `Kinetis\RevoltHttpClient\Http` | Overlaps | Overlaps |
| `Timer::delay()`, `Socket` | Overlaps | Overlaps |
| A hand-built `PDO` or `mysqli` handle, `curl_exec()`, `sleep()`, `file_get_contents()` | Blocks every task | Blocks every task |

```{warning}
The example above returns the same result under PHP-FPM, but not in the
same time. `DB_DRIVER=auto` gives PHP-FPM one blocking PDO connection;
with the tasks ordered as shown, both queries finish before the Redis
read starts. Code that needs query overlap runs under a persistent worker.
Forcing `DB_DRIVER=native` under PHP-FPM builds and discards a connection
pool on every request — {doc}`performance-tuning`'s "What not to tune"
gives the cost.
```

`concurrently()` overlaps one request's own work. It does not let a
worker take a second request while the first waits: a FrankenPHP thread
or a RoadRunner process serves one request at a time, and cross-request
capacity comes from the worker count — see {doc}`runtime-adapters`'s
"Sizing FrankenPHP's worker threads".

## When a task fails

Every task runs to completion, successfully or not, before an exception
surfaces. A failing task does not abort the others; the first failure in
task order is rethrown once all of them have finished:

```{code-block} php
try {
    concurrently([
        fn () => $slowButSuccessful(),
        fn () => throw new RuntimeException('this one fails'),
    ]);
} catch (RuntimeException $e) {
    // $slowButSuccessful() ran to completion before this was thrown.
}
```

A task may itself call `concurrently()`. The inner call suspends only its
own task while the inner tasks run. Prefer one flat call when the work is
naturally one batch; nesting that falls out of composition, such as a
helper that fans out internally, is correct.

```{important}
Tasks run on reused Fibers, and a Fiber that ran one task may run a task
from a later request. Do not keep state keyed on the current Fiber, or
attach Fiber-local state that outlives the task — see
{ref}`runtime-reference-fibers`.
```

## Timers and sockets

`Timer::delay()` suspends the current task without blocking the process,
which makes it a deterministic way to show overlap in a test: three 50 ms
delays through `concurrently()` finish in well under 100 ms.

```{code-block} php
use Kinetis\Async\Timer;

Timer::delay(0.05);
```

`Kinetis\Async\Socket` is a non-blocking TCP client. `connect()`,
`read()` and `write()` suspend the current task while the stream is not
ready. Call them inside a `concurrently()` task: outside a Fiber there is
nothing to resume, and PHP throws `FiberError`.

```{code-block} php
use Kinetis\Async\Socket;

use function Kinetis\Async\concurrently;

[$response] = concurrently([
    function (): string {
        $socket = Socket::connect('example.com', 80);
        $socket->write("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n");

        return $socket->read(4096);
    },
]);
```

`concurrently()` itself needs no surrounding Fiber, so a controller, a
console command or a test calls it directly.

(non-blocking-application-io)=
## Keeping application I/O non-blocking

Wrapping a blocking call in a Fiber or a `concurrently()` task does not
make it non-blocking. A blocking call has no point where it can hand
control back to the event loop, so it holds the worker, and every other
Fiber and watcher on its loop, until it returns: the other tasks of a
`concurrently()` call wait, and so does every deadline and timer the loop
enforces meanwhile. Only calls that wait on the loop, such as `Socket`,
`Timer`, and the clients in the table above, let the rest make progress.

### Flagging blocking calls with PHPStan

`Kinetis\Linting\NoBlockingIoRule` is a PHPStan rule for application code.
It ships under the framework's main autoload for the same reason
`NoStaticPropertiesRule` does (see {doc}`container`) and needs nothing
beyond PHPStan. `kinetis/skeleton`'s `phpstan.neon` registers both rules;
an existing project adds it to its own:

```{code-block} yaml
:caption: phpstan.neon

rules:
    - Kinetis\Linting\NoBlockingIoRule
```

Every report carries the identifier `kinetis.blockingCall` and a message
naming the replacement for its category:

| Category | Reported | Use instead |
|---|---|---|
| Sleep | `sleep()`, `usleep()`, `time_nanosleep()`, `time_sleep_until()` | `Kinetis\Async\Timer::delay()` |
| Sockets | `fsockopen()`, `pfsockopen()`, `stream_socket_client()` | `Kinetis\Async\Socket` or another Revolt-aware socket; {doc}`revolt-http-client` for HTTP |
| curl waits | `curl_exec()`, `curl_multi_select()` | {doc}`revolt-http-client` |
| Database connections | `new PDO`, `new mysqli`, `mysqli_connect()`, `pg_connect()`, `pg_pconnect()` | an injected `SqlLink`, `MysqlLink`, or `PostgresLink` — see {doc}`persistence` |
| Child processes | `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `popen()` | a separate short-lived or external process, or an audited short-lived console command — never a persistent worker, `queue:work` included |
| HTTP transport selection | `find()` on `Http\Discovery\Psr18ClientDiscovery`, `HttpClientDiscovery`, and `HttpAsyncClientDiscovery`; `create()` and `createForBaseUri()` on `Symfony\Component\HttpClient\HttpClient`; `new Http\Discovery\Psr18Client`, `new Symfony\Component\HttpClient\HttplugClient`, and `new Symfony\Component\HttpClient\Psr18Client` with the client argument absent or a literal `null`; `new GuzzleHttp\Client` | an explicitly injected Revolt-backed client, below |

`curl_multi_exec()` is not reported: it advances transfers without
waiting, and `curl_multi_select()` is the wait.

Names resolve the way PHP resolves them. An imported or aliased function
or class is reported under its real name, and an unqualified `sleep()`
inside a namespace that declares its own `sleep()` calls that function and
is not reported.

### Injecting the HTTP transport

`php-http/discovery` returns the first supported client it finds
installed — Guzzle, a curl or socket adapter, Symfony's. Symfony's
`HttpClient::create()` prefers `CurlHttpClient` over `AmpHttpClient`
when ext-curl supports HTTP/2, even with `amphp/http-client` installed.
Guzzle's default handler is curl, or PHP streams without it. None of
these is a Revolt-backed transport by guarantee, and which one a
deployment gets can change with an unrelated `composer require` or PHP
extension.

For calls the application makes itself, inject
`Kinetis\RevoltHttpClient\Http` (see {doc}`revolt-http-client`). For a
library that takes a PSR-18 client, construct one around the
Revolt-backed transport and bind it:

```{code-block} php
:caption: bootstrap.php

use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\Psr18Client;

$app->instance(ClientInterface::class, new Psr18Client(AmpHttpClientFactory::create()));
```

A `Psr18Client` from either package, or Symfony's `HttplugClient`, given
a known non-null transport like the one above uses it and is not reported.

### Audited exceptions

Code that never runs inside a persistent worker — an audited short-lived
console command, a build script — can block without stalling other work.
Exempt it with PHPStan's standard ignores, by identifier and path, with
the reason beside it:

```{code-block} yaml
:caption: phpstan.neon

parameters:
    ignoreErrors:
        # A short-lived CLI command; never runs inside a persistent worker.
        -
            identifier: kinetis.blockingCall
            path: src/Console/ImportCommand.php
```

For a single line, put
`/** @phpstan-ignore-next-line kinetis.blockingCall */` on the line before
it instead.

### What the rule does not see

- **Dynamic calls.** A function or class named by an expression —
  `$function()`, `new $class()`, `call_user_func('sleep', 1)` — is not
  reported.
- **Nullable client arguments.** The rule treats the client argument of
  these adapters as intentional injection unless it is a literal `null`. An
  expression that evaluates to `null` at runtime still makes the client
  pick its own transport, so guard a nullable transport before passing it.
- **Filesystem and DNS.** `file_get_contents()` and `fopen()` on a local
  path or a URL, `gethostbyname()`, and the name lookup inside connecting
  to a hostname all block, and none of them is reported: there is no
  universal non-blocking replacement for either. {doc}`storage` serves
  local files through a Fiber-suspending adapter.
- **Dependencies.** PHPStan analyses the project's own `paths`, so a
  blocking call inside a vendor package is not reported.
- **CPU-bound work.** A long computation holds the loop exactly as a
  blocking call does.

A clean analysis is therefore not proof that a path keeps the loop
responsive. {ref}`loop-liveness` observes that directly in a test.

## See also

- {doc}`persistence` — driver selection, pools, and `TransactionGuard`.
- {doc}`redis` and {doc}`revolt-http-client` — the non-blocking Redis and
  HTTP clients.
- {doc}`performance-tuning` — the worker × connection budget and tuning
  by workload shape.
- {doc}`telemetry` — overlapping tasks as overlapping spans.
- {doc}`appendix-runtime` — Fiber scheduling, resident Fibers, and what
  each client waits on.
