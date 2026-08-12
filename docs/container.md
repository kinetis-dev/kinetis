# Container

Kinetis's dependency injection container is split into two classes with
deliberately different lifetimes: `AppScope`, which lives for as long as
the worker process does, and `RequestScope`, which lives for exactly one
request. Understanding *why* it's split this way — and what guarantee that
split is actually enforcing — matters more than memorizing the API surface,
so this page leads with the reasoning.

## `AppScope` — the persistent container

```{code-block} php
use Kinetis\Container\AppScope;

$app = new AppScope();

$app->bind(Logger::class, fn () => new FileLogger('/var/log/app.log'));
$app->instance(Config::class, Config::fromEnv());

$app->boot();
```

Everything registered on `AppScope` is built once and lives for the
worker's entire lifetime — this is exactly where a classic singleton's
state should live, just reached through the container instead of a static
accessor (more on that [below](#the-singleton-rewrite)).

`bind()` registers a factory; `instance()` registers an already-built
object directly. Both accept a `$shared` flag (default `true` for `bind()`)
controlling whether resolving the same id twice returns the same instance
or builds a fresh one each time.

**Registration is only allowed before `boot()`.** Once booted, calling
`bind()`/`instance()` again throws — this isn't a style preference, it's
what makes "the route table and service definitions are fixed at server
startup" an enforced invariant instead of a convention someone could
quietly violate three files away from where it matters. This covers
every way *you* add a binding; it doesn't extend to `AppScope`'s own
internal bookkeeping for an id you never registered at all —
`get()`/`resolve()` autowiring an unregistered class still caches that
one instance for next time (so a shared, unregistered class resolved
twice returns the same object), which is a real difference from "the
binding set literally never changes after boot()," just not one reachable
through the public API.

`boot()` itself registers five bindings for you, each only if you haven't
already registered your own: `Psr\Log\LoggerInterface` →
`Psr\Log\NullLogger` (see {doc}`logging`); `Kinetis\Config\Config` →
`Config::fromEnvironment()` (see {doc}`config`);
`Psr\SimpleCache\CacheInterface` → a Redis-backed cache when one's
configured, else a null one that always misses (see {doc}`persistence`);
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

## `RequestScope` — the ephemeral container

```{code-block} php
$scope = $app->createRequestScope();

// ... handle one request using $scope ...

$scope->dispose();
```

`Kernel::handle()` creates exactly one `RequestScope` per incoming request
and disposes it in a `finally` block, so a thrown exception partway through
dispatch still can't leak that scope into the next request. You will
almost never call `createRequestScope()`/`dispose()` yourself — this is
`Kernel`'s job — but understanding what happens inside it is what the rest
of this page is actually about.

### Resolution order

When `RequestScope::get($id)` is asked for something it doesn't have a
local binding for, it doesn't just fall back to constructing anything that
happens to exist:

1. **Delegate to `AppScope`, but only if `AppScope` has an *explicit*
   registration for `$id`.** `AppScope::has()` deliberately does not fall
   back to `class_exists()` — an unregistered class is never treated as
   "available on AppScope."
2. **Otherwise, autowire it locally**, via constructor-parameter
   reflection. The resulting instance is cached only for the *remainder of
   this request* — in `RequestScope`'s own binding table, which is wiped
   entirely on `dispose()` — and it is **never promoted to `AppScope`.**

Autowiring a constructor parameter typed as a class or interface tries to
resolve it through the container first, but — the same as a plain
builtin-typed parameter always could — falls back to the parameter's own
default value (or `null`, if it's nullable with no explicit default) when
that resolution fails, rather than propagating the failure unconditionally:

```{code-block} php
final class ReportGenerator
{
    public function __construct(
        // Nothing registers a Watermarker anywhere — resolution fails,
        // and this constructor gets null instead of a thrown exception.
        private ?Watermarker $watermarker = null,
    ) {}
}
```

This is what makes "inject this if it's available, otherwise use a sane
default" — the standard PHP idiom for an optional collaborator — actually
usable for a dependency, not just for a scalar constructor argument.
Resolution genuinely is attempted first, though: an unregistered-but-
real, instantiable class still autowires normally through this same
mechanism, exactly as point 2 above describes; only an *actual* failure
(nothing to resolve, or a nested dependency that itself can't be built)
triggers the fallback.

That second point is the actual guarantee this whole design exists to
provide: **a stray, unregistered `$container->get(SomeClass::class)` call
can never accidentally turn into a persistent, cross-request singleton.**
Without it, the most natural-looking code — just resolving something you
need, without first explicitly registering it — would be a silent trap:
the first request to touch that class would decide, by accident, whether
its state is request-scoped or worker-lifetime-scoped for every request
after it.

### Dispose hooks

```{code-block} php
$scope->onDispose(function (): void {
    // runs when this request's scope is torn down
});
```

`onDispose()` is the generic mechanism the request lifecycle's cleanup
hangs off of — `Kernel` uses it, unconditionally, on every request, to
register `TransactionGuard::rollbackDangling()` (see {doc}`persistence`),
so a transaction opened and never explicitly closed still gets rolled back
before the scope disappears. `RequestScope` itself has no idea
`TransactionGuard` or database transactions exist; it just runs whatever
callbacks were registered, in registration order, when `dispose()` is
called.

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

Under PHP-FPM this is completely safe — the process dies after the request,
so `self::$instance` never survives to see a second one. Under a
persistent worker, `self::$instance` is now shared, mutable state visible
to *every* request the worker ever handles, reachable from anywhere in the
codebase with zero indication at the call site that it's touching shared
state at all.

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
| Lifetime | Worker process | One request |
| Registration | Only before `boot()` | Any time before `dispose()` |
| Falls back to autowiring? | No | Yes, for anything not explicitly on `AppScope` |
| Autowired instances promoted upward? | — | Never |
| Analogous to | A correctly-scoped singleton | A fresh object graph per request |
