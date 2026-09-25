# Appendix: Container Lifecycle

Kinetis splits dependency injection across two containers with different
lifetimes. `AppScope` lives for as long as the execution context that
booted it, and holds what every request shares. `RequestScope` lives for
one request, and holds what must not outlive it. PHP does not separate
one request's memory from the next inside a persistent worker; this split
is what does. For the newcomer's first decision between the two — and
when to write `bootstrap.php` at all — see {doc}`bootstrapping`; this
page is the complete contract behind that decision.

## `AppScope` — the persistent container

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Logging\ErrorLogLogger;
use Psr\Log\LoggerInterface;

$app = new AppScope();

$app->bind(LoggerInterface::class, fn (): LoggerInterface => new ErrorLogLogger());
$app->instance(Config::class, Config::fromEnvironment());

$app->boot();
```

Everything registered on `AppScope` is built once and lives for as long
as the execution context that booted it — a worker thread under
FrankenPHP, a worker process under RoadRunner, a single request under
PHP-FPM, since `bootstrap.php` runs once per context (see
{doc}`runtime-adapters`). A classic singleton's state belongs here,
reached through the container instead of a static accessor (more on that
[below](#the-singleton-rewrite)).

`bind()` registers a factory; `instance()` registers an already-built
object directly. `bind()` takes a `$shared` flag, `true` by default,
controlling whether resolving the same id twice returns the same instance
or builds a fresh one each time. An `instance()` registration is one
object and is always returned as-is.

**Registration is only allowed before `boot()`.** Once booted, calling
`bind()`/`instance()` again throws — this isn't a style preference, it's
what makes "the route table and service definitions are fixed at server
startup" an enforced invariant instead of a convention someone could
quietly violate three files away from where it matters.

**Only an explicit registration creates a singleton.** `get()` on a class
you never registered still works — it autowires the class through its
constructor — but returns a fresh instance every call, never cached. This
is the same "never promoted" guarantee `RequestScope` makes, applied to
`AppScope`'s own public API: a stray `get()` on a class holding
per-request state can't quietly become one shared object every request
that context ever serves. A service that should be one shared instance is
registered with `bind()`/`instance()` before `boot()`.

That fallback reaches only as far as reflection can: the id must name a
declared, instantiable class — never an interface, an abstract class or
an enum — and every one of its constructor parameters must itself
resolve. Outside that boundary `get()` raises rather than handing back a
half-built object;
[Absent dependencies, and broken ones](#absent-dependencies-and-broken-ones)
below is the full rule.

`boot()` itself registers nine bindings for you, each only if you
haven't already registered your own: `Kinetis\Runtime\AppEnvironment` →
the detected environment (`APP_ENV`, defaulting to production);
`Kinetis\Instrumentation\TelemetryInterface` → the process-wide
`Telemetry::global()` holder, so app code can constructor-inject it too
(see {doc}`appendix`); `Psr\Log\LoggerInterface` → an `error_log()`-backed
logger in development, `Psr\Log\NullLogger` in production (see
{doc}`logging`); `Kinetis\Config\Config` →
`Config::fromEnvironment()` (see {doc}`config`);
`Kinetis\Http\Form\FormLimits` and `Kinetis\Http\TrustedProxies` → both
built from that `Config`, standing in for an entry point that registered
neither of its own (see {doc}`appendix`);
`Psr\SimpleCache\CacheInterface` → a Redis-backed cache when one's
configured, whose connection `dispose()` below closes, else a null one
that always misses (see {doc}`redis`);
`Kinetis\Events\ListenerInvokerInterface` → a synchronous invoker (see
{doc}`events`); and `AppScope::class` → the exact instance that's
booting. That last one means `$app->get(AppScope::class) === $app` is
always true once booted — resolving `AppScope` through itself doesn't
silently autowire a brand-new, disconnected, unbooted container the way
it would for any other unregistered class, since `AppScope` (unlike, say,
a plain service class) genuinely does exist and is instantiable,
`Autowire` would otherwise happily construct one with no error at all.

```{code-block} php
$app = new AppScope();
$app->boot();

