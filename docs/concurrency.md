# Concurrency

`Kinetis\Async` is a thin wrapper over [Revolt](https://revolt.run/) — the
AMPHP v3 event loop — not a hand-rolled reactor. PHP Fibers alone are just
cooperative coroutines; they need an event loop scheduling I/O around them,
and Revolt is the PHP ecosystem's de facto standard for that. Nothing in
this page is Kinetis reinventing an event loop — it's a small, deliberately
thin layer of primitives built on Revolt's suspend/resume pattern.

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
Wrapping a blocking call in a Fiber does not make it non-blocking: a
blocking call has no point where it can hand control back to other work,
so it blocks the whole worker just as hard, only less visibly. Kinetis's
database clients (see {doc}`persistence`) are built on `ext-mysqli` and
`ext-pgsql`, and under a persistent worker they issue statements through
those extensions' asynchronous entry points, so a query waits on the
event loop and suspends only its own Fiber. Under PHP-FPM, where a
worker process handles one request at a time, the fallback is a blocking
`PDO` connection.
```

## `concurrently()` — running tasks side by side

```{code-block} php
use Amp\Redis\RedisClient;
use Kinetis\Http\Attributes\Get;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

use function Kinetis\Async\concurrently;

final readonly class OrderController
{
    public function __construct(
        private MysqlLink $db,
        private RedisClient $redis,
    ) {}

    #[Get('/orders/{id}/summary')]
    public function summary(int $id): array
    {
        [$order, $itemCount, $views] = concurrently([
            fn () => new Query($this->db)->table('orders')->where('id', '=', $id)->first(),
            fn () => new Query($this->db)->table('order_items')->where('order_id', '=', $id)->count(),
            fn () => $this->redis->get("order:{$id}:views"),
        ]);

        return ['order' => $order, 'itemCount' => $itemCount, 'views' => (int) $views];
    }
}
```

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
well under 100ms total, not the ~150ms+ a sequential fallback would take —
because they genuinely overlap rather than merely appearing to.

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
loop: a `concurrently()` call can freely mix tasks built on any of them
and still run every one genuinely in parallel — a MySQL query, a
Postgres query, and a Redis command issued together complete in roughly
the time the slowest one alone takes, not the sum of all three.

## See also

- {doc}`persistence` — the database drivers and Redis client described
  above, and `TransactionGuard`, the request-lifecycle safety net built
  specifically because connection pools have no concept of
  Kinetis's `RequestScope`.
- {doc}`performance-tuning` — the worker-threads x connections
  budget, what to observe under load, and tuning by workload shape.
