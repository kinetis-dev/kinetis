<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\HttpCache;
use Kinetis\Container\AppScope;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\Exception\UnknownMiddlewareGroupException;
use Kinetis\Http\Middleware\GlobalMiddlewareOrder;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\McpServer;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\OpenApi\SwaggerUiPage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The runtime-agnostic core every Kinetis\Runtime adapter drives. Consumes
 * and returns pure PSR-7 — it never touches a superglobal, an environment
 * variable, or a runtime-specific function.
 *
 * Owns the per-request lifecycle: a fresh RequestScope is created before
 * routing/dispatch and disposed in a `finally` block. `/openapi.json` and
 * `/docs` are served directly ahead of that, since they read the Router's
 * registered routes rather than application state ($exposeOpenApi turns
 * both off). `$mcp` (opt-in, null by default) exposes `/mcp` implementing
 * MCP's Streamable HTTP transport across both eras McpServer supports —
 * see McpServer::isModernRequest()/Kernel::headerMismatch()/mcpHttpStatus()
 * for the per-era differences, and wantsProgressStream()/
 * handleMcpStreaming() for the `text/event-stream` path a `tools/call`
 * carrying `_meta.progressToken` gets instead of a buffered JSON body.
 *
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
 * terminating at `dispatchCore()` — the OpenAPI/MCP short-circuits,
 * routing, dispatch. A matched route additionally runs its own
 * `#[Middleware]` pipeline (class-level then method-level) around
 * `Dispatcher::dispatch()`, resolved from the request's own RequestScope
 * rather than `AppScope`.
 *
 * `/mcp` and `/openapi.json`/`/docs` each additionally run a narrower
 * pipeline (`$mcpPipeline`/`$openApiPipeline`, from
 * `AppScope::mcpMiddleware()`/`openApiMiddleware()` plus
 * `$discoveredMcpMiddleware`/`$discoveredOpenApiMiddleware` —
 * `#[AsMcpMiddleware]`/`#[AsOpenApiMiddleware]` classes) *inside* the
 * global pipeline, for middleware scoped to just one of these endpoints
 * rather than every route. `$mcpAllowedOrigins` gates `/mcp`
 * specifically: a request carrying an `Origin` header not in this list
 * is rejected with `403` before anything else runs, per MCP's
 * DNS-rebinding-prevention requirement — a request with no `Origin` at
 * all is unaffected.
 *
 * `$httpCache`/`$cacheStore` are the optional, production-only AOT cache
 * (see `Kinetis\Cache`) — both null by default, meaning every request
 * behaves exactly as it always has (live reflection throughout). Split
 * rather than one object: `$httpCache` is loaded eagerly by the caller
 * since nearly every request needs some slice of it; `$cacheStore` is
 * only touched lazily, inside the `/openapi.json` branch, since that
 * document is often the bulkiest cached artifact and the least
 * frequently requested.
 */
final class Kernel
{
    // A plain string, not a `use` import — TransactionGuard lives in the
    // separate kinetis/persistence package; see the class docblock above
    // and RuntimeDetector::BREF_ADAPTER_CLASS for why referencing it this
    // way never triggers autoloading on its own.
    private const TRANSACTION_GUARD_CLASS = 'Kinetis\Persistence\TransactionGuard';

    private readonly OpenApiGenerator $openApi;

    private readonly RequestHandlerInterface $globalPipeline;

    private readonly RequestHandlerInterface $mcpPipeline;

    private readonly RequestHandlerInterface $openApiPipeline;

