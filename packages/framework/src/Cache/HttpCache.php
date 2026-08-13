<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Http\Dispatcher;
use Kinetis\Validation\Hydrator;

/**
 * Everything a normal HTTP request needs: the route table, each route's
 * parameter-binding plan, the validation plan for every DTO reachable
 * from an HTTP route specifically (not every DTO in the app — see
 * Compiler), every #[AsGlobalMiddleware]/#[AsMcpMiddleware]/
 * #[AsOpenApiMiddleware]-discovered class, and every
 * #[AsMiddlewareGroup]-declared group keyed by name (see
 * Kinetis\Http\Middleware\GlobalMiddlewareDiscovery), all already sorted
 * by priority. Kept separate from McpCache/OpenApiCache so a plain API
 * request never has to load MCP tool definitions or the OpenAPI document
 * just to dispatch — those are unrelated concerns with their own,
 * independent access patterns and their own bloat (verbose JSON-schema-
 * shaped data) that a hot HTTP path shouldn't pay for.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-import-type HttpBindingPlan from Dispatcher
 */
final readonly class HttpCache
{
    public function __construct(
        public int $formatVersion,
        /** @var list<array{httpMethod:string,pathTemplate:string,controllerClass:string,controllerMethod:string,status:int,middleware:list<string>}> */
        public array $routes,
        /** @var array<string, list<HttpBindingPlan>> */
        public array $httpBindingPlans,
        /** @var array<string, HydrationPlan> */
        public array $hydrationPlans,
        /** @var list<class-string> */
        public array $globalMiddleware,
        /** @var list<class-string> */
        public array $mcpMiddleware,
        /** @var list<class-string> */
        public array $openApiMiddleware,
        public string $compiledAt,
        /** @var array<string, list<class-string>> */
        public array $middlewareGroups = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'routes' => $this->routes,
            'httpBindingPlans' => $this->httpBindingPlans,
            'hydrationPlans' => $this->hydrationPlans,
            'globalMiddleware' => $this->globalMiddleware,
            'mcpMiddleware' => $this->mcpMiddleware,
            'openApiMiddleware' => $this->openApiMiddleware,
            'middlewareGroups' => $this->middlewareGroups,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{httpMethod:string,pathTemplate:string,controllerClass:string,controllerMethod:string,status:int,middleware:list<string>}> $routes */
        $routes = $data['routes'];
        /** @var array<string, list<HttpBindingPlan>> $httpBindingPlans */
        $httpBindingPlans = $data['httpBindingPlans'];
        /** @var array<string, HydrationPlan> $hydrationPlans */
        $hydrationPlans = $data['hydrationPlans'];
        /** @var list<class-string> $globalMiddleware */
        $globalMiddleware = $data['globalMiddleware'];
        /** @var list<class-string> $mcpMiddleware */
        $mcpMiddleware = $data['mcpMiddleware'];
        /** @var list<class-string> $openApiMiddleware */
        $openApiMiddleware = $data['openApiMiddleware'];
        /** @var array<string, list<class-string>> $middlewareGroups */
        $middlewareGroups = $data['middlewareGroups'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            routes: $routes,
            httpBindingPlans: $httpBindingPlans,
            hydrationPlans: $hydrationPlans,
            globalMiddleware: $globalMiddleware,
            mcpMiddleware: $mcpMiddleware,
            openApiMiddleware: $openApiMiddleware,
            compiledAt: (string) $data['compiledAt'],
            middlewareGroups: $middlewareGroups,
        );
    }
}
