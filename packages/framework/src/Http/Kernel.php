<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Cache\HttpCache;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\Exception\UnknownMiddlewareGroupException;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Middleware\GlobalMiddlewareOrder;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Instrumentation\Telemetry;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Routing\Route;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The runtime-agnostic core every Kinetis\Runtime adapter drives. Consumes
 * and returns pure PSR-7 — it never touches a superglobal, an environment
 * variable, or a runtime-specific function.
 *
 * Owns the per-request lifecycle: a fresh RequestScope is created before
 * routing/dispatch and disposed in a `finally` block. `/openapi.json` and
 * `/openapi` are ordinary routes on a discovered controller
 * ({@see \Kinetis\Http\OpenApi\DocumentationController}), not something
 * this class intercepts — all it still owns is the access policy, which
 * folds $exposeOpenApi over OPENAPI_ENVIRONMENTS and is handed to that
 * controller through the request scope.
 * Every request also resolves a `Kinetis\Persistence\TransactionGuard`
 * from its RequestScope, registering `rollbackDangling()` as a dispose
 * hook whenever that class is available — referenced only as a
 * class-name string, `class_exists()`-gated so an application with no
 * database (and no `kinetis/persistence` installed) pays nothing for
 * this.
 *
 * `$isPersistent` — set from the driving RuntimeAdapterInterface — gates
 * a `gc_collect_cycles()` call at the end of `handle()`, forcing cleanup
 * of circular references (including Fibers) between requests in a
 * persistent worker; skipped for a boot-and-die process about to have
 * the OS reclaim everything anyway.
 *
 * Every request runs through a global PSR-15 middleware pipeline:
 * `ExceptionHandlerMiddleware` outermost, then `$app`'s own
 * `AppScope::middleware()` registrations in order, then
 * `$discoveredGlobalMiddleware` (`#[AsGlobalMiddleware]` classes, sorted
 * by priority, minus anything already in `$app`'s explicit list),
 * terminating at `dispatchCore()` — routing and dispatch. A matched
 * route additionally runs its own `#[Middleware]` pipeline (class-level
 * then method-level) around `Dispatcher::dispatch()`, resolved from the
 * request's own RequestScope rather than `AppScope`. `#[AsOpenApiMiddleware]`
 * classes are published as the `openapi` middleware group, which
 * DocumentationController references like any other route middleware.
 *
 * `$httpCache` is the optional, production-only AOT cache (see
 * `Kinetis\Cache`) — null by default, meaning every request behaves
 * exactly as it always has, with live reflection throughout.
 */
final class Kernel
{
    // A plain string, not a `use` import — TransactionGuard lives in the
    // separate kinetis/persistence package; see the class docblock above
    // and RuntimeDetector::BREF_ADAPTER_CLASS for why referencing it this
    // way never triggers autoloading on its own.
    private const TRANSACTION_GUARD_CLASS = 'Kinetis\Persistence\TransactionGuard';

    private readonly OpenApiAccess $openApiAccess;

    /** @var array<string, list<class-string>> */
    private readonly array $groups;

    private readonly RequestHandlerInterface $globalPipeline;

    public function __construct(
        private readonly AppScope $app,
        private readonly Router $router,
        /** true or false decides outright; null defers to OPENAPI_ENVIRONMENTS — see {@see OpenApiAccess}. */
        ?bool $exposeOpenApi = null,
        private readonly bool $isPersistent = false,
        private readonly ?HttpCache $httpCache = null,
        /** @var list<class-string> */
        private readonly array $discoveredGlobalMiddleware = [],
        /** @var list<class-string> */
        private readonly array $discoveredOpenApiMiddleware = [],
        /** @var array<string, list<class-string>> #[AsMiddlewareGroup]-declared groups, each already priority-sorted — see GlobalMiddlewareDiscovery::discoverAll()'s `groups` bucket. */
        private readonly array $middlewareGroups = [],
    ) {
        $this->openApiAccess = match ($exposeOpenApi) {
            true => OpenApiAccess::enabled(),
            false => OpenApiAccess::disabled(),
            null => $app->has(Config::class) && ($config = $app->get(Config::class)) instanceof Config
                ? OpenApiAccess::fromConfig($config)
                // No configuration to consult — a Kernel on a scope that
                // was never booted — so both paths stay closed.
                : OpenApiAccess::disabled(),
        };

        // The built-in `openapi` group: what discovery found, plus this
        // application's own AppScope::openApiMiddleware() registrations,
        // which discovery cannot see. Always defined even when empty —
        // DocumentationController references it unconditionally.
        $this->groups = [
            ...$this->middlewareGroups,
            GlobalMiddlewareDiscovery::OPENAPI_GROUP => GlobalMiddlewareOrder::merge(
                $app->openApiMiddlewares(),
                $this->discoveredOpenApiMiddleware,
            ),
        ];

        $this->assertMiddlewareGroupsExist();

        // Resolved from AppScope, not RequestScope — global middleware
        // wraps the entire request, including before any RequestScope
        // exists.
        $order = GlobalMiddlewareOrder::resolve($this->app->middlewares(), $this->discoveredGlobalMiddleware);
        $globalMiddleware = array_map($this->app->get(...), $order);

        $this->globalPipeline = new MiddlewarePipeline(
            $globalMiddleware,
            new CallableRequestHandler($this->dispatchCore(...)),
        );

    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->globalPipeline->handle($request);
    }

    /**
     * Every `@name` group reference across every registered route is
     * checked once here, at construction, rather than when a request
     * happens to hit the route carrying it — a typo'd group name stops
     * the worker from starting instead of turning into a 500 for whoever
     * hits that one endpoint first.
     */
    private function assertMiddlewareGroupsExist(): void
    {
        foreach ($this->router->routes() as $route) {
            foreach ($route->middleware as $reference) {
                if (!str_starts_with($reference, Middleware::GROUP_PREFIX)) {
                    continue;
                }

                $group = substr($reference, strlen(Middleware::GROUP_PREFIX));

                if (!isset($this->groups[$group])) {
                    throw UnknownMiddlewareGroupException::forRoute(
                        $group,
                        $route->controllerClass,
                        $route->controllerMethod,
                        array_keys($this->groups),
                    );
                }
            }
        }
    }

    /**
     * Expands a route's declared middleware list, replacing each `@name`
     * group reference with that group's own members in place — so a
     * group's position in the running pipeline is exactly where the
     * reference was declared, keeping route middleware's
     * declaration-order rule intact whether an entry is one class or a
     * whole group.
     *
     * @param list<class-string|string> $references
     * @return list<class-string>
     */
    private function expandMiddlewareGroups(array $references): array
    {
        $expanded = [];

        foreach ($references as $reference) {
            if (!str_starts_with($reference, Middleware::GROUP_PREFIX)) {
                /** @var class-string $reference */
                $expanded[] = $reference;

                continue;
            }

            // Guaranteed present by assertMiddlewareGroupsExist().
            $group = substr($reference, strlen(Middleware::GROUP_PREFIX));

            foreach ($this->groups[$group] as $middlewareClass) {
                $expanded[] = $middlewareClass;
            }
        }

        return $expanded;
    }

    private function dispatchCore(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->app->createRequestScope();

        // Kinetis\Http\OpenApi\DocumentationController is discovered and
        // dispatched like any other controller, so what it needs has to
        // be resolvable — and neither of these can come from AppScope:
        // the Router is built after boot() has locked it, and the access
        // policy folds in $exposeOpenApi, which Kernel owns. Registering
        // them here keeps every entry point unchanged.
        $scope->instance(Router::class, $this->router);
        $scope->instance(OpenApiAccess::class, $this->openApiAccess);

        if (class_exists(self::TRANSACTION_GUARD_CLASS)) {
            $transactionGuardClass = self::TRANSACTION_GUARD_CLASS;
            $transactionGuard = $scope->get($transactionGuardClass);
            $scope->onDispose($transactionGuard->rollbackDangling(...));
        }

        try {
            $telemetry = Telemetry::global();
            $matchToken = $telemetry->routeMatchStarted($request->getMethod(), $request->getUri()->getPath());

            try {
                $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());
                $telemetry->routeMatchEnded($matchToken, $match->route->pathTemplate);
            } catch (Throwable $e) {
                $telemetry->routeMatchEnded($matchToken, null);

                throw $e;
            }

            // Same known nullsafe.neverNull false positive as the
            // /openapi.json branch above — $this->httpCache is genuinely
            // nullable here.
            // @phpstan-ignore-next-line nullsafe.neverNull
            $httpBindingPlans = $this->httpCache?->httpBindingPlans ?? [];
            // @phpstan-ignore-next-line nullsafe.neverNull
            $hydrationPlans = $this->httpCache?->hydrationPlans ?? [];
            $dispatcher = new Dispatcher($scope, $httpBindingPlans, $hydrationPlans);

            // Resolved from $scope, not $this->app: unlike the global
            // pipeline's middleware, route-level #[Middleware] is exactly
            // the kind likely to need a per-request dependency (a resolved
            // "current user", TransactionGuard, ...), so it gets the same
            // fresh-per-request container a controller would.
            $routeMiddleware = array_map($scope->get(...), $this->expandMiddlewareGroups($match->route->middleware));
            $routePipeline = new MiddlewarePipeline(
                $routeMiddleware,
                new CallableRequestHandler(
                    static fn (ServerRequestInterface $request): ResponseInterface => $dispatcher->dispatch($match, $request),
                ),
            );

            return $routePipeline->handle($request);
        } catch (RouteNotFoundException $e) {
            return $this->error(404, $e->getMessage());
        } catch (MethodNotAllowedException $e) {
            return $this->error(405, $e->getMessage(), ['Allow' => implode(', ', $e->allowedMethods)]);
        } finally {
            $scope->dispose();

            if ($this->isPersistent) {
                gc_collect_cycles();
            }
        }
    }




    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $message, array $headers = []): ResponseInterface
    {
        return ErrorResponse::create($status, $message, $headers);
    }


}
