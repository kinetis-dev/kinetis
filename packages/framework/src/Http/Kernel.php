<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Cache\HttpCache;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Container\TransactionGuardHook;
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
use Kinetis\Logging\SafeLogger;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\Runtime\StreamableResponseInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The runtime-agnostic core every Kinetis\Runtime adapter drives. Consumes
 * and returns pure PSR-7 — it never touches a superglobal, an environment
 * variable, or a runtime-specific function.
 *
 * Owns the per-request lifecycle: a fresh RequestScope is created before
 * routing/dispatch and disposed once the response is settled — before
 * `handle()` returns for an ordinary buffered response, and for a
 * StreamableResponseInterface on whichever settlement reaches its lease
 * first, see `deferDisposal()` and `settlePendingStream()`.
 * `/openapi.json` and `/openapi` are ordinary routes on a discovered
 * controller ({@see \Kinetis\Http\OpenApi\DocumentationController}), not
 * something this class intercepts — all it still owns is the access
 * policy, which folds $exposeOpenApi over OPENAPI_ENVIRONMENTS and is
 * handed to that controller through the request scope.
 * Every request also runs {@see TransactionGuardHook::registerIfAvailable()}
 * against its RequestScope — the shared hook that registers
 * `Kinetis\Persistence\TransactionGuard::rollbackDangling()` as a dispose
 * callback whenever that optional package is installed, and costs
 * nothing when it is not.
 *
 * `$isPersistent` — set from the driving RuntimeAdapterInterface — gates
 * the `gc_collect_cycles()` call that follows every request-scope
 * disposal, forcing cleanup of circular references (including Fibers)
 * between requests in a persistent worker; skipped for a boot-and-die
 * process about to have the OS reclaim everything anyway. A streamed
 * response's disposal happens after `handle()` has returned, so the flag
 * travels with its {@see StreamScopeLease}.
 *
 * Every request runs through a global PSR-15 middleware pipeline, in the
 * order {@see GlobalMiddlewareOrder::resolve()} computes from `$app`'s
 * own `AppScope::middleware()` registrations and
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
 *
 * `$pendingStream` is the one piece of mutable state on this class: the
 * lease for the streamed response this Kernel handed back most recently,
 * held only until that stream is settled — emitted, abandoned, displaced
 * from the response leaving the global pipeline, or found still pending
 * by the next request. A Kernel belongs to one worker thread —
 * `bootstrap.php` runs per thread — so this is per-thread state, not
 * shared.
 */
final class Kernel
{
    private readonly OpenApiAccess $openApiAccess;

    /** @var array<string, list<class-string>> */
    private readonly array $groups;

    private readonly RequestHandlerInterface $globalPipeline;

    private ?StreamScopeLease $pendingStream = null;

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
        $this->releaseUnsettledStream();

        try {
            $response = $this->globalPipeline->handle($request);
        } catch (Throwable $e) {
            $this->settlePendingStream(null);

            throw $e;
        }

        $this->settlePendingStream($response);