    public function __construct(
        private readonly AppScope $app,
        private readonly Router $router,
        private readonly bool $exposeOpenApi = true,
        private readonly ?McpServer $mcp = null,
        private readonly bool $isPersistent = false,
        private readonly ?HttpCache $httpCache = null,
        private readonly ?CacheStore $cacheStore = null,
        /** @var list<class-string> */
        private readonly array $discoveredGlobalMiddleware = [],
        /** @var list<class-string> */
        private readonly array $discoveredMcpMiddleware = [],
        /** @var list<class-string> */
        private readonly array $discoveredOpenApiMiddleware = [],
        /** @var list<string> exact Origin values allowed to reach /mcp; empty means "reject any request that sends an Origin header at all" — a request with no Origin (a non-browser client) is unaffected either way. */
        private readonly array $mcpAllowedOrigins = [],
        /** @var array<string, list<class-string>> #[AsMiddlewareGroup]-declared groups, each already priority-sorted — see GlobalMiddlewareDiscovery::discoverAll()'s `groups` bucket. */
        private readonly array $middlewareGroups = [],
    ) {
        $this->openApi = new OpenApiGenerator($router);

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

        // Both scoped pipelines below are always built, even when $mcp is
        // null or $exposeOpenApi is false — cheap when unconfigured (an
        // empty middleware list resolves to nothing), and the alternative
        // (deferring construction) would need the same lazy-loading
        // machinery $cacheStore already has for a genuinely bulkier
        // resource, for no real benefit here.
        $mcpOrder = GlobalMiddlewareOrder::merge($this->app->mcpMiddlewares(), $this->discoveredMcpMiddleware);
        $this->mcpPipeline = new MiddlewarePipeline(
            array_map($this->app->get(...), $mcpOrder),
            new CallableRequestHandler($this->serveMcp(...)),
        );

        $openApiOrder = GlobalMiddlewareOrder::merge($this->app->openApiMiddlewares(), $this->discoveredOpenApiMiddleware);
        $this->openApiPipeline = new MiddlewarePipeline(
            array_map($this->app->get(...), $openApiOrder),
            new CallableRequestHandler($this->serveOpenApi(...)),
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

                if (!isset($this->middlewareGroups[$group])) {
                    throw UnknownMiddlewareGroupException::forRoute(
                        $group,
                        $route->controllerClass,
                        $route->controllerMethod,
                        array_keys($this->middlewareGroups),
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

            foreach ($this->middlewareGroups[$group] as $middlewareClass) {
                $expanded[] = $middlewareClass;
            }
        }

        return $expanded;
    }

    private function dispatchCore(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($this->exposeOpenApi && ($path === '/openapi.json' || $path === '/docs')) {
            return $this->openApiPipeline->handle($request);
        }

        // POST/GET/DELETE are all handled for /mcp (see serveMcp()) — any
        // other method falls through to normal routing. GET and DELETE
        // both answer 405 (see serveMcp()): checked directly against the
        // real 2026-07-28 spec text, which states plainly that a server
        // implementing only this revision "SHOULD respond as follows"
        // to old-transport traffic — "HTTP GET or DELETE to the MCP
        // endpoint: respond with 405 Method Not Allowed." A prior version
        // of this comment described DELETE /mcp as falling through to a
        // 404 like any other unmatched route instead — that was never
        // checked against the spec text directly and turned out to be
        // wrong once it was.
        if ($this->mcp !== null && $path === '/mcp' && in_array($request->getMethod(), ['POST', 'GET', 'DELETE'], true)) {
            return $this->mcpPipeline->handle($request);
        }

        $scope = $this->app->createRequestScope();

        if (class_exists(self::TRANSACTION_GUARD_CLASS)) {
            $transactionGuardClass = self::TRANSACTION_GUARD_CLASS;
            $transactionGuard = $scope->get($transactionGuardClass);
            $scope->onDispose($transactionGuard->rollbackDangling(...));
        }

        try {
            $match = $this->router->match($request->getMethod(), $request->getUri()->getPath());

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
     * The terminal handler at the end of $openApiPipeline — the
     * /openapi.json and /docs logic lives here, rather than directly in
     * dispatchCore(), so #[AsOpenApiMiddleware]/
     * AppScope::openApiMiddleware() can wrap it.
     */
    private function serveOpenApi(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/openapi.json') {
            // $this->cacheStore is genuinely nullable (null by default);
            // PHPStan's flow analysis misreports the nullsafe as
            // redundant here — the same known quirk documented on
            // AppScope::resolve()/RequestScope::resolve(), reproduced in
            // isolation with a minimal nullable-readonly-promoted-
            // property repro before suppressing. Deliberately lazy: this
            // is the only place openapi.php is ever read from disk, so a
            // request for any other path never touches it.
            // @phpstan-ignore-next-line nullsafe.neverNull
            $openApi = $this->cacheStore?->loadOpenApi()?->openApi ?? $this->openApi->generate();

            return $this->json($openApi, 200);
        }

        return $this->html(SwaggerUiPage::render());
    }

    /**
     * The terminal handler at the end of $mcpPipeline. Validates the
     * `Origin` header first — the MCP Streamable HTTP specification
     * requires servers to validate `Origin` on every incoming connection
     * to prevent DNS-rebinding attacks — before any of the existing
     * POST/GET/DELETE handling runs. A request with no `Origin` header at all
     * (a CLI client, a server-to-server call — neither sends one) is
     * unaffected regardless of $mcpAllowedOrigins, since DNS rebinding is
     * specifically a browser attack that always carries an Origin.
     */
    private function serveMcp(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin !== '' && !in_array($origin, $this->mcpAllowedOrigins, true)) {
            return $this->error(403, "Origin \"{$origin}\" is not allowed to access this MCP endpoint.");
        }

        /** @var McpServer $mcp */
        $mcp = $this->mcp;

        if ($request->getMethod() === 'POST') {
            return $this->handleMcp($request, $mcp);
        }

        // Only POST/GET/DELETE ever reach this handler (see
        // dispatchCore()), so "not POST" here always means GET or DELETE.
        // Legacy (2025-03-26 through 2025-11-25) Streamable HTTP allowed a
        // client GET to open a server-initiated stream and a DELETE to
        // terminate a session; the modern (2026-07-28) revision removes
        // both — its own transport spec states plainly that a server
        // implementing only this revision "SHOULD" answer 405 to either
        // one. Kinetis never offered the GET stream even under the
        // legacy era, so this was already correct for GET; DELETE is
        // handled the same way now for the identical reason.
        return $this->error(
            405,
            'This MCP endpoint does not support server-initiated streams or session termination.',
            ['Allow' => 'POST'],
        );
    }

    private function handleMcp(ServerRequestInterface $request, McpServer $mcp): ResponseInterface
    {
        $decoded = json_decode((string) $request->getBody(), associative: true);

        if (!is_array($decoded)) {
            return $this->json(
                ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error.']],
                200,
            );
        }

        /** @var array<string, mixed> $decoded */
        $isModern = McpServer::isModernRequest($decoded);

        if ($isModern) {
            $mismatch = $this->headerMismatch($request, $decoded);

            if ($mismatch !== null) {
                return $this->json(
                    ['jsonrpc' => '2.0', 'id' => $decoded['id'] ?? null, 'error' => ['code' => -32020, 'message' => $mismatch]],
                    400,
                );
            }
        }

        if ($this->wantsProgressStream($decoded)) {
            return $this->handleMcpStreaming($mcp, $decoded);
        }

        $response = $mcp->handle($decoded);

        // Spec: a POST body containing only notifications/responses gets
        // 202 Accepted with no body once the server has accepted it. Not
        // reachable in practice for a modern request — the 2026-07-28
        // revision defines no client-to-server notifications over
        // Streamable HTTP — but harmless to leave in place for either era.
        if ($response === null) {
            return new Response(202);
        }

        return $this->json($response, $isModern ? $this->mcpHttpStatus($response) : 200);
    }

    /**
     * `progressToken` is a spec-general reserved `_meta` key (see
     * McpServer::callTool()) — not restricted to the 2026-07-28 per-request
     * model — so this applies to legacy and modern `tools/call` requests
     * alike, with no era-gating needed.
     *
     * @param array<string, mixed> $decoded
     */
    private function wantsProgressStream(array $decoded): bool
    {
        if (($decoded['method'] ?? null) !== 'tools/call') {
            return false;
        }

        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        return array_key_exists('progressToken', $meta);
    }

    /**
     * Answers with an SSE response scoped to this one request: zero or more
     * `notifications/progress` events (each written the instant the tool
     * method calls ProgressReporter::report(), via $onNotification), then
     * one final event carrying the JSON-RPC response. HTTP status is always
     * 200 here regardless of the JSON-RPC-level outcome — headers are sent
     * before the body starts streaming, so there's no later point to change
     * it; any JSON-RPC error surfaces inside the final event's payload
     * instead, same as how the legacy (non-modern) path already always
     * returns 200.
     *
     * @param array<string, mixed> $decoded
     */
    private function handleMcpStreaming(McpServer $mcp, array $decoded): ResponseInterface
    {
        $inner = new Response(200, [
            'Content-Type' => 'text/event-stream',
            'X-Accel-Buffering' => 'no',
        ]);

        $emitter = static function () use ($mcp, $decoded): void {
            $write = static function (array $payload): void {
                echo 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }

                flush();
            };

            $onNotification = static function (array $notification) use ($write): void {
                $write([
                    'jsonrpc' => '2.0',
                    'method' => 'notifications/progress',
                    'params' => $notification,
                ]);
            };

            $response = $mcp->handle($decoded, $onNotification);

            if ($response !== null) {
                $write($response);
            }
        };

        return new StreamedResponse($inner, $emitter);
    }

    /**
     * Streamable HTTP mirrors selected JSON-RPC body fields into headers so
     * intermediaries can route/inspect requests without parsing the body.
     * Only enforced for modern (per-request `_meta`) requests: legacy
     * 2025-03-26 clients never send these headers, and the spec's own
     * backward-compatibility carve-out allows a server to treat a request
     * missing `MCP-Protocol-Version` as that earlier version rather than
     * rejecting it. Deliberately does NOT mirror `x-mcp-header`
     * tool-parameter headers — optional for servers per the spec, and a
     * materially bigger addition (a new JsonSchema-level annotation) than
     * the header validation this method performs.
     *
     * @param array<string, mixed> $decoded
     * @return string|null a human-readable mismatch description, or null if the headers are valid
     */
    private function headerMismatch(ServerRequestInterface $request, array $decoded): ?string
    {
        $expectedVersion = McpServer::requestedProtocolVersion($decoded);
        $headerVersion = $request->getHeaderLine('MCP-Protocol-Version');

        if ($headerVersion === '' || $headerVersion !== $expectedVersion) {
            return "Header mismatch: MCP-Protocol-Version header value \"{$headerVersion}\" does not match body value \"{$expectedVersion}\".";
        }

        $method = $decoded['method'] ?? null;
        $headerMethod = $request->getHeaderLine('Mcp-Method');

        if ($headerMethod === '' || $headerMethod !== $method) {
            $bodyMethod = is_string($method) ? $method : 'null';

            return "Header mismatch: Mcp-Method header value \"{$headerMethod}\" does not match body value \"{$bodyMethod}\".";
        }

        return $this->nameHeaderMismatch($request, $decoded, $method);
    }

    /**
     * `Mcp-Name` mirrors `params.name` (`tools/call`) or `params.uri`
     * (`resources/read`) — the spec's third method needing it,
     * `prompts/get`, has no equivalent here, since this server never
     * implements prompts. Required only for these two methods; every
     * other method is untouched, matching the spec's own scoping.
     *
     * @param array<string, mixed> $decoded
     */
    private function nameHeaderMismatch(ServerRequestInterface $request, array $decoded, mixed $method): ?string
    {
        /** @var array<string, mixed> $params */
        $params = is_array($decoded['params'] ?? null) ? $decoded['params'] : [];

        $bodyName = match ($method) {
            'tools/call' => $params['name'] ?? null,
            'resources/read' => $params['uri'] ?? null,
            default => null,
        };

        if ($bodyName === null) {
            // No name/uri in the body at all — callTool()/readResource()
            // reject that themselves with a more specific error; nothing
            // for this header check to validate against.
            return null;
        }

        $bodyName = is_string($bodyName) ? $bodyName : (string) $bodyName;
        $headerName = $request->getHeaderLine('Mcp-Name');
        $decodedHeaderName = self::decodeHeaderValue($headerName);

        if ($headerName === '' || $decodedHeaderName === null || $decodedHeaderName !== $bodyName) {
            return "Header mismatch: Mcp-Name header value \"{$headerName}\" does not match body value \"{$bodyName}\".";
        }

        return null;
    }

    /**
     * Decodes a header value per the transport's Base64 sentinel format
     * (`=?base64?{...}?=`), used by a conforming client when a value can't
     * be safely represented as plain ASCII. A value not wrapped in the
     * sentinel is returned as-is; one that is but fails to decode returns
     * null, so the caller's comparison against the body value fails
     * closed rather than silently treating a malformed header as a match.
     */
    private static function decodeHeaderValue(string $value): ?string
    {
        if (!str_starts_with($value, '=?base64?') || !str_ends_with($value, '?=')) {
            return $value;
        }

        $decoded = base64_decode(substr($value, 9, -2), strict: true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Maps a modern-era JSON-RPC error response to the HTTP status the
     * 2026-07-28 transport spec mandates for that error code. Only the
     * codes the spec explicitly documents a status for are mapped; any
     * other error (e.g. -32603 internal error) keeps the transport-level
     * default of 200, matching the legacy era's "status is always 200,
     * the JSON-RPC envelope carries the real outcome" behavior.
     *
     * @param array<string, mixed> $response
     */
    private function mcpHttpStatus(array $response): int
    {
        $code = $response['error']['code'] ?? null;

        return match ($code) {
            -32020, -32021, -32022, -32602 => 400,
            -32601 => 404,
            default => 200,
        };
    }

    /**
     * @param array<string, string> $headers
     */
    private function error(int $status, string $message, array $headers = []): ResponseInterface
    {
        return ErrorResponse::create($status, $message, $headers);
    }

    private function json(mixed $data, int $status): ResponseInterface
    {
        return new Response(
            status: $status,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function html(string $body): ResponseInterface
    {
        return new Response(
            status: 200,
            headers: ['Content-Type' => 'text/html'],
            body: $body,
        );
    }
}
