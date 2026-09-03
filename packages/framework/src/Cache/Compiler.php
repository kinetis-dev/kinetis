<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Console\CommandDiscovery;
use Kinetis\Console\CommandRegistry;
use Kinetis\Events\EventListenerDiscovery;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Dispatcher;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\Validation\Hydrator;
use DateTimeImmutable;
use ReflectionMethod;

/**
 * Walks every registered route/command, derives their parameter-binding
 * plans, discovers every DTO class reachable through them, compiles a
 * validation plan for each, and carries the already-sorted list of
 * #[AsGlobalMiddleware]-discovered classes and #[Listener]-discovered
 * event listeners — producing four sections (HttpCache/CommandCache/
 * EventCache/PluginCache, grouped for convenience as one CompiledCache)
 * with zero live objects/closures inside any of them. PluginCache is
 * populated by PluginDiscovery, which compiles whatever installed
 * packages declare as their own CacheableDiscoveryInterface class —
 * kinetis/mcp's McpRegistry (tool/resource definitions) among them,
 * when that package is installed. CacheStore publishes all four
 * together as one atomic generation — see its own docblock — so
 * "independent" describes how each is *read* (a given entry point only
 * ever loads the sections it needs — an HTTP boot loads
 * http/events/plugins, the CLI loads commands/events/plugins), not how
 * they're published.
 *
 * DTO discovery here is HTTP-only — every #[Body]-bound DTO class
 * reachable from a registered route. kinetis/mcp's own tool/resource
 * parameter schemas are built entirely inside that package
 * (McpDiscovery/JsonSchema, not Hydrator::compilePlan()) and reach
 * PluginCache through the PluginDiscovery mechanism described above,
 * never through this discovery pass.
 *
 * Discovery itself only ever walks the top-level #[Body]-bound DTO
 * class — it does *not* recurse into a DTO's own nested-DTO constructor
 * parameters to find more classes to compile plans for, even though
 * Hydrator now supports nested-DTO hydration. That's not a gap:
 * Hydrator::compilePlan() is itself recursive (see its own doc comment)
 * and embeds every nested class's plan inline as `nestedPlan`, so
 * compiling a plan for just the top-level class already produces a
 * fully nested-inclusive result — there's no independent lookup
 * anywhere by a nested DTO's class name for this discovery pass to feed.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-import-type DiscoveredMiddleware from GlobalMiddlewareDiscovery
 */
final class Compiler
{
    /**
     * $middleware is GlobalMiddlewareDiscovery::discoverAll()'s own return
     * shape, passed through whole rather than as one parameter per bucket —
     * every entry is expected already sorted, since compile() reflects no
     * middleware attribute and applies no ordering of its own. Any missing
     * key is treated as empty.
     *
     * @param DiscoveredMiddleware $middleware
     * @param list<class-string> $packageBootstraps
     * @param array<class-string, array<array-key, mixed>> $pluginData already-compiled, keyed by the CacheableDiscoveryInterface class that produced it — see PluginDiscovery::discover()
     */
    public function compile(
        Router $router,
        ?CommandRegistry $commands = null,
        ?EventListenerRegistry $listeners = null,
        array $middleware = [],
        array $packageBootstraps = [],
        array $pluginData = [],
    ): CompiledCache {
        $compiledAt = (new DateTimeImmutable())->format(DATE_ATOM);

        $httpBindingPlans = [];
        $httpDtoClasses = [];

        foreach ($router->routes() as $route) {
            $method = new ReflectionMethod($route->controllerClass, $route->controllerMethod);
            $plan = Dispatcher::derivePlan($method, $route);
            $httpBindingPlans["{$route->controllerClass}::{$route->controllerMethod}"] = $plan;

            foreach ($plan as $param) {
                if ($param['source'] === 'body' && $param['dtoClass'] !== null) {
                    $httpDtoClasses[$param['dtoClass']] = true;
                }
            }
        }

        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: $router->toArray(),
            httpBindingPlans: $httpBindingPlans,
            hydrationPlans: $this->hydrationPlansFor($httpDtoClasses),
            globalMiddleware: $middleware['global'] ?? [],
            openApiMiddleware: $middleware['openApi'] ?? [],
            compiledAt: $compiledAt,
            middlewareGroups: $middleware['groups'] ?? [],
            packageBootstraps: $packageBootstraps,
        );

        $commandsCache = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: ($commands ?? new CommandRegistry())->toArray(),
            compiledAt: $compiledAt,
            packageBootstraps: $packageBootstraps,
        );

        $eventsCache = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: ($listeners ?? new EventListenerRegistry())->toArray(),
            compiledAt: $compiledAt,
        );

        $pluginsCache = new PluginCache(
            formatVersion: CacheFormat::VERSION,
            data: $pluginData,
            compiledAt: $compiledAt,
        );

        return new CompiledCache($http, $commandsCache, $eventsCache, $pluginsCache);
    }

    /**
     * @param array<string, true> $dtoClasses
     * @return array<string, HydrationPlan>
     */
    private function hydrationPlansFor(array $dtoClasses): array
    {
        $hydrationPlans = [];

        foreach (array_keys($dtoClasses) as $dtoClass) {
            /** @var class-string $dtoClass */
            $hydrationPlans[$dtoClass] = Hydrator::compilePlan($dtoClass);
        }

        return $hydrationPlans;
    }

    /**
     * The one entry point every lazy-first-run/build path calls — "compile
     * everything" doesn't exist twice, they all just call this. Routes,
     * commands, #[AsGlobalMiddleware]/#[AsOpenApiMiddleware]/
     * #[AsMiddlewareGroup]-attributed classes, and #[Listener]-attributed
     * methods are all discovered by namespace — see
     * RouteDiscovery/CommandDiscovery/GlobalMiddlewareDiscovery/EventListenerDiscovery.
     * GlobalMiddlewareDiscovery::discoverAll() performs exactly one scan
     * for all three middleware attributes rather than three, and its
     * result is handed to compile() whole rather than destructured per
     * bucket. kinetis/mcp is one example of the pluggable mechanism
     * below, not a special case this method knows anything about: its
     * McpRegistry declares itself as that package's own
     * CacheableDiscoveryInterface class, so PluginDiscovery::discover()
     * compiles its tool/resource definitions into PluginCache here too.
     * Any package declaring its own CacheableDiscoveryInterface class
     * (see PackageDiscovery::discoveryClasses()) is compiled the same
     * way — that's the whole point of the mechanism. Only with no cache
     * published yet does anything discover live instead — see
     * PluginDiscovery::bind()'s own docblock.
     */
    public function compileProject(string $projectRoot): CompiledCache
    {
        // Discovered first, not alongside the others: RouteDiscovery needs
        // the global middleware list itself, to resolve any #[RoutePrefix]
        // those classes declare into every route's own compiled path — see
        // Router::register()'s own doc comment.
        $middleware = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
        $router = RouteDiscovery::discover($projectRoot, globalMiddleware: $middleware['global']);
        $commands = CommandDiscovery::discover($projectRoot);
        $listeners = EventListenerDiscovery::discover($projectRoot);
        $packageBootstraps = PackageDiscovery::bootstrapClasses($projectRoot);
        $pluginData = PluginDiscovery::discover($projectRoot);

        return $this->compile($router, $commands, $listeners, $middleware, $packageBootstraps, $pluginData);
    }
}
