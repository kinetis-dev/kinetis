# Core Concepts

## Boot-and-die and persistent workers

A boot-and-die runtime — PHP-FPM here — runs one request per script
execution, not one per process. An FPM worker process serves many
requests in sequence, but PHP discards the request's userland state at
request shutdown and re-enters `public/index.php` from a clean slate for
the next one, so a static property set during one request is gone before
the next starts. The cost is that bootstrap runs again on every request:
the autoloader's class map is rebuilt in memory, every registration
re-runs, and the container is constructed from scratch. OPcache keeps
those files' compiled bytecode between requests; what executing them
builds does not survive.

A persistent worker — FrankenPHP's worker mode, RoadRunner — runs
`public/index.php` once and feeds that one execution request after
request. Bootstrap runs once per worker and everything it built stays in
memory. That makes one class of bug possible that boot-and-die cannot
express: **state leaking from one request into the next.**

## A warm worker still waits

A warm worker removes bootstrap cost, not waiting. A synchronous
database query or HTTP call occupies the worker for as long as the
response takes to arrive, and nothing else that worker could be doing
gets a turn in the meantime.

`Kinetis\Async` is the other half of the picture: PHP Fibers, scheduled
by a Revolt event loop, let a request that's waiting on one slow
operation hand control back so the worker makes progress on something
else instead of sitting idle. `concurrently()` uses this to run several
independent operations — a database query, an HTTP call, a cache read —
side by side, completing in roughly the time of the slowest one rather
than their sum. See {doc}`concurrency` for the full picture.

## `HttpStartup` owns the boot

An application's `public/index.php` is the Composer autoloader plus one
call:

```{code-block} php
:caption: public/index.php

require dirname(__DIR__) . '/vendor/autoload.php';

Kinetis\Runtime\HttpStartup::run(__DIR__);
```

`Kinetis\Runtime\HttpStartup` is the startup program itself, owned by the
framework rather than copied into each project. Everything it does runs
once per execution of that entry script — once per FrankenPHP worker
thread, for that thread's whole life, and once per request under PHP-FPM,
which re-enters the script every time. Nothing in it is request-specific;
the `Kernel` it builds is what handles requests. In order, it:

1. Loads `.env`, then detects `APP_ENV`. That order matters: `APP_ENV`
   may be defined for the first time in `.env` rather than already set
   in the process environment.
2. Resolves routes, middleware and event listeners. Development
   discovers them live from source on every boot; production reads the
   `.kinetis-cache/compiled.php` artifact, falling back to one fresh
   compile when there is none this build can use — see {doc}`caching`.
3. Binds `FormLimits` and `TrustedProxies`, then runs the package and
   application bootstrap chain and calls `AppScope::boot()`. Both
   bindings land before the chain, so `bootstrap.php` can replace either
   one; the last write before `boot()` locks the container wins.
4. Detects the runtime adapter, handing it the proxy policy read back
   out of the booted container rather than the one built in step 3 — so
   a `bootstrap.php` that narrowed it decides this request's scheme and
   client address.
5. Constructs the `Kernel` with the adapter's own `isPersistent()`, and
   hands `Kernel::handle()` to the adapter's request loop.

The request body takes no part in this. An adapter hands the body on as
raw PSR-7 bytes, and `RequestBodyMiddleware` bounds and parses it inside
the Kernel under whatever `FormLimits` the container holds — see
{doc}`middleware`.

