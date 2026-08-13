<?php

declare(strict_types=1);

namespace Kinetis\Container;

use Kinetis\Config\Config;
use Kinetis\Container\Exception\CircularDependencyException;
use Kinetis\Container\Exception\ContainerException;
use Kinetis\Container\Exception\NotFoundException;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Events\SynchronousListenerInvoker;
use Kinetis\Logging\ErrorLogLogger;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException;
use Kinetis\SimpleCache\NullSimpleCache;
use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * The persistent, worker-lifetime container. Services registered here are
 * booted once at server startup and live for as long as the worker process
 * does — they must never hold per-request state.
 *
 * Registration is only allowed before boot(). Once booted, the binding set
 * is locked: this is what makes "compiled routes and service definitions
 * booted once at server startup" an enforced invariant rather than a
 * convention that can quietly drift.
 */
final class AppScope implements ContainerInterface
{
    // Not `use` imports — see buildDefaultCache()'s docblock and
    // RuntimeDetector::BREF_ADAPTER_CLASS for why a plain string constant
    // is deliberate here: referencing either name never triggers
    // autoloading on its own, only class_exists()/instantiation does.
    private const REDIS_SIMPLE_CACHE_CLASS = 'Kinetis\SimpleCache\RedisSimpleCache';
    private const REDIS_CLUSTER_CACHE_CLASS = 'Kinetis\SimpleCache\ClusteredRedisSimpleCache';

    /** @var array<string, Binding> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $middleware = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $mcpMiddleware = [];

    /** @var list<class-string<MiddlewareInterface>> */
    private array $openApiMiddleware = [];