$app->get(AppScope::class) === $app; // true
```

(container-app-disposal)=
### Ending the application's lifetime

`dispose()` is the other end of `boot()`. It runs what `onDispose()`
registered, releases every retained instance, and refuses every later
use — so an application-scoped resource opened at boot is closed rather
than abandoned when the process, or the test, that created the scope is
finished with it.

```{code-block} php
$pool = new ConnectionPool($config);

$app->onDispose($pool->close(...));
```

`onDispose()` takes a `callable(): void` and is the **one registration
allowed after `boot()`**, not only before it. An app-scoped factory is
lazy: a pool that opens on first resolution can register its own close
operation only then, with the binding set long since locked. It is
refused once the scope has actually been disposed, where nothing would
ever run the callback.

`dispose()`'s contract:

- every callback runs, in registration order, even if an earlier one
  threw;
- the scope is marked disposed, and then bindings, instances and every
  registration list are released — all of it either way, so neither a
  failing callback nor a released service whose own destructor throws
  can leave the scope holding worker-lifetime state or still answering
  resolutions;
- only then is the *first* failure rethrown. A later failure is not the
  one `dispose()` surfaces;
- a second `dispose()` has nothing left to run or release and returns;
- `bind()`, `instance()`, `get()`, `boot()`, `createRequestScope()` and
  `onDispose()` all throw
  `Kinetis\Container\Exception\ContainerException` afterwards, naming
  disposal rather than the boot lock — two different mistakes, and only
  one is fixed by registering earlier.

This is the same shape `RequestScope::dispose()` has, one lifetime out.
The two stay distinct: a request-scoped resource is registered on the
scope that resolved it and closed at the end of that unit of work, while
an application-scoped one is registered here and closed when the
execution context ends. `kinetis/database-bridge` uses both — the
links it builds are closed on application disposal, and each scope's
`TransactionGuard` and `EntityManagerRegistry` on that scope's (see
{doc}`persistence`).

Who calls it:

| Entry point | When |
|---|---|
| `Kinetis\Runtime\HttpStartup::serve()` | Once the adapter's request loop returns — a worker shutting down, or the end of the one request a boot-per-request SAPI served. A loop that *throws* ends the worker with that exception instead, uncaught, since the failure that ended it is what the runtime has to see. |
| `HttpStartup::assemble()` and `Kinetis\Testing\TestApplication::boot()` | On a failure anywhere past the scope's construction. A bootstrap that already opened something owns a real resource; the disposal's own failure is swallowed rather than replacing the assembly or boot failure the caller has to see. |
| `bin/kinetis` | After the command's request scope, the longer-lived scope going second. Each disposal is contained on its own, so a failing request-scope disposal does not skip this one. |
| `TestApplication::dispose()` | When a test finishes with the application it booted. Idempotent, because `AppScope::dispose()` is. |
| `Kinetis\Testing\ApplicationTestCase` | From a `#[After]` hook, per test, guarded so a boot failure is reported as itself rather than as an uninitialized property during teardown ({doc}`testing`). |

`bin/kinetis` keeps the command's own outcome authoritative: a disposal
failure is reported separately and replaces nothing. It becomes the
process's exit code only when the command completed successfully and
nothing else was signaling a problem, in which case the binary exits
`70` — see {doc}`cli`.

## `RequestScope` — the ephemeral container

```{code-block} php
$scope = $app->createRequestScope();

// ... handle one request using $scope ...

$scope->dispose();
```

`Kernel::handle()` creates exactly one `RequestScope` per incoming request
and disposes it before returning, so a thrown exception partway through
dispatch still can't leak that scope into the next request.

A response that streams its own body is the one exception, because the
code writing those bytes runs after `handle()` has returned and resolves
from that same scope. `Kernel` hands back a `StreamedResponse` wrapper
and releases the scope the moment the emitter finishes. An owner that
will never write that body settles it the other way instead, through
`Kinetis\Runtime\StreamableResponseInterface::abandon()` — an adapter
that cannot stream calls it before answering with a refusal of its own.
`Kernel` releases the scope the same way, before `handle()` answers,
whenever the response leaving its global pipeline is not the wrapper it
handed that pipeline: a middleware's own reply, buffered or streamed, or
a failure on its way out. A `with*` clone is still that wrapper, and is
left to the adapter. Either settlement happens on the request that
created the scope. What reaches none of them — an exception trace
holding the wrapper as a frame argument, say — the next request releases
first thing, before any of its own global middleware runs. Either way a
finished request's scope is unreachable from the one after it.

