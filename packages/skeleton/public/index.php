<?php

declare(strict_types=1);

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Cache\RoutesFile;
use Kinetis\Config\Config;
use Kinetis\Config\EnvFile;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Runtime\RuntimeDetector;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = ProjectRoot::detect(__DIR__);

// Loaded before AppEnvironment::detect(): APP_ENV itself might be defined
// for the first time in .env, not already set in the real process
// environment.
EnvFile::safeLoad($projectRoot);

$env = AppEnvironment::detect();
$store = new CacheStore($projectRoot . '/.kinetis-cache');

$app = new AppScope();
$config = Config::fromEnvironment();
$app->instance(Config::class, $config);
$httpCache = null;
$cacheStore = null;

// Computed before boot(): EventListenerRegistry has to be $app->instance()'d
// below, and instance() is locked after boot() the same as bind()/
// middleware() — unlike $router/$discoveredGlobalMiddleware, which are
// plain constructor arguments Kernel takes directly and never touch
// AppScope at all.
if ($env->isProduction()) {
    // First request in this process to find no cache present compiles
    // once and writes all five artifacts — safe under concurrent workers
    // racing to be "first" (see CacheStore::writeAll()'s atomic tmp+rename).
    // Every request after that, on any worker, just loads http.php here;
    // $cacheStore is handed to Kernel so it can *lazily* load openapi.php
    // only if this specific request turns out to hit /openapi.json.
    $cacheStore = $store;
    $httpCache = $store->loadHttp();

    if ($httpCache === null) {
        $compiled = (new Compiler())->compileProject($projectRoot);
        $store->writeAll($compiled);
        $httpCache = $compiled->http;
        $eventCache = $compiled->events;
    } else {
        $eventCache = $store->loadEvents();
    }

    $router = Router::fromArray($httpCache->routes);
    $discoveredGlobalMiddleware = $httpCache->globalMiddleware;
    $discoveredMcpMiddleware = $httpCache->mcpMiddleware;
    $discoveredOpenApiMiddleware = $httpCache->openApiMiddleware;
    $middlewareGroups = $httpCache->middlewareGroups;
    // Another confirmed nullsafe.neverNull false positive (see
    // AppScope::resolve()/RequestScope's own documented case, and this
    // file's twin in bin/kinetis) — $eventCache is genuinely nullable
    // here: the `else` branch above assigns it from
    // CacheStore::loadEvents(): ?EventCache, which really can return null
    // (a stale/foreign-format events.php, or one that simply doesn't
    // exist yet). Verified directly with an isolated repro forcing that
    // exact branch before trusting PHPStan's "always non-null" claim —
    // removing the `?->` here would be a real, reachable fatal error.
    $listenerRegistry = EventListenerRegistry::fromArray($eventCache?->listeners ?? []); // @phpstan-ignore nullsafe.neverNull
    $packageBootstraps = $httpCache->packageBootstraps;
} else {
    // Any class anywhere under one of your own PSR-4 roots is picked up
    // automatically — nothing to register.
    $router = RouteDiscovery::discover($projectRoot);
    // Same for a class carrying #[AsGlobalMiddleware]/#[AsMcpMiddleware]/
    // #[AsOpenApiMiddleware] or #[Listener] — no AppScope::middleware()
    // call, or manual EventListenerRegistry construction in
    // bootstrap.php, needed for any of them. One shared scan produces all
    // three middleware lists at once.
    $discoveredMiddleware = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
    $discoveredGlobalMiddleware = $discoveredMiddleware['global'];
    $discoveredMcpMiddleware = $discoveredMiddleware['mcp'];
    $discoveredOpenApiMiddleware = $discoveredMiddleware['openApi'];
    $middlewareGroups = $discoveredMiddleware['groups'];
    $listenerRegistry = EventListenerDiscovery::discover($projectRoot);
    // null = discover the package bootstrap list live, alongside the rest.
    $packageBootstraps = null;
}

// The bootstrap chain: every installed package's declared
// PackageBootstrapInterface first, then this application's own
// bootstrap.php — which therefore always wins on a shared binding.
// bootstrap.php registers anything package bootstraps don't cover, e.g.:
// return static function (Kinetis\Container\AppScope $app, Config $config): void {
//     $app->instance(SomeConnectionPool::class, SomeConnectionPool::fromConfig($config));
// };
RoutesFile::loadBootstrap($projectRoot, $packageBootstraps)($app, $config);

$app->instance(EventListenerRegistry::class, $listenerRegistry);
$app->boot();

// Detected before constructing Kernel, not after, so its isPersistent()
// can be passed straight into the constructor rather than patched in.
$adapter = RuntimeDetector::detect();

$kernel = new Kernel(
    $app,
    $router,
    isPersistent: $adapter->isPersistent(),
    httpCache: $httpCache,
    cacheStore: $cacheStore,
    discoveredGlobalMiddleware: $discoveredGlobalMiddleware,
    discoveredMcpMiddleware: $discoveredMcpMiddleware,
    discoveredOpenApiMiddleware: $discoveredOpenApiMiddleware,
    middlewareGroups: $middlewareGroups,
);

$adapter->run($kernel->handle(...));
