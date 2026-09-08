<?php

declare(strict_types=1);

namespace Kinetis\Runtime;

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

/**
 * The HTTP startup program itself, owned by the framework. An
 * application's `public/index.php` is the Composer autoloader plus one
 * call:
 *
 *     require dirname(__DIR__) . '/vendor/autoload.php';
 *
 *     Kinetis\Runtime\HttpStartup::run(__DIR__);
 *
 * All of it runs once per worker process — `bootstrap.php` runs per
 * FrankenPHP worker thread, and once per process under a boot-and-die
 * SAPI. Nothing here is per-request: the {@see Kernel} it hands the
 * adapter creates and disposes a `RequestScope` around every request it
 * serves.
 *
 * The order below is the whole contract, and every step depends on the
 * one before it:
 *
 * 1. `.env` loads before `AppEnvironment::detect()`, because `APP_ENV`
 *    itself may be defined for the first time in `.env` rather than
 *    already set in the real process environment.
 * 2. Development discovers routes, middleware and listeners live from
 *    source on every boot. Production resolves them through
 *    {@see BootSequence::resolveHttp()} — the published artifact, or one
 *    fresh compile published as the very artifact `kinetis build`
 *    produces when there is none this build can use.
 * 3. `FormLimits` and `TrustedProxies` are registered before the
 *    package/application bootstrap chain, which is what leaves
 *    `bootstrap.php` able to replace either: `AppScope` locks its
 *    bindings at `boot()`, and the last write before that lock wins.
 * 4. The discovered or reconstructed plugin instances and
 *    `EventListenerRegistry` bind before that same chain, for the same
 *    reason — {@see BootSequence::run()} owns that ordering.
 * 5. The bootstrap phases are reported to telemetry only after `boot()`,
 *    since they run before any backend could exist and are measured with
 *    plain timestamps until one does.
 * 6. The proxy policy handed to the adapter is read back out of the
 *    booted container, so a `bootstrap.php` that narrowed it decides
 *    this request's scheme and client address rather than leaving the
 *    adapter on a second object built beside it. The body ceiling is not
 *    passed to the adapter at all: an adapter hands the body on raw, and
 *    `RequestBodyMiddleware` bounds and parses it inside the Kernel
 *    under whatever `FormLimits` the container holds.
 * 7. The adapter is detected before the Kernel is constructed, so its
 *    `isPersistent()` is a constructor argument rather than something
 *    patched in afterwards.
 */
final class HttpStartup
{
    private function __construct(
        public readonly AppScope $app,
        public readonly Kernel $kernel,
        public readonly RuntimeAdapterInterface $adapter,
    ) {}

    /**
     * The one call an entry point makes. `$entryPointDir` is the
     * directory of the `public/index.php` making it; `ProjectRoot` turns
     * that into the project root the rest of startup works from.
     *
     * Returns when the adapter's own loop does, which for a persistent
     * runtime is when the worker is shutting down.
     */
    public static function run(string $entryPointDir): void
    {
        self::assemble(ProjectRoot::detect($entryPointDir))->serve();
    }

    /** Hands the Kernel to the detected adapter's own request loop. */
    public function serve(): void
    {
        $this->adapter->run($this->kernel->handle(...));
    }

    /**
     * Startup up to, but not including, handing the Kernel to the
     * adapter's loop, exposing the booted container, the Kernel and the
     * adapter — which is how a test observes a real boot without
     * entering a SAPI loop that never returns.
     *
     * `$detectAdapter` replaces {@see RuntimeDetector::detect()}, taking
     * the proxy policy the booted container settled on and returning the
     * adapter to drive. It is how a deployment pins one adapter instead
     * of letting detection choose, and how a test sees what reached it.
     * `null` — what `run()` passes — detects the runtime this process is
     * actually in.
     *
     * @param (callable(TrustedProxies): RuntimeAdapterInterface)|null $detectAdapter
     */
    public static function assemble(string $projectRoot, ?callable $detectAdapter = null): self
    {
        /** @var array<string, array{float, float}> $phases */
        $phases = [];

        $phaseStart = microtime(true);
        EnvFile::safeLoad($projectRoot);
        $phases['bootstrap.env'] = [$phaseStart, microtime(true)];

        $env = AppEnvironment::detect();

        $app = new AppScope();
        $config = Config::fromEnvironment();
        $app->instance(Config::class, $config);

        $httpCache = null;
        $pluginInstances = null;

        if ($env->isProduction()) {
            // resolveHttp() is the entire "use the artifact, or compile
            // fresh" decision: .kinetis-cache/compiled.php has to be
            // present, the right format, and reconstruct into live
            // objects — Router/EventListenerRegistry/every plugin
            // instance included, not just the raw DTOs — or it counts as
            // absent and the compile runs exactly once. See its own
            // docblock.
            $resolved = BootSequence::resolveHttp(
                new CacheStore($projectRoot . '/.kinetis-cache'),
                static fn (): CompiledCache => new Compiler()->compileProject($projectRoot),
            );

            $httpCache = $resolved['httpCache'];
            $router = $resolved['router'];
            $listenerRegistry = $resolved['listenerRegistry'];
            $pluginInstances = $resolved['pluginInstances'];
            $globalMiddleware = $httpCache->globalMiddleware;
            $openApiMiddleware = $httpCache->openApiMiddleware;
            $middlewareGroups = $httpCache->middlewareGroups;
            $packageBootstraps = $resolved['packageBootstraps'];
        } else {
            $phaseStart = microtime(true);
            // Middleware before routes: RouteDiscovery needs the global
            // middleware list to resolve any #[RoutePrefix] those classes
            // declare into every route's own path — see
            // Router::register()'s own doc comment. That one scan also
            // covers #[AsOpenApiMiddleware] and #[AsMiddlewareGroup], so
            // all three lists come out of it at once.
            $discovered = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
            $router = RouteDiscovery::discover($projectRoot, globalMiddleware: $discovered['global']);
            $globalMiddleware = $discovered['global'];
            $openApiMiddleware = $discovered['openApi'];
            $middlewareGroups = $discovered['groups'];
            $listenerRegistry = EventListenerDiscovery::discover($projectRoot);
            // null = discover the package bootstrap list live, and
            // discover and reconstruct the plugin instances live, both
            // alongside the rest.
            $packageBootstraps = null;
            $phases['bootstrap.discovery'] = [$phaseStart, microtime(true)];
        }

        $app->instance(FormLimits::class, FormLimits::fromConfig($config));
        $app->instance(TrustedProxies::class, TrustedProxies::fromConfig($config));

        $phaseStart = microtime(true);
        BootSequence::run($app, $projectRoot, $config, $listenerRegistry, $pluginInstances, $packageBootstraps);
        $app->boot();
        $phases['bootstrap.services'] = [$phaseStart, microtime(true)];

        $telemetry = Telemetry::global();

        foreach ($phases as $phaseName => [$phaseStartedAt, $phaseEndedAt]) {
            $telemetry->phase($phaseName, $phaseStartedAt, $phaseEndedAt);
        }

        /** @var TrustedProxies $trustedProxies */
        $trustedProxies = $app->get(TrustedProxies::class);

        $detectAdapter ??= RuntimeDetector::detect(...);
        $adapter = $detectAdapter($trustedProxies);

        $kernel = new Kernel(
            $app,
            $router,
            isPersistent: $adapter->isPersistent(),
            httpCache: $httpCache,
            discoveredGlobalMiddleware: $globalMiddleware,
            discoveredOpenApiMiddleware: $openApiMiddleware,
            middlewareGroups: $middlewareGroups,
        );

        return new self($app, $kernel, $adapter);
    }
}