    private bool $booted = false;

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = true): void
    {
        $this->assertNotBooted($id);
        $this->bindings[$id] = new Binding($this->normalizeConcrete($id, $concrete), $shared);
    }

    public function instance(string $id, object $instance): void
    {
        $this->assertNotBooted($id);
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
        $this->assertNotBooted($middlewareClass);
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
     * Registers middleware scoped to Kernel's `/mcp` endpoint only — never
     * runs for any other route, unlike middleware(). Global middleware
     * already wraps `/mcp` (see Kernel's own docblock), so this exists for
     * the narrower need of protecting `/mcp` specifically without also
     * touching unrelated traffic. Same registration-order/lock-after-
     * boot() discipline as middleware().
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function mcpMiddleware(string $middlewareClass): void
    {
        $this->assertNotBooted($middlewareClass);
        $this->mcpMiddleware[] = $middlewareClass;
    }

    /**
     * @return list<class-string<MiddlewareInterface>>
     */
    public function mcpMiddlewares(): array
    {
        return $this->mcpMiddleware;
    }

    /**
     * Registers middleware scoped to Kernel's `/openapi.json` and `/docs`
     * endpoints only — one registration point for both, since they're the
     * same "expose the API's own shape" concern. See mcpMiddleware()'s own
     * docblock for why this narrower-than-global scoping exists at all.
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function openApiMiddleware(string $middlewareClass): void
    {
        $this->assertNotBooted($middlewareClass);
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
     * Locks the binding set. Also registers four default bindings if the
     * consumer hasn't already registered their own:
     *
     * - `LoggerInterface` → `NullLogger`. ExceptionHandlerMiddleware,
     *   TransactionGuard, and McpServer all resolve LoggerInterface
     *   through the container.
     * - `Config` → `Config::fromEnvironment()` (see Kinetis\Config) —
     *   populated from `.env` already if Kinetis\Config\EnvFile::safeLoad()
     *   ran first, as public/index.php and bin/kinetis both do.
     * - `Psr\SimpleCache\CacheInterface` → `Kinetis\SimpleCache\ClusteredRedisSimpleCache::fromConfig()`
     *   when `REDIS_CLUSTER=true`, else `Kinetis\SimpleCache\RedisSimpleCache::fromConfig()`
     *   when Redis is actually configured (`REDIS_URL`/`REDIS_HOST`), else
     *   `NullSimpleCache` — Redis is optional, not every consumer needs it,
     *   so nothing here ever attempts a connection unless one was
     *   explicitly configured. Resolved *after* `Config` is registered
     *   above, via `get()`, not a second `Config::fromEnvironment()` call —
     *   whichever `Config` instance ends up bound (the consumer's own, or
     *   the default just registered) is the one this reads. Both concrete
     *   classes live in the separate `kinetis/cache-redis` package, not
     *   core — referenced here only as class-name strings,
     *   `class_exists()`-gated the same way `RuntimeDetector` gates
     *   `BrefLambdaAdapter`, so core itself has no amphp/redis dependency.
     *   Redis being *configured* (any of the three env vars above) with
     *   neither class installed is a clear `SimpleCacheUnavailableException`
     *   naming `kinetis/cache-redis`, not a silent `NullSimpleCache`
     *   fallback — the same "explicit intent, missing package is an error"
     *   precedent `kinetis/storage`'s own `FILESYSTEM_DRIVER=s3` gate
     *   already establishes.
     * - `Kinetis\Events\ListenerInvokerInterface` →
     *   `SynchronousListenerInvoker` — a `ShouldQueue` listener with no
     *   real queue package installed still runs, just inline.
     */
    public function boot(): void
    {
        if (!$this->has(AppEnvironment::class)) {
            $this->instance(AppEnvironment::class, AppEnvironment::detect());
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

        if (!$this->has(CacheInterface::class)) {
            /** @var Config $config */
            $config = $this->get(Config::class);
            $this->instance(CacheInterface::class, self::buildDefaultCache($config));
        }

        if (!$this->has(ListenerInvokerInterface::class)) {
            $this->instance(ListenerInvokerInterface::class, new SynchronousListenerInvoker());
        }

        // Closes a real, previously-documented-but-unfixed trap: without
        // this, resolving AppScope::class through itself doesn't fail
        // loudly — it silently autowires a brand-new, disconnected, unbooted
        // AppScope instead (class_exists('Kinetis\Container\AppScope') is
        // true, so Autowire::instantiate() happily constructs one), and
        // caches that wrong instance forever. RequestScope already
        // self-registers for the identical reason; AppScope never did.
        if (!$this->has(self::class)) {
            $this->instance(self::class, $this);
        }

        $this->booted = true;
    }

    /**
     * Redis is optional — `kinetis/cache-redis` provides the concrete
     * `RedisSimpleCache`/`ClusteredRedisSimpleCache` classes, referenced
     * here only as class-name strings so core has no amphp/redis
     * dependency of its own. Only the *default* connection's keys are
     * checked (`REDIS_HOST`/`REDIS_URL`/`REDIS_CLUSTER`, unscoped) — a
     * named connection is never auto-registered here, unaffected either
     * way, matching the "Named connections" convention documented in
     * {doc}`config`.
     */
    private static function buildDefaultCache(Config $config): CacheInterface
    {
        $redisConfigured = $config->bool('REDIS_CLUSTER', false)
            || $config->get('REDIS_URL') !== null
            || $config->get('REDIS_HOST') !== null;

        if (!$redisConfigured) {
            return new NullSimpleCache();
        }

        if (!class_exists(self::REDIS_CLUSTER_CACHE_CLASS) && !class_exists(self::REDIS_SIMPLE_CACHE_CLASS)) {
            throw SimpleCacheUnavailableException::missingDriverPackage('kinetis/cache-redis');
        }

        $clusterClass = self::REDIS_CLUSTER_CACHE_CLASS;
        $simpleClass = self::REDIS_SIMPLE_CACHE_CLASS;

        /** @var ?CacheInterface $cache */
        $cache = class_exists($clusterClass) ? $clusterClass::fromConfig($config) : null;
        /** @var ?CacheInterface $cache */
        $cache ??= class_exists($simpleClass) ? $simpleClass::fromConfig($config) : null;

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
        return $this->resolve($id);
    }

    public function createRequestScope(): RequestScope
    {
        if (!$this->booted) {
            throw new ContainerException('Cannot create a request scope before the application container is booted.');
        }

        // Registers itself on itself so anything resolved through this
        // scope (middleware, a controller) can constructor-inject
        // RequestScope $scope and get this exact instance, not attempt to
        // autowire a new, disconnected one.
        $scope = new RequestScope($this);
        $scope->instance(RequestScope::class, $scope);

        return $scope;
    }

    private function resolve(string $id): mixed
    {
        $binding = $this->bindings[$id] ?? null;

        if ($binding?->resolved() !== null) {
            return $binding->resolved();
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

    private function assertNotBooted(string $id): void
    {
        if ($this->booted) {
            throw new ContainerException(
                "Cannot register \"{$id}\": the application container is booted and its bindings are locked."
            );
        }
    }
}
