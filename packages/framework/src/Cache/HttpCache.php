<?php

declare(strict_types=1);

namespace Kinetis\Cache;

use Kinetis\Cache\Exception\ArtifactValidation;
use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
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

    private const array TOP_LEVEL_KEYS = [
        'formatVersion', 'routes', 'httpBindingPlans', 'hydrationPlans',
        'globalMiddleware', 'openApiMiddleware', 'middlewareGroups',
        'packageBootstraps', 'compiledAt',
    ];

    private const array ROUTE_ENTRY_KEYS = [
        'httpMethod', 'pathTemplate', 'controllerClass', 'controllerMethod', 'status', 'middleware',
    ];

    private const string ARTIFACT_COMPONENT = 'HttpCache route';

    /**
     * Validates every top-level field's presence, type, and — via
     * `ArtifactValidation::exactKeys()` — that no *extra* field is
     * present either, matching `toArray()`'s own shape exactly rather
     * than merely tolerating a superset of it. Every route entry gets
     * the identical treatment, matching the shape `Router::fromArray()`
     * (next) actually consumes. `httpBindingPlans`/`hydrationPlans`
     * are validated by `Kinetis\Http\Dispatcher::validateBindingPlans()`/
     * `Kinetis\Validation\Hydrator::validatePlans()` — the abstractions
     * that own those plan shapes — rather than re-deriving their own
     * recursive validation rules a second time here.
     *
     * @param array<string, mixed> $data
     * @throws CacheArtifactExceptionInterface
     */
    public static function fromArray(array $data): self
    {
        ArtifactValidation::exactKeys($data, 'HttpCache', self::TOP_LEVEL_KEYS);

        $formatVersion = ArtifactValidation::int($data, 'HttpCache', 'formatVersion');
        $routes = ArtifactValidation::listOfArrays($data, 'HttpCache', 'routes');
        $httpBindingPlans = ArtifactValidation::array($data, 'HttpCache', 'httpBindingPlans');
        $hydrationPlans = ArtifactValidation::array($data, 'HttpCache', 'hydrationPlans');
        $globalMiddleware = ArtifactValidation::listOfStrings($data, 'HttpCache', 'globalMiddleware');
        $openApiMiddleware = ArtifactValidation::listOfStrings($data, 'HttpCache', 'openApiMiddleware');
        $middlewareGroups = ArtifactValidation::mapOfListOfStrings($data, 'HttpCache', 'middlewareGroups');
        $packageBootstraps = ArtifactValidation::listOfStrings($data, 'HttpCache', 'packageBootstraps');
        $compiledAt = ArtifactValidation::string($data, 'HttpCache', 'compiledAt');

        Dispatcher::validateBindingPlans($httpBindingPlans);
        Hydrator::validatePlans($hydrationPlans);

        /** @var list<array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}> $routes */
        $routes = array_map(self::validateRouteEntry(...), $routes);
        /** @var array<string, list<HttpBindingPlan>> $httpBindingPlans */
        /** @var array<string, HydrationPlan> $hydrationPlans */
        /** @var list<class-string> $globalMiddleware */
        /** @var list<class-string> $openApiMiddleware */
        /** @var array<string, list<class-string>> $middlewareGroups */
        /** @var list<class-string> $packageBootstraps */

        return new self(
            formatVersion: $formatVersion,
            routes: $routes,
            httpBindingPlans: $httpBindingPlans,
            hydrationPlans: $hydrationPlans,
            globalMiddleware: $globalMiddleware,
            openApiMiddleware: $openApiMiddleware,
            compiledAt: $compiledAt,
            middlewareGroups: $middlewareGroups,
            packageBootstraps: $packageBootstraps,
        );
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{httpMethod:string,pathTemplate:string,controllerClass:class-string,controllerMethod:string,status:int,middleware:list<string>}
     */
    private static function validateRouteEntry(array $entry): array
    {
        ArtifactValidation::exactKeys($entry, self::ARTIFACT_COMPONENT, self::ROUTE_ENTRY_KEYS);

        $httpMethod = ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'httpMethod');
        $pathTemplate = ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'pathTemplate');
        $controllerClass = ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'controllerClass');
        $controllerMethod = ArtifactValidation::string($entry, self::ARTIFACT_COMPONENT, 'controllerMethod');
        $status = ArtifactValidation::int($entry, self::ARTIFACT_COMPONENT, 'status');
        $middleware = ArtifactValidation::listOfStrings($entry, self::ARTIFACT_COMPONENT, 'middleware');

        /** @var class-string $controllerClass */
        return [
            'httpMethod' => $httpMethod,
            'pathTemplate' => $pathTemplate,
            'controllerClass' => $controllerClass,
            'controllerMethod' => $controllerMethod,
            'status' => $status,
            'middleware' => $middleware,
        ];
    }
}
