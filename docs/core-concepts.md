# Core Concepts

## The world before persistent workers

Classic PHP deployment — Apache with mod_php, then PHP-FPM — runs one
request per process invocation. The process (or, for FPM, the worker that
handles your request) starts from a known-clean state, executes your
script, and either dies or gets recycled. Nothing you allocate outlives the
response. A global variable set during request A is simply gone by the
time request B starts, because the interpreter itself starts over.

This has a real cost: every request re-parses your autoloader's class map,
re-runs every bit of bootstrap logic, re-builds your DI container from
scratch. For most applications this cost is small enough to ignore. For
high-throughput services, it adds up.

**Persistent workers — FrankenPHP's worker mode, Swoole, RoadRunner — trade
that cost away by keeping one PHP process alive across many requests.**
Bootstrap happens once. The interpreter stays warm. And a whole category of
bugs impossible under boot-and-die — where the process died before they
could matter — becomes possible: **state leaking from one request into
the next.**

## Staying warm isn't the same as staying busy

A persistent worker removes the cost of re-parsing your autoloader and
rebuilding your container on every request, but that alone doesn't change
what happens while a request is waiting on something slow. A synchronous
database query or HTTP call occupies the worker for exactly as long as
the response takes to arrive — warm process or not — and nothing else
that worker could be doing gets a turn in the meantime.

`Kinetis\Async` is the other half of the picture: PHP Fibers, scheduled
by a Revolt event loop, let a request that's waiting on one slow
operation hand control back so the worker makes progress on something
else instead of sitting idle. `concurrently()` uses this to run several
independent operations — a database query, an HTTP call, a cache read —
side by side, completing in roughly the time of the slowest one rather
than their sum. See {doc}`concurrency` for the full picture.

## The `Kernel` — runtime-agnostic by design

Everything in Kinetis's request-handling path converges on one class:

```{code-block} php
namespace Kinetis\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Kernel
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // ...
    }
}
```

`Kernel::handle()` takes a pure [PSR-7](https://www.php-fig.org/psr/psr-7/)
`ServerRequestInterface` and returns a pure `ResponseInterface`. It never
touches `$_GET`, `$_SERVER`, `frankenphp_handle_request()`, or any other
runtime-specific primitive — that boundary is deliberate and total. Every
execution environment bridges in through a small
`RuntimeAdapterInterface` (see {doc}`runtime-adapters`), and `Kernel`
neither knows nor cares which one is driving it.

This matters for more than just testability. It means the same `Kernel`,
constructed identically, behaves identically whether it's handling request
#1 of a brand-new PHP-FPM process or request #40,000 of a FrankenPHP worker
that's been running for three days. Nothing in `Kernel` itself is aware of
which situation it's in — except one flag, `$isPersistent`, which exists
for exactly one purpose: deciding whether to force a `gc_collect_cycles()`
call at the end of the request (see [below](#the-request-lifecycle)).

## The request lifecycle

Every call to `Kernel::handle()` follows the same shape:

1. A fresh `RequestScope` is created from the persistent `AppScope`
   container (see {doc}`container`).
2. A `TransactionGuard` is resolved from that scope, and
   `rollbackDangling()` is registered as a dispose hook — unconditionally,
   for every request, whether or not it ever opens a database transaction.
   If a request opens a transaction and something goes wrong before it's
   explicitly committed or rolled back, this is the safety net that closes
   it anyway. See {doc}`persistence`.
3. The router matches the request; a `Dispatcher` resolves the matched
   controller's parameters and invokes it.
4. In a `finally` block — so it runs whether the request succeeded, threw,
   or hit a 404/405 — the `RequestScope` is disposed.
5. If `$isPersistent` is true, `gc_collect_cycles()` runs.

That last step deserves its own explanation, because it's easy to dismiss
as a micro-optimization when it's actually closing a real gap. PHP's
garbage collector already reclaims most memory automatically through
reference counting — but **circular references** (two objects each holding
a reference to the other, including a `Fiber` caught in one) need PHP's
cycle collector to run before they're freed, and that collector runs on its
own heuristic schedule, not deterministically per-request. In a
boot-and-die process, this doesn't matter: the OS reclaims everything the
moment the process exits anyway. In a worker that's going to keep running
for the next few million requests, letting circular references pile up
between the collector's own heuristic runs is a genuine, slow memory leak.
Forcing collection at the natural boundary of "this request is done" closes
it — and skipping that same call in a boot-and-die process is equally
deliberate: forcing a collection cycle a moment before the process dies
anyway would be pure waste.

## Why "just don't leak state" isn't good enough

The obvious response to "persistent workers can leak state between
requests" is "then don't write code that leaks state." In practice, that's
not a sufficient answer, for a very specific reason: **the classic
singleton pattern — a `private static ?self $instance` property — is
exactly the shape of code that leaks state**, and it's a pattern that
exists throughout the PHP ecosystem precisely because, under PHP-FPM, it
was always safe. A static property in a process that dies after one
request is indistinguishable from a request-scoped variable. The same
static property in a worker that lives for days is a value every
subsequent request can read and, worse, mutate.

This is the actual reason Kinetis's container is split into two tiers
instead of one, and the actual reason a PHPStan rule banning `static`
properties ships as part of the framework itself rather than as
project-specific advice in a README. Both are covered in full in
{doc}`container` — including the specific, concrete rewrite of "singleton
via static property" into "singleton via the container," since that's the
pattern most likely to need to change when porting code that grew up under
PHP-FPM.

## What's *not* a new problem

It's worth being precise about what persistent workers actually change,
because not everything does. Superglobal state (`$_GET`, `$_POST`,
`$_SERVER`) is a good example: it's tempting to assume a persistent worker
needs some kind of manual superglobal-reset step between requests, but it
needs no code at all: FrankenPHP already repopulates every superglobal
correctly on each call into the worker, and `Kinetis\Runtime\
SuperglobalsBridge` relies on exactly that.

The genuinely new risk is narrower and more specific than "any global
state": it's specifically the state *your own application code* introduces
outside the container's request-scoping mechanism — which is precisely what
the `NoStaticPropertiesRule` in {doc}`container` exists to catch.

## Where to go next

- {doc}`container` — `AppScope`, `RequestScope`, and the enforcement
  mechanism behind everything above.
- {doc}`concurrency` — `concurrently()`, `Async\Socket`, and the
  non-blocking database/Redis clients that make a warm process pay off
  during I/O, not just at boot.
- {doc}`middleware` — the two PSR-15 pipelines wrapping every request
  through this lifecycle, and the built-in `ExceptionHandlerMiddleware`
  that guarantees an uncaught exception still becomes a response.
- {doc}`runtime-adapters` — exactly how `Kernel` gets driven by FrankenPHP,
  PHP-FPM, and AWS Lambda, and what each one is actually responsible for.
- {doc}`caching` — what changes about this lifecycle in production, and
  why the answer turned out to be more specific than "cache everything."