You will almost never call `createRequestScope()`/`dispose()`
yourself — this is `Kernel`'s job — but understanding what happens inside
it is what the rest of this page is actually about.

### Resolution order

When `RequestScope::get($id)` is asked for something it doesn't have a
local binding for, it resolves in a fixed order rather than constructing
anything that happens to exist:

1. **Delegate to `AppScope`, but only if `AppScope` has an *explicit*
   registration for `$id`.** `AppScope::has()` deliberately does not fall
   back to `class_exists()` — an unregistered class is never treated as
   "available on AppScope."
2. **Otherwise, autowire it locally**, via constructor-parameter
   reflection. The resulting instance is cached only for the *remainder of
   this request* — in `RequestScope`'s own binding table, which is wiped
   entirely on `dispose()` — and it is **never promoted to `AppScope`.**

That second point is the actual guarantee this whole design exists to
provide: **a stray, unregistered `$container->get(SomeClass::class)` call
can never accidentally turn into a persistent, cross-request singleton.**
Without it, the most natural-looking code — just resolving something you
need, without first explicitly registering it — would be a silent trap:
the first request to touch that class would decide, by accident, whether
its state is request-scoped or worker-lifetime-scoped for every request
after it.

### Absent dependencies, and broken ones

A class- or interface-typed constructor parameter with a default value,
or a nullable type, says one thing: **the dependency may be absent.** It
never says a broken one is acceptable.

Absence is decided from the id alone, before anything is resolved: a
dependency is absent when nothing registered the id and the id is an
interface, an enum, or a name that declares nothing at all. Everything
else is resolved, and every failure that resolution meets reaches the
caller: a binding factory that throws, a nested dependency that cannot
be built, a cycle
(`Kinetis\Container\Exception\CircularDependencyException`), a
request-scoped id asked for from `AppScope`
(`DisconnectedRequestScopeException`). A declared class that cannot be
constructed — abstract, or a non-public constructor — is a wiring error,
not an absent dependency.

An absent dependency takes the parameter's own default value, or `null`
when the type is nullable with no default written out. With neither, the
container reports the absence itself, naming the id nobody bound.

```{code-block} php
final class ReportGenerator
{
    public function __construct(
        // Nothing binds this interface, so the dependency is absent and
        // this stays null.
        private ?WatermarkerInterface $watermarker = null,
    ) {}
}
```

That is what makes "inject this if it's available, otherwise use a sane
default" — the standard PHP idiom for an optional collaborator — usable
for a dependency rather than only for a scalar argument, without the
default doubling as a place for real failures to disappear into. An
ordinary class is never absent: it autowires normally, exactly as point
2 above describes, and a failure to construct it propagates.

`Kinetis\Http\Dispatcher` applies this same rule to a controller
method's class-typed parameter, so a dependency behaves identically
whether it arrives through a constructor or a method signature — see
{doc}`routing-validation`.

### Resolving `RequestScope` itself, from the wrong scope

`RequestScope` registers itself onto itself (`RequestScope::class` →
the current instance) the moment `AppScope::createRequestScope()` mints
one, so a class resolved *through* that scope can constructor-inject
`RequestScope $scope` and reach the exact instance the current request is
using.

`AppScope` never has such a registration — there is no single, "the"
`RequestScope` at the worker-lifetime scope, since a fresh one exists per
request. Asking `AppScope` for `RequestScope::class` — directly, or as a
constructor dependency of anything else `AppScope` resolves (a class
bound there, or autowired through it) — throws
`Kinetis\Container\Exception\DisconnectedRequestScopeException` rather
than autowiring a brand-new, disconnected, unbooted `RequestScope`. A
class that needs `RequestScope` must be resolved through a real request's
own scope (route middleware, a controller) — never registered on
`AppScope` with a factory that also resolves `RequestScope`.

### Request-scope initializers

```{code-block} php
$app->onRequestScopeCreated(static function (RequestScope $scope): void {
    // runs on every scope createRequestScope() creates, before it is returned
});
```

