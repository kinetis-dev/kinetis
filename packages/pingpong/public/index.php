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
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Runtime\RuntimeDetector;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = ProjectRoot::detect(__DIR__);

// Loaded before AppEnvironment::detect(): APP_ENV itself might be defined
// for the first time in .env, not already set in the real process
// environment.
$phases = [];
$phaseStart = microtime(true);
EnvFile::safeLoad($projectRoot);
$phases['bootstrap.env'] = [$phaseStart, microtime(true)];

$env = AppEnvironment::detect();
$store = new CacheStore($projectRoot . '/.kinetis-cache');

$app = new AppScope();
$config = Config::fromEnvironment();
$app->instance(Config::class, $config);

$httpCache = null;

// Same APP_ENV-gated cache-or-discover split as kinetis/framework's own
// reference public/index.php — mirrored here rather than diverging from
// it, so this app's routes and middleware actually benefit from
// `bin/kinetis build` the way the caching docs describe. The /mcp
// endpoint and this app's own tools need nothing here: kinetis/mcp's
// bootstrap binds the server, and its controller is discovered like any
// other route.
if ($env->isProduction()) {
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
    $discoveredOpenApiMiddleware = $httpCache->openApiMiddleware;
    $middlewareGroups = $httpCache->middlewareGroups;
    $listenerRegistry = EventListenerRegistry::fromArray($eventCache !== null ? $eventCache->listeners : []);
    $packageBootstraps = $httpCache->packageBootstraps;
} else {
    $phaseStart = microtime(true);
    // Discovered before routes, not after: RouteDiscovery needs the
    // global middleware list itself, to resolve any #[RoutePrefix] those
    // classes declare into every route's own path. Same scan also covers
    // a class carrying #[AsOpenApiMiddleware] or #[Listener] — no
    // AppScope::middleware() call, or manual EventListenerRegistry
    // construction in bootstrap.php, needed for any of them. One shared
    // scan produces both middleware lists at once.
    $discoveredMiddleware = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
    // Any class anywhere under one of your own PSR-4 roots is picked up
    // automatically — nothing to register.
    $router = RouteDiscovery::discover($projectRoot, globalMiddleware: $discoveredMiddleware['global']);
    $discoveredGlobalMiddleware = $discoveredMiddleware['global'];
    $discoveredOpenApiMiddleware = $discoveredMiddleware['openApi'];
    $middlewareGroups = $discoveredMiddleware['groups'];
    $listenerRegistry = EventListenerDiscovery::discover($projectRoot);
    // null = discover the package bootstrap list live, alongside the rest.
    $packageBootstraps = null;
    $phases['bootstrap.discovery'] = [$phaseStart, microtime(true)];
}

// The bootstrap chain: every installed package's declared
// PackageBootstrapInterface first (kinetis/persistence binding MysqlLink,
// kinetis/queue binding QueueInterface), then this application's own
// bootstrap.php — which therefore always wins on a shared binding.
$phaseStart = microtime(true);
RoutesFile::loadBootstrap($projectRoot, $packageBootstraps)($app, $config);
$phases['bootstrap.services'] = [$phaseStart, microtime(true)];

$app->instance(EventListenerRegistry::class, $listenerRegistry);
$app->boot();

// Reported only now: these phases ran before any telemetry backend
// could exist, so they were measured with plain timestamps and are
// handed to whatever backend the bootstrap chain just swapped in.
$telemetry = Telemetry::global();

foreach ($phases as $phaseName => [$phaseStartedAt, $phaseEndedAt]) {
    $telemetry->phase($phaseName, $phaseStartedAt, $phaseEndedAt);
}

// Detected before constructing Kernel, not after, so its isPersistent()
// can be passed straight into the constructor rather than patched in.
$adapter = RuntimeDetector::detect();

$kernel = new Kernel(
    $app,
    $router,
    isPersistent: $adapter->isPersistent(),
    httpCache: $httpCache,
    discoveredGlobalMiddleware: $discoveredGlobalMiddleware,
    discoveredOpenApiMiddleware: $discoveredOpenApiMiddleware,
    middlewareGroups: $middlewareGroups,
);

$adapter->run($kernel->handle(...));
