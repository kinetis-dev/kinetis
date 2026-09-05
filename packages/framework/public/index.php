<?php

declare(strict_types=1);

use Kinetis\Cache\BootSequence;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\Compiler;
use Kinetis\Config\Config;
use Kinetis\Config\EnvFile;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\TrustedProxies;
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
$pluginInstances = null;

// Computed before boot(): EventListenerRegistry is bound via
// BootSequence::run() below, and instance() is locked after boot() the
// same as bind()/middleware() — unlike $router/$discoveredGlobalMiddleware,
// which are plain constructor arguments Kernel takes directly and never
// touch AppScope at all.
if ($env->isProduction()) {
    // BootSequence::resolveHttp() is the entire "use the cache, or
    // compile fresh" decision: http.php, events.php, and plugins.php
    // must all be present, the right format, and actually reconstruct
    // into live objects — Router/EventListenerRegistry/every plugin
    // instance included, not just the raw DTOs — or the whole
    // generation is treated as absent and $compile runs exactly once,
    // safe under concurrent workers racing to be "first" (see
    // CacheStore::writeAll()'s own docblock: each racing writer
    // publishes its own complete generation, never a partial or mixed
    // one). See its own docblock.
    $resolved = BootSequence::resolveHttp($store, static fn (): CompiledCache => (new Compiler())->compileProject($projectRoot));

    $httpCache = $resolved['httpCache'];
    $router = $resolved['router'];
    $listenerRegistry = $resolved['listenerRegistry'];
    $pluginInstances = $resolved['pluginInstances'];
    $discoveredGlobalMiddleware = $httpCache->globalMiddleware;
    $discoveredOpenApiMiddleware = $httpCache->openApiMiddleware;
    $middlewareGroups = $httpCache->middlewareGroups;
    $packageBootstraps = $httpCache->packageBootstraps;
} else {
    $phaseStart = microtime(true);
    // Discovered before routes, not alongside them: RouteDiscovery needs
    // the global middleware list itself, to resolve any #[RoutePrefix]
    // those classes declare into every route's own path (see
    // Router::register()'s own doc comment). Same scan also covers a
    // class carrying #[AsMcpMiddleware]/#[AsOpenApiMiddleware]/
    // #[AsMiddlewareGroup] or #[Listener] — no AppScope::middleware()
    // call, or manual EventListenerRegistry construction in
    // bootstrap.php, needed for any of them. One shared scan produces
    // all three middleware lists plus every named group at once.
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
    // $pluginInstances stays null from its declaration above — the same
    // sentinel BootSequence::run() reads as "discover and reconstruct
    // live" below.
    $phases['bootstrap.discovery'] = [$phaseStart, microtime(true)];
}

// The two policies an adapter needs before the Kernel or its container
// exist: how many bytes a request body may carry, and whose forwarded
// headers may decide this request's scheme and client address. Built
// from the same Config everything else here came from and registered
// before the bootstrap chain runs, so bootstrap.php can replace either
// one the way it replaces any other binding: AppScope locks its
// bindings at boot(), and every registration has to be on the near side
// of that.
$app->instance(FormLimits::class, FormLimits::fromConfig($config));
$app->instance(TrustedProxies::class, TrustedProxies::fromConfig($config));

// PluginDiscovery::bindInstances() and the discovered EventListenerRegistry
// both have to be bound before the bootstrap chain runs — bootstrap.php's
// own last-write-wins override (resolving and augmenting a discovered
// instance, or replacing it outright) only actually wins if something
// is already there to act on, not asserted again afterward.
// BootSequence::run() is the one place this ordering lives, shared by
// every framework-managed entry point (bin/kinetis, TestApplication, and
// the identical reference copies in kinetis/skeleton/kinetis/pingpong)
// so none of them can drift from the others on it again — see its own
// docblock. null pluginInstances (development, or production with
// nothing cached yet) means "discover and reconstruct live instead."
// bootstrap.php itself registers anything package bootstraps don't
// cover, e.g.:
// return static function (Kinetis\Container\AppScope $app, Config $config): void {
//     $app->instance(SomeConnectionPool::class, SomeConnectionPool::fromConfig($config));
// };
$phaseStart = microtime(true);
BootSequence::run($app, $projectRoot, $config, $listenerRegistry, $pluginInstances, $packageBootstraps);
$app->boot();
$phases['bootstrap.services'] = [$phaseStart, microtime(true)];

// Reported only now: these phases ran before any telemetry backend
// could exist, so they were measured with plain timestamps and are
// handed to whatever backend the bootstrap chain just swapped in.
$telemetry = Telemetry::global();

foreach ($phases as $phaseName => [$phaseStartedAt, $phaseEndedAt]) {
    $telemetry->phase($phaseName, $phaseStartedAt, $phaseEndedAt);
}

// Detected before constructing Kernel, not after, so its isPersistent()
// can be passed straight into the constructor rather than patched in.
// Both policies are read back out of the container rather than kept
// from the registration above: whatever the bootstrap chain left bound
// is what MaxBodySizeMiddleware enforces inside the Kernel, so the
// adapter has to bound and forward this request by those same two
// objects.
/** @var FormLimits $formLimits */
$formLimits = $app->get(FormLimits::class);
/** @var TrustedProxies $trustedProxies */
$trustedProxies = $app->get(TrustedProxies::class);

$adapter = RuntimeDetector::detect($formLimits, $trustedProxies);

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