        return $response;
    }

    /**
     * Releases the lease this request opened, unless $final is still the
     * wrapper carrying it.
     *
     * Global middleware takes delivery of whatever `dispatchCore()`
     * produced and is under no obligation to hand it back: it can answer
     * with a buffered response, with a stream of its own, or with an
     * exception — $final is null for the last of those. In every one of
     * them the wrapper has left the response chain and nothing
     * downstream will ever emit or abandon it, so its scope is released
     * here, while the request that opened it is still on the stack. A
     * `with*` clone is the one response that is not a replacement: it
     * carries the same lease, so settling it stays the adapter's to do.
     * A response built *around* the wrapper carries no lease and is
     * settled here like any other.
     */
    private function settlePendingStream(?ResponseInterface $final): void
    {
        $lease = $this->pendingStream;

        if ($lease === null || ($final instanceof StreamedResponse && $final->carries($lease))) {
            return;
        }

        $this->pendingStream = null;
        $lease->release();
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

        TransactionGuardHook::registerIfAvailable($scope);

        try {
            $response = $this->matchAndDispatch($scope, $request);
        } catch (Throwable $e) {
            // A route/controller failure is already the outcome — see
            // disposeScope()'s own docblock for why a cleanup failure
            // must never replace it.
            $this->disposeScope($scope, $request, $e);

            throw $e;
        }

        // A streamed response's body has not been written yet, and the
        // code that writes it runs on this scope.
        if ($response instanceof StreamableResponseInterface) {
            return $this->deferDisposal($scope, $request, $response);
        }

        // The handler succeeded, but $response has not left this process
        // yet — disposeScope() may still legitimately turn this into the
        // generic 500 every other uncaught exception produces, see its
        // own docblock.
        $this->disposeScope($scope, $request, null);

        return $response;
    }

    /**
     * Keeps $scope alive past dispatch, and returns a StreamedResponse
     * that releases it when its body is emitted or the response is
     * settled without one.
     *
     * A streamed body is written after `handle()` has returned, by an
     * adapter, against code that resolves from this request's own
     * container — so disposing before returning would tear the scope out
     * from under the emitter. Ownership of the release sits in one
     * {@see StreamScopeLease}, which the wrapper holds and every `with*`
     * clone of it carries, so a header or status edit after dispatch
     * hands on a response that still owns the scope — and one this class
     * still recognizes as its own when the pipeline returns.
     *
     * Status, headers, protocol version and reason phrase all come from
     * $response, which the wrapper composes unchanged.
     */
    private function deferDisposal(
        RequestScope $scope,
        ServerRequestInterface $request,
        ResponseInterface&StreamableResponseInterface $response,
    ): ResponseInterface {
        $lease = new StreamScopeLease(
            $this->app,
            $scope,
            $request->getMethod(),
            $request->getUri()->getPath(),
            $this->isPersistent,
        );

        $this->pendingStream = $lease;

        return new StreamedResponse($response, $response->getEmitter(), $lease);
    }

    /**
     * The defensive path, for a streamed response an owner neither
     * emitted nor abandoned.
     *
     * The wrapper is returned to the caller, so anything up the stack can
     * hold the last reference to it past the request: a direct caller
     * that reads its status and drops it, an exception trace pinning it
     * as a frame argument, an adapter that refuses a stream it cannot
     * emit without abandoning it first. Every one of those leaves a live
     * RequestScope from a finished request, which is exactly what must
     * not reach the next one in a persistent worker. Kernel is the only
     * owner that knows the next request has started, so it releases at
     * the top of `handle()` — ahead of the global pipeline, which can
     * answer a request outright (a CORS preflight, a rejected body, a
     * rate limit) without ever reaching `dispatchCore()`. The warning
     * names the request whose scope was carried this far.
     */
    private function releaseUnsettledStream(): void
    {
        $lease = $this->pendingStream;
        $this->pendingStream = null;

        if ($lease === null || $lease->isReleased()) {
            return;
        }

        SafeLogger::logFrom(
            fn (): LoggerInterface => $this->app->get(LoggerInterface::class),
            LogLevel::WARNING,
            'Streamed response for {method} {path} was neither emitted nor abandoned; releasing its request scope.',
            [
                'method' => $lease->method,
                'path' => $lease->path,
            ],
        );

        $lease->release();
    }

    private function matchAndDispatch(RequestScope $scope, ServerRequestInterface $request): ResponseInterface
    {
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
        }
    }

    /**
     * Disposes $scope without letting a cleanup failure silently replace
     * or suppress the real outcome dispatchCore() already has in hand.
     * A successful streamed response never reaches here — its scope
     * outlives dispatch, see deferDisposal().
     *
     * $primaryFailure is the route/controller Throwable already
     * propagating, or null on the success path. PHP's own `finally`
     * semantics would otherwise let a Throwable raised while disposing
     * silently replace whichever one is currently in flight — exactly
     * the defect this method exists to close: a declared
     * HttpStatusExceptionInterface's real status, or a generic failure's
     * real diagnostic, must never become a misleading "cleanup failed"
     * 500 instead.
     *
     * - $primaryFailure !== null: dispose()'s own failure is logged here,
     *   separately, through AppScope's own LoggerInterface — $scope is
     *   already disposed by the time SafeLogger's own catch could run,
     *   so it can no longer resolve one safely — and then discarded.
     *   $primaryFailure itself is left completely untouched for the
     *   caller to rethrow.
     * - $primaryFailure === null: nothing has gone out to the client yet,
     *   so it's safe (and correct, since something in cleanup is
     *   genuinely broken) to let dispose()'s own failure propagate
     *   normally — it reaches ExceptionHandlerMiddleware exactly like
     *   any other uncaught exception, logged exactly once there, never
     *   here too.
     */
    private function disposeScope(RequestScope $scope, ServerRequestInterface $request, ?Throwable $primaryFailure): void
    {
        try {
            try {
                $scope->dispose();
            } catch (Throwable $disposeFailure) {
                if ($primaryFailure === null) {
                    throw $disposeFailure;
                }

                // logFrom(), not log(): SafeLogger::log($this->app->get(...), ...)
                // would evaluate that get() call before log() is ever
                // entered, so a throwing LoggerInterface binding/factory
                // would escape uncaught right here and replace
                // $primaryFailure anyway — exactly the defect this method
                // exists to close, just moved one level up. Passing the
                // resolution itself as a callable keeps it inside the
                // same containment as the logger's own log() call.
                SafeLogger::logFrom(
                    fn (): LoggerInterface => $this->app->get(LoggerInterface::class),
                    LogLevel::ERROR,
                    'Request scope disposal failed while handling {method} {path}, after {originalClass} was already the outcome: {message}',
                    [
                        'method' => $request->getMethod(),
                        'path' => (string) $request->getUri()->getPath(),
                        'originalClass' => $primaryFailure::class,
                        'message' => $disposeFailure->getMessage(),
                        'exception' => $disposeFailure,
                    ],
                );
            }
        } finally {
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
