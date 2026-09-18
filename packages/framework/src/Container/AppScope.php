<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Kinetis\Config\Config;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\DisconnectedRequestScopeException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Events\SynchronousListenerInvoker;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\TrustedProxies;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Instrumentation\TelemetryInterface;
use Kinetis\Logging\ErrorLogLogger;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\SimpleCache\NullSimpleCache;
use Kinetis\SimpleCache\UnavailableSimpleCache;
use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * The persistent, worker-lifetime container. Services registered here are
 * booted once at server startup and live for as long as the worker process
 * does — they must never hold per-request state.
 *
 * Binding registration is only allowed before boot(). Once booted, the
 * binding set is locked: this is what makes "compiled routes and service definitions
 * booted once at server startup" an enforced invariant rather than a
 * convention that can quietly drift.
 *
 * dispose() ends that lifetime: it runs what onDispose() registered,
 * refuses every later use, and releases every retained instance. An
 * entry point that owns the scope calls it once the process — or the
 * test — that created it is finished with it, so an application-scoped
 * resource opened at boot is closed rather than abandoned.
 */
final class AppScope implements ContainerInterface
{
    // Not `use` imports — see buildDefaultCache()'s docblock and
    // RuntimeDetector::BREF_ADAPTER_CLASS for why a plain string constant
    // is deliberate here: referencing either name never triggers
    // autoloading on its own, only class_exists()/instantiation does.
    private const REDIS_SIMPLE_CACHE_CLASS = 'Kinetis\SimpleCache\RedisSimpleCache';

    /** @var array<string, Binding> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $openApiMiddleware = [];

    /** @var list<callable(RequestScope): void> */
    private array $requestScopeInitializers = [];

    /** @var list<callable(): void> */
    private array $disposeCallbacks = [];

    private bool $booted = false;

