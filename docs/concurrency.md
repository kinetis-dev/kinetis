# Concurrency

`Kinetis\Async` is a thin wrapper over [Revolt](https://revolt.run/), the
AMPHP v3 event loop. PHP Fibers are cooperative coroutines; they need an
event loop scheduling I/O around them. Everything on this page is a small
set of primitives built on Revolt's suspend/resume pattern.

## The suspend/resume pattern

Every non-blocking primitive in `Kinetis\Async` is built the same way:
capture the currently-running `Fiber`, register a Revolt watcher for
whatever condition you're waiting on, then suspend — and let the watcher's
callback resume the fiber once that condition is met.

```{code-block} php
:caption: The pattern Socket/Timer both reduce to

use Revolt\EventLoop;

$fiber = Fiber::getCurrent();

EventLoop::onReadable($stream, static function (string $watcherId) use ($fiber): void {
    EventLoop::cancel($watcherId);
    $fiber?->resume();
});

Fiber::suspend();
```

`Timer::delay()` is the simplest concrete example — a Fiber-suspending
delay with nothing to read or write, useful mainly as a deterministic way
to *prove* concurrency actually overlaps, without depending on real network
timing in a test:

```{code-block} php
use Kinetis\Async\Timer;

Timer::delay(0.05); // suspends this Fiber for 50ms, without blocking the process
```

```{important}
**What this buys you, precisely.** Every primitive on this page provides
concurrency *within the scope of what's currently executing* — most
concretely, several independent pieces of one request's own work (see
`concurrently()` below). It does not, by itself, let one worker serve a
second, unrelated incoming HTTP request while the first is suspended
waiting on I/O — under a persistent worker (FrankenPHP or RoadRunner)
specifically, each worker (a thread under FrankenPHP, a process under
RoadRunner) processes one request fully before picking up the next;
cross-request concurrency there comes from the number of workers, not
from this suspend/resume mechanism. See {doc}`runtime-adapters`'s
"Sizing FrankenPHP's worker threads" and "Sizing RoadRunner's worker
processes" sections for what that means in practice and how to size for
it.
```

## `Socket` — non-blocking TCP

`connect()`/`read()`/`write()` suspend the calling Fiber while the
underlying stream isn't ready, rather than blocking on it — the worker is
free to run other Fibers, or process other watchers, for however long that
takes. Calling any of these methods **outside a Fiber is a programming
error** — there's nothing to resume — and surfaces as PHP's own
`FiberError`, not a silent hang. In practice that means calling `Socket`
from inside a `concurrently()` task (below), which is what actually
supplies the Fiber — a bare top-level `Socket::connect(...)` call with no
surrounding Fiber will throw:

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

```{warning}
Wrapping a blocking call in a Fiber does not make it non-blocking — see
{ref}`non-blocking-application-io`.
```

## `concurrently()` — running tasks side by side

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Redis\Client;

use function Kinetis\Async\concurrently;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
        private Client $redis,
    ) {}

    #[Get('/orders/{id}/summary')]
    public function summary(int $id): array
    {
        [$order, $itemCount, $views] = concurrently([
            fn () => new Query($this->db)->table('orders')->where('id', '=', $id)->first(),
            fn () => new Query($this->db)->table('order_items')->where('order_id', '=', $id)->count(),
            fn () => $this->redis->execute('GET', "order:{$id}:views"),
        ]);

        return ['order' => $order, 'itemCount' => $itemCount, 'views' => (int) $views];
    }
}
```

`MysqlLink` is bound by `kinetis/database-bridge` from the `DB_*` keys.
`Kinetis\Redis\Client` has no boot-time binding — `bootstrap.php` builds
it from `Client::create()` and binds it, as {doc}`redis` shows.

A database row, a database count, and a Redis read — three independent
round trips that would otherwise run one after another — complete
together, in roughly the time the slowest one alone takes.

Each task runs in its own `Fiber`, drawn from a pool of *resident
workers* (`Kinetis\Async\FiberPool`) that park between tasks instead of
terminating. Constructing a `Fiber` allocates a whole C stack and
destroying it frees one, so reusing a parked resident keeps a fan-out
from paying that construction and destruction cost per task. The pool is
per PHP thread and holds only idle Fibers — a task suspended on I/O keeps
its Fiber to itself until it finishes — up to a bounded number of them: a
wider burst still runs, on fresh Fibers that aren't retained afterwards. None of it is visible in the API: you write plain closures,
exactly as above.

A resident Fiber outlives the task that parked it, so the next task to
run on it may belong to a later batch — and, in a persistent worker, to a
later request. Anything that attaches Fiber-local or Fiber-keyed state
must detach or release it before the task returns, on both the success
and the failure path, or a later task inherits it. Fiber identity is the
carrier that executes a task, not an identifier for that task, its batch,
or its request; do not key anything on it that has to outlive the task.
Span scopes follow this rule — see {ref}`telemetry-fiber-scopes`.

While tasks are in flight, the caller waits on a Revolt suspension that
the last task to finish resumes — the event loop drives every suspended
task no matter how many times each one suspends internally, or in what
order they finish.

**Every task runs to completion — successfully or not — before any
exception is allowed to surface.** A failing task doesn't abort the others
still in flight; if one or more failed, the first failure (in `$tasks`
order) is rethrown only once everything has finished:

```{code-block} php
try {
    concurrently([
        fn () => $slowButSuccessful(),
        fn () => throw new RuntimeException('this one fails'),
    ]);
} catch (RuntimeException $e) {
    // $slowButSuccessful() still ran to completion — it just wasn't
    // allowed to abort the other task's own error from surfacing.
}
```

Three 50ms `Timer::delay()` calls run through `concurrently()` complete in
well under 100ms total, not the ~150ms+ a sequential run would take.

```{note}
**Nesting is supported.** A task may itself call `concurrently()` — the
inner call suspends only its own task's Fiber while the inner tasks run,
and everything else keeps making progress. Prefer a single flat
`concurrently()` call when the work is naturally one batch — a flat
list is easier to reason about and slightly cheaper — but nesting that
falls out of composition, such as a helper that fans out internally
being called from a task, is correct and safe.
```

## Composing across clients

Kinetis's database drivers (see {doc}`persistence`) and the Redis client
(`Kinetis\Redis\Client`, over the non-replaying transport `kinetis/redis`
owns — see {doc}`redis`) all wait by suspending the calling Fiber on the
same underlying Revolt loop — the native Postgres driver through a real
socket watcher, the native MySQL driver through its poll bridge, Redis
through `Amp\Future` internally. `Amp\Redis\RedisClient` is an optional
typed command facade over that same transport, reached through
`Client::link()`, and suspends the same way. Different API shapes, one
loop: a `concurrently()` call can mix tasks built on any of them and
still overlap every one — a MySQL query, a Postgres query, and a Redis
command issued together complete in roughly the time the slowest one
alone takes, not the sum of all three.

(non-blocking-application-io)=
## Keeping application I/O non-blocking

Wrapping a blocking call in a Fiber or a `concurrently()` task does not
make it non-blocking. A blocking call has no point where it can hand
control back to the event loop, so it holds the worker, and every other
Fiber and watcher on its loop, until it returns: the other tasks of a
`concurrently()` call wait, and so does every deadline and timer the loop
enforces meanwhile. Only calls that wait on the loop, such as `Socket`,
`Timer`, and the clients above, let the rest make progress.

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

- {doc}`persistence` — the database drivers and Redis client described
  above, and `TransactionGuard`, the request-lifecycle safety net built
  specifically because connection pools have no concept of
  Kinetis's `RequestScope`.
- {doc}`performance-tuning` — the worker-threads x connections
  budget, what to observe under load, and tuning by workload shape.
