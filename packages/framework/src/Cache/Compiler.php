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
use Kinetis\Mcp\McpDiscovery;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\Validation\Hydrator;
use DateTimeImmutable;
use ReflectionMethod;

/**
 * Walks every registered route/tool/resource/command, derives their
 * parameter-binding plans, discovers every DTO class reachable through
 * them, compiles a validation plan for each, generates the OpenAPI
 * document once, and carries the already-sorted list of
 * #[AsGlobalMiddleware]-discovered classes and #[Listener]-discovered
 * event listeners — producing five independent artifacts
 * (HttpCache/McpCache/OpenApiCache/CommandCache/EventCache, grouped for
 * convenience as one CompiledCache) with zero live objects/closures
 * inside any of them.
 *
 * DTO discovery is tracked separately for HTTP and MCP (rather than one
 * shared bag) precisely so HttpCache/McpCache stay self-contained — a DTO
 * reachable from both a route and a tool ends up in both caches (harmless
 * duplication), never forcing one side to depend on the other's discovery
 * pass.
 *
 * Discovery itself only ever walks the top-level #[Body]-bound DTO class
 * and each non-builtin MCP tool parameter's class — it does *not* recurse
 * into a DTO's own nested-DTO constructor parameters to find more classes
 * to compile plans for, even though Hydrator now supports nested-DTO
 * hydration. That's not a gap: Hydrator::compilePlan() is itself recursive
 * (see its own doc comment) and embeds every nested class's plan inline as
 * `nestedPlan`, so compiling a plan for just the top-level class already
 * produces a fully nested-inclusive result — there's no independent lookup
 * anywhere by a nested DTO's class name for this discovery pass to feed.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 */
final class Compiler
{
    /**
     * @param list<class-string> $globalMiddleware Already sorted, e.g. by
     *     GlobalMiddlewareDiscovery::discoverAll()['global'] — compile()
     *     itself doesn't reflect #[AsGlobalMiddleware]/#[AsMcpMiddleware]/
     *     #[AsOpenApiMiddleware] or apply any ordering.
     * @param list<class-string> $mcpMiddleware Already sorted, e.g. by
     *     GlobalMiddlewareDiscovery::discoverAll()['mcp'].
     * @param list<class-string> $openApiMiddleware Already sorted, e.g. by
     *     GlobalMiddlewareDiscovery::discoverAll()['openApi'].
     */
    public function compile(
        Router $router,
        McpRegistry $registry,
        ?CommandRegistry $commands = null,
        array $globalMiddleware = [],
        ?EventListenerRegistry $listeners = null,
        array $mcpMiddleware = [],
        array $openApiMiddleware = [],
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

        $mcpBindingPlans = [];
        $mcpDtoClasses = [];

        foreach ([...$registry->tools(), ...$registry->resources()] as $definition) {
            $method = new ReflectionMethod($definition->controllerClass, $definition->controllerMethod);
            $plan = McpDispatcher::derivePlan($method);
            $mcpBindingPlans["{$definition->controllerClass}::{$definition->controllerMethod}"] = $plan;

            foreach ($plan as $param) {
                if ($param['dtoClass'] !== null) {
                    $mcpDtoClasses[$param['dtoClass']] = true;
                }
            }
        }

        $http = new HttpCache(
            formatVersion: CacheFormat::VERSION,
            routes: $router->toArray(),
            httpBindingPlans: $httpBindingPlans,
            hydrationPlans: $this->hydrationPlansFor($httpDtoClasses),
            globalMiddleware: $globalMiddleware,
            mcpMiddleware: $mcpMiddleware,
            openApiMiddleware: $openApiMiddleware,
            compiledAt: $compiledAt,
        );

        $mcpToArray = $registry->toArray();

        $mcp = new McpCache(
            formatVersion: CacheFormat::VERSION,
            mcpTools: $mcpToArray['tools'],
            mcpResources: $mcpToArray['resources'],
            mcpBindingPlans: $mcpBindingPlans,
            hydrationPlans: $this->hydrationPlansFor($mcpDtoClasses),
            compiledAt: $compiledAt,
        );

        $openApi = new OpenApiCache(
            formatVersion: CacheFormat::VERSION,
            openApi: (new OpenApiGenerator($router))->generate(),
            compiledAt: $compiledAt,
        );

        $commandsCache = new CommandCache(
            formatVersion: CacheFormat::VERSION,
            commands: ($commands ?? new CommandRegistry())->toArray(),
            compiledAt: $compiledAt,
        );

        $eventsCache = new EventCache(
            formatVersion: CacheFormat::VERSION,
            listeners: ($listeners ?? new EventListenerRegistry())->toArray(),
            compiledAt: $compiledAt,
        );

        return new CompiledCache($http, $mcp, $openApi, $commandsCache, $eventsCache);
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
     * MCP tools/resources, commands, #[AsGlobalMiddleware]/
     * #[AsMcpMiddleware]/#[AsOpenApiMiddleware]-attributed classes, and
     * #[Listener]-attributed methods are all discovered by namespace — see
     * RouteDiscovery/McpDiscovery/CommandDiscovery/GlobalMiddlewareDiscovery/EventListenerDiscovery.
     * GlobalMiddlewareDiscovery::discoverAll() performs exactly one scan
     * for all three middleware attributes rather than three.
     */
    public function compileProject(string $projectRoot): CompiledCache
    {
        $router = RouteDiscovery::discover($projectRoot);
        $registry = McpDiscovery::discover($projectRoot);
        $commands = CommandDiscovery::discover($projectRoot);
        $middleware = GlobalMiddlewareDiscovery::discoverAll($projectRoot);
        $listeners = EventListenerDiscovery::discover($projectRoot);

        return $this->compile(
            $router,
            $registry,
            $commands,
            $middleware['global'],
            $listeners,
            $middleware['mcp'],
            $middleware['openApi'],
        );
    }
}
