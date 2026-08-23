<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Http\Dispatcher;
use Kinetis\Validation\Hydrator;

/**
 * Everything a normal HTTP request needs: the route table, each route's
 * parameter-binding plan, the validation plan for every DTO reachable
 * from an HTTP route specifically (not every DTO in the app — see
 * Compiler), every #[AsGlobalMiddleware]/#[AsOpenApiMiddleware]-discovered
 * class, and every #[AsMiddlewareGroup]-declared group keyed by name (see
 * Kinetis\Http\Middleware\GlobalMiddlewareDiscovery), all already sorted
 * by priority. Kept separate from the OpenAPI document so a plain API
 * request never has to load verbose JSON-schema-shaped data just to
 * dispatch.
 *
 * @phpstan-import-type HydrationPlan from Hydrator
 * @phpstan-import-type HttpBindingPlan from Dispatcher
 */
final readonly class HttpCache
{
    public function __construct(
        public int $formatVersion,
        /** @var list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}> */
        public array $routes,
        /** @var array<string, list<HttpBindingPlan>> */
        public array $httpBindingPlans,
        /** @var array<string, HydrationPlan> */
        public array $hydrationPlans,
        /** @var list<class-string> */
        public array $globalMiddleware,
        /** @var list<class-string> */
        public array $openApiMiddleware,
        public string $compiledAt,
        /** @var array<string, list<class-string>> */
        public array $middlewareGroups = [],
        /** @var list<class-string> */
        public array $packageBootstraps = [],
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
            'openApiMiddleware' => $this->openApiMiddleware,
            'middlewareGroups' => $this->middlewareGroups,
            'packageBootstraps' => $this->packageBootstraps,
            'compiledAt' => $this->compiledAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}> $routes */
        $routes = $data['routes'];
        /** @var array<string, list<HttpBindingPlan>> $httpBindingPlans */
        $httpBindingPlans = $data['httpBindingPlans'];
        /** @var array<string, HydrationPlan> $hydrationPlans */
        $hydrationPlans = $data['hydrationPlans'];
        /** @var list<class-string> $globalMiddleware */
        $globalMiddleware = $data['globalMiddleware'];
        /** @var list<class-string> $openApiMiddleware */
        $openApiMiddleware = $data['openApiMiddleware'];
        /** @var array<string, list<class-string>> $middlewareGroups */
        $middlewareGroups = $data['middlewareGroups'];
        /** @var list<class-string> $packageBootstraps */
        $packageBootstraps = $data['packageBootstraps'];

        return new self(
            formatVersion: (int) $data['formatVersion'],
            routes: $routes,
            httpBindingPlans: $httpBindingPlans,
            hydrationPlans: $hydrationPlans,
            globalMiddleware: $globalMiddleware,
            openApiMiddleware: $openApiMiddleware,
            compiledAt: (string) $data['compiledAt'],
            middlewareGroups: $middlewareGroups,
            packageBootstraps: $packageBootstraps,
        );
    }
}
