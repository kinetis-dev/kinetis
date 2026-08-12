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
use Kinetis\Mcp\McpDiscovery;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Runtime\RuntimeDetector;
use Psr\Log\LoggerInterface;

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
RoutesFile::loadBootstrap($projectRoot)($app, $config);

$httpCache = null;
$cacheStore = null;

// Same APP_ENV-gated cache-or-discover split as kinetis/kinetis's own
// reference public/index.php — mirrored here rather than diverging from
// it, so this app's routes/middleware/MCP tools actually benefit from
// `bin/kinetis build` the way the caching docs describe.
if ($env->isProduction()) {
    $cacheStore = $store;
    $httpCache = $store->loadHttp();

    if ($httpCache === null) {
        $compiled = (new Compiler())->compileProject($projectRoot);
        $store->writeAll($compiled);
        $httpCache = $compiled->http;
        $mcpCache = $compiled->mcp;
        $eventCache = $compiled->events;
    } else {
        $mcpCache = $store->loadMcp();
        $eventCache = $store->loadEvents();
    }

    $router = Router::fromArray($httpCache->routes);
    $discoveredGlobalMiddleware = $httpCache->globalMiddleware;
    $discoveredMcpMiddleware = $httpCache->mcpMiddleware;
    $discoveredOpenApiMiddleware = $httpCache->openApiMiddleware;
    $listenerRegistry = EventListenerRegistry::fromArray($eventCache !== null ? $eventCache->listeners : []);
    $mcpRegistry = $mcpCache !== null
        ? McpRegistry::fromArray(['tools' => $mcpCache->mcpTools, 'resources' => $mcpCache->mcpResources])
        : McpDiscovery::discover($projectRoot);
    $mcpBindingPlans = $mcpCache !== null ? $mcpCache->mcpBindingPlans : [];
    $mcpHydrationPlans = $mcpCache !== null ? $mcpCache->hydrationPlans : [];
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
    $listenerRegistry = EventListenerDiscovery::discover($projectRoot);
    $mcpRegistry = McpDiscovery::discover($projectRoot);
    $mcpBindingPlans = [];
    $mcpHydrationPlans = [];
}

$app->instance(EventListenerRegistry::class, $listenerRegistry);
$app->boot();

$mcp = new McpServer(
    $mcpRegistry,
    new McpDispatcher($app, $mcpBindingPlans, $mcpHydrationPlans),
    logger: $app->get(LoggerInterface::class),
);

// Detected before constructing Kernel, not after, so its isPersistent()
// can be passed straight into the constructor rather than patched in.
$adapter = RuntimeDetector::detect();

$kernel = new Kernel(
    $app,
    $router,
    isPersistent: $adapter->isPersistent(),
    mcp: $mcp,
    httpCache: $httpCache,
    cacheStore: $cacheStore,
    discoveredGlobalMiddleware: $discoveredGlobalMiddleware,
    discoveredMcpMiddleware: $discoveredMcpMiddleware,
    discoveredOpenApiMiddleware: $discoveredOpenApiMiddleware,
);

$adapter->run($kernel->handle(...));