A deployment that wants one specific adapter instead of detection calls
`HttpStartup::assemble()` with its own factory and serves from the
result; see {doc}`runtime-adapters`.

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
constructed identically, behaves identically whether it's handling the
one request a PHP-FPM script execution serves or request #40,000 of a
FrankenPHP worker that's been running for three days. Nothing in `Kernel`
itself is aware of which situation it's in — except one flag,
`$isPersistent`, which exists for exactly one purpose: deciding whether to
force a `gc_collect_cycles()` call at the end of the request (see
[below](#the-request-lifecycle)).

## The request lifecycle

Every call to `Kernel::handle()` follows the same shape:

1. Any streamed response left unsettled by the previous request on this
   Kernel is released, before anything of this request runs.
2. The global PSR-15 middleware pipeline runs, resolved from `AppScope`.
   It wraps everything below, so a global middleware that answers
   outright — a CORS preflight, an over-limit body, a rate limit — never
   reaches step 3 and no `RequestScope` is created for it. See
   {doc}`middleware`.
3. The pipeline's innermost handler creates a fresh `RequestScope` from
   the persistent `AppScope` container (see {doc}`container`).
4. When `kinetis/persistence` is installed, a `TransactionGuard` is
   resolved from that scope and `rollbackDangling()` is registered as a
   dispose hook — for every request, whether or not it ever opens a
   database transaction (a no-op when it doesn't). If a request opens a
   transaction and something goes wrong before it's explicitly committed
   or rolled back, this is the safety net that closes it anyway.
   `kinetis/framework` alone has no database concept, so this step is
   skipped entirely without the package installed. See
   {doc}`persistence`.
5. The router matches the request; the matched route's own
   `#[Middleware]` pipeline runs, resolved from that `RequestScope`; a
   `Dispatcher` resolves the controller's parameters and invokes it.
6. The `RequestScope` is disposed — whether the request succeeded,
   threw, or hit a 404/405.
7. If `$isPersistent` is true, `gc_collect_cycles()` runs.

A response that streams its own body is the one exception to steps 6 and
7. Its body is written after `handle()` has returned, by an adapter,
against code that resolves from that same scope — so disposal is deferred
onto a lease the response carries, and runs on whichever settlement
reaches that lease first: the emitter finishing, the response being
abandoned by an adapter that cannot stream it, the wrapper being replaced
by something else on the way out of the global pipeline, or the next
request finding it still pending. See {doc}`container`.

PHP's garbage collector reclaims most memory through reference counting,
but **circular references** — two objects each holding a reference to the
other, a `Fiber` caught in one included — need the cycle collector to run
before they are freed, and that collector runs on its own heuristic
schedule rather than per request. Under boot-and-die that does not
matter: PHP releases the request's memory at request shutdown,
uncollected cycles included, whether or not the process itself exits. In
a worker whose one script execution spans days, cycles accumulating between
the collector's own runs are a slow memory leak, so collection is forced
at the request boundary. Skipping the same call under boot-and-die avoids
paying for a collection pass over memory that is about to be released
wholesale.

## Why state isolation is enforced rather than advised

The classic singleton — a `private static ?self $instance` property — is
the shape of code that leaks state, and it is safe under boot-and-die: a
static property PHP discards at the end of the request is
indistinguishable from a request-scoped variable. The same property in a
worker whose script execution spans days is a value every subsequent
request can read and mutate.

That is why the container is split into two tiers, and why a PHPStan rule
banning `static` properties ships as part of the framework rather than as
advice in a README. {doc}`container` covers both, including the rewrite
of "singleton via static property" into "singleton via the container."

## What persistent workers do not change

Superglobal state (`$_GET`, `$_POST`, `$_SERVER`) needs no reset step
between requests. FrankenPHP repopulates every superglobal on each call
into the worker, and `Kinetis\Runtime\SuperglobalsBridge` reads them on
that basis. RoadRunner has none to reset: `RoadRunnerAdapter` builds the
PSR-7 request from RoadRunner's own protocol and never touches one.

What is left is narrower than "any global state": state your own
application code introduces outside the container's request-scoping
mechanism, which is what the `NoStaticPropertiesRule` in {doc}`container`
catches.

## See also

- {doc}`container` — `AppScope`, `RequestScope`, and the enforcement
  mechanism behind everything above.
- {doc}`concurrency` — `concurrently()`, `Async\Socket`, and the
  non-blocking database/Redis clients that make a warm process pay off
  during I/O, not just at boot.
- {doc}`middleware` — the two PSR-15 pipelines wrapping every request
  through this lifecycle, and the built-in `ExceptionHandlerMiddleware`
  that guarantees an uncaught exception still becomes a response.
- {doc}`runtime-adapters` — exactly how `Kernel` gets driven by
  FrankenPHP, PHP-FPM, RoadRunner, and AWS Lambda, and what each one is
  actually responsible for.
- {doc}`caching` — what the production AOT artifact holds, and what it
  changes about this lifecycle.