    private bool $disposed = false;

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = true): void
    {
        $this->assertUsable($id);
        $this->bindings[$id] = new Binding($this->normalizeConcrete($id, $concrete), $shared);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertUsable($id);
        $binding = new Binding(static fn (): object => $instance);
        $binding->remember($instance);
        $this->bindings[$id] = $binding;
    }

    /**
     * Registers a global middleware, run for every request — including
     * ones that never match a route — in registration order, outermost
     * first. Locked after boot() for the same reason bind()/instance()
     * are: the pipeline a request runs through must be fixed at server
     * startup, not something that can quietly change mid-worker-lifetime.
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function middleware(string $middlewareClass): void
    {
        $this->assertUsable($middlewareClass);
        $this->middleware[] = $middlewareClass;
    }

    /**
     * @return list<class-string<MiddlewareInterface>>
     */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /**
     * Registers middleware scoped to the `/openapi.json` and `/openapi`
     * endpoints only — one registration point for both, since they're the
     * same "expose the API's own shape" concern — never runs for any
     * other route, unlike middleware(), which already wraps those two
     * endpoints as part of every request. Same registration-order/
     * lock-after-boot() discipline as middleware().
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function openApiMiddleware(string $middlewareClass): void
    {
        $this->assertUsable($middlewareClass);
        $this->openApiMiddleware[] = $middlewareClass;
    }

    /**
     * @return list<class-string<MiddlewareInterface>>
     */
    public function openApiMiddlewares(): array
    {
        return $this->openApiMiddleware;
    }

    /**
     * Locks the binding set, after registering each default below that
     * the consumer has not already bound. A consumer's own registration
     * always wins.
     *
     * - `AppEnvironment` → `AppEnvironment::detect()`.
     * - `TelemetryInterface` → `Telemetry::global()`.
     * - `LoggerInterface` → `NullLogger` in production, `ErrorLogLogger`
     *   outside it. ExceptionHandlerMiddleware, TransactionGuard, and
     *   McpServer all resolve LoggerInterface through the container.
     * - `Config` → `Config::fromEnvironment()` (see Kinetis\Config) —
     *   populated from `.env` already if Kinetis\Config\EnvFile::safeLoad()
     *   ran first. Kinetis\Runtime\HttpStartup and bin/kinetis both bind
     *   their own instance before the bootstrap chain, so this default
     *   covers a scope booted without one.
     * - `FormLimits` → `FormLimits::fromConfig()`, the ceilings every
     *   request body meets.
     * - `TrustedProxies` → `TrustedProxies::fromConfig()`, the edge whose
     *   forwarding headers may decide scheme and client address.
     * - `Psr\SimpleCache\CacheInterface` → `Kinetis\SimpleCache\RedisSimpleCache::fromConfig()`
     *   when Redis is configured (`REDIS_URL`/`REDIS_HOST`/
     *   `REDIS_CLUSTER`), else `NullSimpleCache`; nothing here attempts a
     *   connection unless one of those is set. The concrete class lives
     *   in the separate `kinetis/cache-redis` package, named here only as
     *   a class-name string and `class_exists()`-gated the same way
     *   `RuntimeDetector` gates `BrefLambdaAdapter`, so core itself has
     *   no amphp/redis dependency. Redis configured with that package
     *   absent binds `Kinetis\SimpleCache\UnavailableSimpleCache`, whose
     *   every operation throws naming `kinetis/cache-redis` — see that
     *   class for why the failure lands at usage rather than boot.
     * - `Kinetis\Events\ListenerInvokerInterface` →
     *   `SynchronousListenerInvoker` — a `ShouldQueue` listener with no
     *   real queue package installed still runs, just inline.
     * - `self` → this scope, so `AppScope::class` resolves to it rather
     *   than autowiring a disconnected one.
     *
     * The three `Config`-derived defaults read the bound `Config` through
     * `get()`, not a second `Config::fromEnvironment()` call, so whichever
     * instance ended up bound is the one they are built from.
     */
    public function boot(): void
    {
        $this->assertNotDisposed('boot');

        if (!$this->has(AppEnvironment::class)) {
            $this->instance(AppEnvironment::class, AppEnvironment::detect());
        }

        // The process-wide holder, so app code can constructor-inject
        // TelemetryInterface. Hooks fire through the same holder, so a
        // backend swapped in by kinetis/telemetry's bootstrap is seen
        // everywhere at once.
        if (!$this->has(TelemetryInterface::class)) {
            $this->instance(TelemetryInterface::class, Telemetry::global());
        }

        /** @var AppEnvironment $environment */
        $environment = $this->get(AppEnvironment::class);

        // Development gets a real trail by default — an exception during
        // local development lands in the SAPI's error log with zero
        // logging setup. Production keeps the silent default; a
        // consumer-registered logger wins in both.
        if (!$this->has(LoggerInterface::class)) {
            $this->instance(
                LoggerInterface::class,
                $environment->isProduction() ? new NullLogger() : new ErrorLogLogger(),
            );
        }

        if (!$this->has(Config::class)) {
            $this->instance(Config::class, Config::fromEnvironment());
        }

        // The ceilings every request body meets, built once from this
        // scope's own Config and read by RequestBodyMiddleware inside the
        // Kernel. Registered rather than autowired: it holds a validated
        // int, which nothing can reflect its way to. An entry point that
        // built one itself registers that same instance, and this leaves
        // it alone — see docs/runtime-adapters.md.
        if (!$this->has(FormLimits::class)) {
            /** @var Config $config */
            $config = $this->get(Config::class);
            $this->instance(FormLimits::class, FormLimits::fromConfig($config));
        }

        if (!$this->has(TrustedProxies::class)) {
            /** @var Config $config */
            $config = $this->get(Config::class);
            $this->instance(TrustedProxies::class, TrustedProxies::fromConfig($config));
        }

        if (!$this->has(CacheInterface::class)) {
            /** @var Config $config */
            $config = $this->get(Config::class);
            $this->instance(CacheInterface::class, self::buildDefaultCache($config));
        }

        if (!$this->has(ListenerInvokerInterface::class)) {
            $this->instance(ListenerInvokerInterface::class, new SynchronousListenerInvoker());
        }

        // Without this binding, class_exists() answers true for
        // AppScope::class, so resolve() autowires a disconnected,
        // unbooted AppScope rather than raising — and, since it never
        // remembers an unregistered id, another one on every later
        // resolve. RequestScope self-registers for the identical reason.
        if (!$this->has(self::class)) {
            $this->instance(self::class, $this);
        }

        $this->booted = true;
    }

    /**
     * Redis is optional — `kinetis/cache-redis` provides the concrete
     * `RedisSimpleCache` class, referenced here only as a class-name
     * string so core has no amphp/redis dependency of its own. It serves
     * a single node and a Redis Cluster alike; `REDIS_CLUSTER` is a
     * switch inside its own `fromConfig()`. Only the *default*
     * connection's keys are
     * checked (`REDIS_HOST`/`REDIS_URL`/`REDIS_CLUSTER`, unscoped) — a
     * named connection is never auto-registered here, unaffected either
     * way, matching the "Named connections" convention documented in
     * {doc}`config`.
     */
    private static function buildDefaultCache(Config $config): CacheInterface
    {
        // An empty value counts as unset, not as "configured but blank":
        // `REDIS_HOST=` in a .env is how a value gets turned off, and
        // reading it as configured would make every cache operation
        // throw for an application that had switched Redis off.
        $redisConfigured = $config->bool('REDIS_CLUSTER', false)
            || $config->string('REDIS_URL', '') !== ''
            || $config->string('REDIS_HOST', '') !== '';

        if (!$redisConfigured) {
            return new NullSimpleCache();
        }

        if (!class_exists(self::REDIS_SIMPLE_CACHE_CLASS)) {
            // Every operation on this binding throws, so the failure
            // reaches whoever uses the cache while an application that
            // never touches it runs unaffected by a stale REDIS_* key.
            // Never NullSimpleCache here: silently discarding writes
            // would leave rate limits unenforced with no signal.
            return new UnavailableSimpleCache();
        }

        $cacheClass = self::REDIS_SIMPLE_CACHE_CLASS;

        /** @var ?CacheInterface $cache */
        $cache = $cacheClass::fromConfig($config);

        return $cache ?? new NullSimpleCache();
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Whether `$id` has an *explicit* registration. Deliberately does not
     * fall back to class_exists(): RequestScope uses this to decide whether
     * to delegate up to AppScope. If it did treat "any real class" as
     * present here, every unregistered class touched from a request would
     * get silently autowired and cached as a permanent app-scope singleton
     * — exactly the cross-request state bleed this two-tier split exists
     * to prevent.
     */
    #[\Override]
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    #[\Override]
    public function get(string $id): mixed
    {
        $this->assertNotDisposed("resolve \"{$id}\"");

        return $this->resolve($id);
    }

    /**
     * Registers a callback that initializes every RequestScope this
     * scope creates, before createRequestScope() returns it — how a
     * package installs request-scoped bindings and dispose callbacks
     * without each entry point (Kernel, a queue worker, an MCP transport,
     * bin/kinetis) knowing about it. Callbacks run in registration order.
     * Locked after boot() like every other registration: the set is
     * worker-lifetime configuration.
     *
     * An initializer binds lazily, so a unit of work that never resolves
     * what it binds constructs nothing. If one throws, the scope is
     * disposed — running what an earlier initializer registered on it —
     * and that failure propagates from createRequestScope().
     *
     * @param callable(RequestScope): void $initializer
     */
    public function onRequestScopeCreated(callable $initializer): void
    {
        $this->assertUsable('request scope initializer');
        $this->requestScopeInitializers[] = $initializer;
    }

    public function createRequestScope(): RequestScope
    {
        $this->assertNotDisposed('create a request scope');

        if (!$this->booted) {
            throw new ContainerException('Cannot create a request scope before the application container is booted.');
        }

        // Registers itself on itself so anything resolved through this
        // scope (middleware, a controller) can constructor-inject
        // RequestScope $scope and get this exact instance, not attempt to
        // autowire a new, disconnected one.
        $scope = new RequestScope($this);
        $scope->instance(RequestScope::class, $scope);

        try {
            foreach ($this->requestScopeInitializers as $initialize) {
                $initialize($scope);
            }
        } catch (Throwable $e) {
            // Nothing else can dispose a scope that is never returned.
            try {
                $scope->dispose();
            } catch (Throwable) {
                // Secondary: the initializer's failure is the outcome.
            }

            throw $e;
        }

        return $scope;
    }

    /**
     * Registers a callback to run when this scope is disposed — the
     * application-lifetime counterpart to
     * {@see RequestScope::onDispose()}, for the resource a package opens
     * once per worker (a connection pool, a background client) and has
     * to close once the worker ends.
     *
     * Allowed before *and* after boot(), unlike every other registration
     * here, because an app-scoped factory is lazy: a pool that opens on
     * first resolution can only register its own close operation then,
     * with the binding set long since locked. Refused only once the
     * scope has actually been disposed, where nothing would ever run it.
     *
     * @param callable(): void $callback
     */
    public function onDispose(callable $callback): void
    {
        $this->assertNotDisposed('register a dispose callback');
        $this->disposeCallbacks[] = $callback;
    }

    /**
     * Ends this scope's lifetime: every callback onDispose() registered
     * runs in registration order, the scope is then marked disposed, and
     * only then is every retained binding, instance and registration list
     * released. All of that happens whether or not a callback failed and
     * whether or not releasing a retained object ran a destructor that
     * threw, so nothing worker-lifetime survives a failed teardown; the
     * first failure is rethrown once the wipe has completed.
     *
     * The scope is marked disposed *before* the release rather than after
     * it: replacing a property holding the last reference to a service
     * runs that service's destructor, and a destructor reaching back into
     * this scope must be refused rather than handed a half-wiped
     * container. Each release that can hold an object or a callable is
     * contained on its own for the same reason a callback is — PHP
     * surfaces a destructor's exception from the assignment that
     * triggered it, which would otherwise abandon every collection after
     * it.
     *
     * Disposing twice is harmless: the second call has nothing left to
     * run or release and returns. Everything else — binding, resolution,
     * boot, request-scope creation, further dispose registration — is
     * refused afterwards, since this scope no longer holds what any of
     * them would need.
     */
    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $firstError = null;

        foreach ($this->disposeCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $firstError ??= $e;
            }
        }

        // The loop variable still holds the last callback. Released here,
        // its destruction belongs to the contained property clear below;
        // left in place it happens as dispose() unwinds, outside every
        // guard, where what it captured replaces the reported failure.
        unset($callback);

        $this->disposed = true;

        // A destructor is the one thing PHPStan cannot see here:
        // replacing a collection that holds objects or callables destroys
        // them, and PHP surfaces a __destruct() exception from the
        // assignment itself. Each such release is contained so one of them
        // cannot abandon the collections after it. `resolving`,
        // `middleware` and `openApiMiddleware` admit only `true` and
        // class-name strings, which destroy nothing.

        try {
            $this->bindings = [];
        // @phpstan-ignore-next-line catch.neverThrown
        } catch (Throwable $e) {
            $firstError ??= $e;
        }

        $this->resolving = [];
        $this->middleware = [];
        $this->openApiMiddleware = [];

        try {
            $this->requestScopeInitializers = [];
        // @phpstan-ignore-next-line catch.neverThrown
        } catch (Throwable $e) {
            $firstError ??= $e;
        }

        try {
            $this->disposeCallbacks = [];
        // @phpstan-ignore-next-line catch.neverThrown
        } catch (Throwable $e) {
            $firstError ??= $e;
        }

        if ($firstError !== null) {
            throw $firstError;
        }
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    private function resolve(string $id): mixed
    {
        $binding = $this->bindings[$id] ?? null;

        if ($binding?->resolved() !== null) {
            return $binding->resolved();
        }

        // RequestScope only exists per request; resolving it here
        // would otherwise autowire a disconnected, unbooted one.
        if ($binding === null && $id === RequestScope::class) {
            throw DisconnectedRequestScopeException::forPath([...array_keys($this->resolving), $id]);
        }

        if (isset($this->resolving[$id])) {
            throw CircularDependencyException::forPath([...array_keys($this->resolving), $id]);
        }

        if ($binding === null && !class_exists($id)) {
            throw NotFoundException::forId($id);
        }

        $this->resolving[$id] = true;

        try {
            $instance = $binding !== null
                ? ($binding->factory)($this)
                : Autowire::instantiate($id, $this);
        } finally {
            unset($this->resolving[$id]);
        }

        // An explicit shared binding remembers its instance for next time —
        // the registered-singleton contract. An id with no binding at all,
        // autowired purely because the class exists, deliberately does not:
        // an unregistered class is never promoted to a hidden
        // worker-lifetime singleton, the same "never promoted" guarantee
        // RequestScope makes, applied to the parent scope's own public API.
        // Anything genuinely needing one shared instance registers it
        // explicitly before boot().
        if ($binding !== null && $binding->shared) {
            $binding->remember($instance);
        }

        return $instance;
    }

    private function normalizeConcrete(string $id, Closure|string|null $concrete): Closure
    {
        if ($concrete === null) {
            $class = $this->assertClassString($id);

            return static fn (ContainerInterface $c): object => Autowire::instantiate($class, $c);
        }

        if (is_string($concrete)) {
            $class = $this->assertClassString($concrete);

            return static fn (ContainerInterface $c): object => Autowire::instantiate($class, $c);
        }

        return $concrete;
    }

    /**
     * @return class-string
     */
    private function assertClassString(string $id): string
    {
        if (!class_exists($id)) {
            throw new ContainerException(
                "Cannot bind \"{$id}\": no concrete implementation was given and \"{$id}\" is not an existing class."
            );
        }

        return $id;
    }

    /**
     * The registration guard: a disposed scope is refused first, so a
     * caller reaching a scope whose lifetime has ended is told that
     * rather than that its bindings are locked — two different
     * mistakes, and only one of them is fixable by registering earlier.
     */
    private function assertUsable(string $id): void
    {
        $this->assertNotDisposed("register \"{$id}\"");

        if ($this->booted) {
            throw new ContainerException(
                "Cannot register \"{$id}\": the application container is booted and its bindings are locked."
            );
        }
    }

    private function assertNotDisposed(string $operation): void
    {
        if ($this->disposed) {
            throw new ContainerException(
                "Cannot {$operation}: this application container has been disposed and cannot be reused."
            );
        }
    }
}