`AppScope::onRequestScopeCreated()` registers a
`callable(RequestScope): void` that `createRequestScope()` runs on every
scope it creates, in registration order, before returning it. Like every
other registration it is allowed only before `boot()`, and throws
`Kinetis\Container\Exception\ContainerException` after. It is how a
package bootstrap installs request-scoped bindings and dispose hooks
without any entry point knowing about them: `Kernel`, `bin/kinetis`, `kinetis/queue`'s
`QueueWorker`/`SyncQueue`, and `kinetis/mcp`'s `ScopedMessageHandler` all take
their scopes from `createRequestScope()`, so every initializer runs on
each. A `#[Command(bootstrap: false)]` command runs no package bootstrap
and gets no package initializer.

Bind lazily inside an initializer, so a unit of work that never
resolves the binding constructs nothing. If an initializer throws, the scope is
disposed — running whatever earlier initializers registered on it — and
that failure propagates from `createRequestScope()`.

### Dispose hooks

```{code-block} php
$scope->onDispose(function (): void {
    // runs when this request's scope is torn down
});
```

`onDispose()` is the generic mechanism the request lifecycle's cleanup
hangs off of — `AppScope` has its own counterpart for what a worker
owns ([Ending the application's
lifetime](#ending-the-applications-lifetime)).
`kinetis/database-bridge`'s request-scope initializer uses it: the first
time a scope resolves `TransactionGuard`, the guard's
`rollbackDangling()` (see {doc}`persistence`) is registered on that
scope's disposal, so a transaction opened through the guard and never
explicitly closed is still closed before the scope disappears.
`RequestScope` itself has no idea `TransactionGuard` or database
transactions exist; it just runs whatever callbacks were registered, in
registration order, when `dispose()` is called.

`dispose()`'s own contract, regardless of who calls it: every registered
callback is attempted, even if an earlier one throws; the scope's
bindings are wiped and it's marked disposed either way; then, only after
all of that has happened, the *first* callback's failure (if any) is
rethrown to whoever called `dispose()`. A later callback throwing too is
never silently lost — it just isn't the one `dispose()` itself surfaces.

### A cleanup failure never replaces the real outcome

PHP's own `finally` semantics are a trap here: a `Throwable` raised while
disposing a scope inside a `finally` block silently *replaces* whatever
exception or return value was already in flight from the code that
`finally` block wraps. A route handler that threw a well-formed 404, or a
queue job that already committed its outcome, would otherwise vanish
behind an unrelated "cleanup failed" error the moment disposal itself had
a problem — worse than the failure it was supposed to be reporting on.

Every place in Kinetis that disposes a `RequestScope` — `Kernel`,
`kinetis/queue`'s `QueueWorker`/`SyncQueue`, `kinetis/mcp`'s
`ScopedMessageHandler`, and `bin/kinetis` — disposes it *outside* any `finally` that could still
discard an already-decided outcome, and defines an explicit precedence
instead: whatever the unit of work already produced (a response, a job's
durable transition, a command's exit code) is preserved exactly, and a
disposal failure on top of it is logged separately rather than allowed to
overwrite it. Each owner's exact rule is documented on its own page —
{doc}`routing-validation` for HTTP, {doc}`queue` for the worker/sync
queue, {doc}`mcp` for the stdio transport, and
{doc}`cli` for `bin/kinetis` — since what "the real outcome" means differs
per owner (a response that hasn't left the process yet is not the same
situation as a queue job whose `ack()` already ran).

## The singleton rewrite

This is the concrete pattern worth internalizing if you're bringing
PHP-FPM habits into a persistent-worker codebase. The classic singleton:

```{code-block} php
:caption: The PHP-FPM-safe pattern that becomes dangerous under a persistent worker

final class Metrics
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private array $counters = [];

    public function increment(string $name): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + 1;
    }
}

// called from anywhere, no constructor injection needed:
Metrics::instance()->increment('requests');
```

Under PHP-FPM this is safe, for the reason {doc}`core-concepts` covers in
full: the process dies after the request, so `self::$instance` never
survives to see a second one. Under a persistent worker, `self::$instance`
is now shared, mutable state visible to *every* request the worker ever
handles, reachable from anywhere in the codebase with zero indication at
the call site that it's touching shared state at all.

The Kinetis-idiomatic rewrite keeps the *same* one-instance-per-worker
lifetime, but makes it reachable only through the container:

```{code-block} php
:caption: The same lifetime, reached through the container instead of a static accessor

final class Metrics
{
    private array $counters = [];

    public function increment(string $name): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + 1;
    }
}

// registered once, at boot:
$app->bind(Metrics::class, shared: true);
```

```{code-block} php
:caption: Consumed via constructor injection, not a static call

use Kinetis\Http\Attributes\Post;

final readonly class OrderController
{
    public function __construct(
        private Metrics $metrics,
    ) {}

    #[Post('/orders')]
    public function store(): array
    {
        $this->metrics->increment('orders.created');
        // ...
    }
}
```

Nothing about `Metrics` itself changed — it's still one instance for the
worker's whole lifetime. What changed is *reachability*: the only way to
get an instance is through the container, via constructor injection, which
is exactly what makes `RequestScope`'s isolation guarantee hold. A static
accessor is, by construction, reachable from literally anywhere, bypassing
any scoping the container tries to enforce; a constructor parameter is
visible in exactly the places that actually receive it.

## `NoStaticPropertiesRule` — the enforcement PHP itself can't provide

Everything above is a *convention*: a fresh `RequestScope` per request, and
services correctly registered on the right tier. PHP doesn't sandbox memory
per request, so nothing stops application code from reintroducing exactly
the state-bleed problem `RequestScope` exists to prevent — just via a
`static` property instead of a singleton accessor:

```{code-block} php
:caption: This bypasses RequestScope's isolation entirely, from inside a request-scoped class

final class RequestLogger
{
    private static array $entries = [];  // ← survives every request, forever

    public function log(string $message): void
    {
        self::$entries[] = $message;
    }
}
```

This is what `Kinetis\Linting\NoStaticPropertiesRule` exists to catch. It
ships as a PHPStan rule under the framework's **main** autoload — not a
dev-only tool — because it's meant to run against *your* application code,
added to your own project's `phpstan.neon`:

```{code-block} yaml
:caption: phpstan.neon

rules:
    - Kinetis\Linting\NoStaticPropertiesRule
```

It flags exactly one thing — a `static` property declaration — and nothing
else; a static *method* or a plain instance property is left alone, since
neither one holds state across requests on its own.

```{code-block} php
private static array $entries = [];
```
```{code-block} text
Static properties hold state across every request a persistent worker
handles until it restarts — exactly the cross-request state bleed a
fresh RequestScope per request exists to prevent. Use AppScope for state
that should genuinely persist for the worker's lifetime, or RequestScope
for state scoped to one request.
```

### The escape hatch

The rule is a *warning you opt into*, not a language-level ban, and there's
a real, standard PHPStan mechanism for the rare case where a static
property is genuinely safe — for instance, a memoized *pure* computation
with provably zero per-request variance (the same value would be computed
identically regardless of which request triggers it first):

```{code-block} php
/** @phpstan-ignore-next-line kinetis.noStaticProperties */
private static array $memoizedPureLookup = [];
```

Use this deliberately and rarely, with a real reason stated inline — not a
blanket exemption.

## Summary

| | `AppScope` | `RequestScope` |
|---|---|---|
| Lifetime | One execution context: a FrankenPHP worker thread, a RoadRunner worker process, one PHP-FPM request | One request |
| Registration | Only before `boot()` — `onDispose()` excepted, which is allowed until disposal | Any time before `dispose()` |
| Disposed by | The entry point that built it, when its execution context ends | The owner of the unit of work, at its end |
| Falls back to autowiring? | Yes, for an instantiable class whose constructor resolves | Same, for anything not explicitly on `AppScope` |
| Autowired instances cached? | Never | For this request only, never promoted |
| Analogous to | A correctly-scoped singleton | A fresh object graph per request |

## See also

- {doc}`bootstrapping` — the task-first guide: what boots automatically,
  when to write `bootstrap.php`, and the `AppScope`/`RequestScope` choice
  a newcomer has to make.
- {doc}`config` — `Config`, resolved from `AppScope` the same as any
  other service you never explicitly registered on `RequestScope`.
- {doc}`core-concepts` — why a persistent worker makes scope a
  correctness question rather than a style one.
- {doc}`routing-validation` — where controllers get resolved from, and
  what a route's own dependencies are resolved against.
- {doc}`testing` — building a booted application in a test, and the
  container it gives you.
