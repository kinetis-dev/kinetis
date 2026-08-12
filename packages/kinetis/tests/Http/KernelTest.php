<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Cache\CacheFormat;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\CommandCache;
use Kinetis\Cache\CompiledCache;
use Kinetis\Cache\Compiler;
use Kinetis\Cache\EventCache;
use Kinetis\Cache\HttpCache;
use Kinetis\Cache\McpCache;
use Kinetis\Cache\OpenApiCache;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Http\StreamedResponse;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpRegistry;
use Kinetis\Mcp\McpServer;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\CurrentUserController;
use Kinetis\Tests\Http\Fixtures\DiscoveredGlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\EventDispatchingController;
use Kinetis\Tests\Http\Fixtures\EventLog;
use Kinetis\Tests\Http\Fixtures\GlobalMiddleware;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\McpScopedMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\OpenApiScopedMiddleware;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;
use Kinetis\Tests\Http\Fixtures\SendOrderConfirmationListener;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\Mcp\Fixtures\AccountController;
use Kinetis\Tests\Mcp\Fixtures\ProgressReportingController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/Fixtures/gc_collect_cycles_spy.php';

final class KernelTest extends TestCase
{
    private function kernel(): Kernel
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        return new Kernel($app, $router);
    }

    public function test_handles_a_registered_route_end_to_end(): void
    {
        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc']));

        $response = $this->kernel()->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@noy.cc'],
            json_decode((string) $response->getBody(), true),
        );
    }

    /**
     * Kernel resolves Kinetis\Persistence\TransactionGuard from every
     * request's RequestScope — but that class lives in the separate,
     * optional kinetis/persistence package, never installed for this
     * suite. This is the real, always-true "not installed" branch of the
     * class_exists() gate in Kernel::dispatchCore() — proving a request
     * completes normally with no dispose-hook crash, not just benefiting
     * from it implicitly the way every other test in this file already
     * does. The "is installed" branch is verified in kinetis/persistence's
     * own test suite instead, which is the one place both Kernel and
     * TransactionGuard are simultaneously available.
     */
    public function test_handles_a_request_normally_when_the_persistence_package_is_not_installed(): void
    {
        self::assertFalse(class_exists('Kinetis\Persistence\TransactionGuard'));

        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc']));

        $response = $this->kernel()->handle($request);

        self::assertSame(201, $response->getStatusCode());
    }

    public function test_returns_a_404_json_response_for_an_unknown_route(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_returns_a_405_json_response_for_a_disallowed_method(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('DELETE', '/users'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function test_a_405_response_carries_a_real_allow_header(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('DELETE', '/users'));

        self::assertSame(405, $response->getStatusCode());
        $allowed = array_map('trim', explode(',', $response->getHeaderLine('Allow')));
        self::assertSame(['POST', 'GET'], $allowed);
    }

    public function test_each_handled_request_gets_an_independent_request_scope(): void
    {
        $kernel = $this->kernel();

        $first = $kernel->handle(new ServerRequest('GET', '/users/1'));
        $second = $kernel->handle(new ServerRequest('GET', '/users/2'));

        self::assertSame(['id' => 1], json_decode((string) $first->getBody(), true));
        self::assertSame(['id' => 2], json_decode((string) $second->getBody(), true));
    }

    public function test_handles_a_body_dto_with_an_asymmetric_visibility_property(): void
    {
        $request = new ServerRequest('PATCH', '/users/1/status', body: json_encode(['status' => 'active']));

        $response = $this->kernel()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => 1, 'status' => 'active'], json_decode((string) $response->getBody(), true));
    }

    public function test_serves_the_openapi_document_at_openapi_json(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $spec = json_decode((string) $response->getBody(), true);
        self::assertSame('3.1.0', $spec['openapi']);
        self::assertArrayHasKey('/users', $spec['paths']);
    }

    public function test_serves_the_swagger_ui_page_at_docs(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('GET', '/docs'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('swagger-ui', (string) $response->getBody());
    }

    public function test_openapi_routes_can_be_disabled(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $kernel = new Kernel($app, $router, exposeOpenApi: false);

        $response = $kernel->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_mcp_endpoint_is_absent_by_default(): void
    {
        $response = $this->kernel()->handle(new ServerRequest('POST', '/mcp', body: '{}'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_mcp_endpoint_handles_json_rpc_over_http_when_provided(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));

        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    // --- /mcp Origin validation and #[AsMcpMiddleware]/
    // #[AsOpenApiMiddleware] scoped pipelines. ---

    private function mcpEnabledKernel(array $discoveredMcpMiddleware = [], array $mcpAllowedOrigins = []): Kernel
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));

        return new Kernel(
            $app,
            $router,
            mcp: $mcp,
            discoveredMcpMiddleware: $discoveredMcpMiddleware,
            mcpAllowedOrigins: $mcpAllowedOrigins,
        );
    }

    private function mcpToolsListRequest(): ServerRequest
    {
        return new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));
    }

    public function test_a_request_with_no_origin_header_reaches_mcp_regardless_of_the_allow_list(): void
    {
        $kernel = $this->mcpEnabledKernel(mcpAllowedOrigins: ['https://allowed.example']);

        $response = $kernel->handle($this->mcpToolsListRequest());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_origin_not_on_the_allow_list_is_rejected_with_403(): void
    {
        $kernel = $this->mcpEnabledKernel(mcpAllowedOrigins: ['https://allowed.example']);

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://evil.example');
        $response = $kernel->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_an_origin_on_the_allow_list_is_accepted(): void
    {
        $kernel = $this->mcpEnabledKernel(mcpAllowedOrigins: ['https://allowed.example']);

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://allowed.example');
        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_the_default_allow_list_rejects_any_origin_at_all(): void
    {
        $kernel = $this->mcpEnabledKernel();

        $request = $this->mcpToolsListRequest()->withHeader('Origin', 'https://anything.example');
        $response = $kernel->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_discovered_mcp_middleware_runs_for_mcp_but_not_for_a_normal_route_or_openapi(): void
    {
        RecordingMiddleware::$log = [];
        $kernel = $this->mcpEnabledKernel(discoveredMcpMiddleware: [McpScopedMiddleware::class]);

        $kernel->handle($this->mcpToolsListRequest());
        self::assertSame([McpScopedMiddleware::class], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/users/1'));
        self::assertSame([], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/openapi.json'));
        self::assertSame([], RecordingMiddleware::$log);
    }

    public function test_discovered_openapi_middleware_runs_for_both_openapi_json_and_docs_but_not_mcp(): void
    {
        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));

        $kernel = new Kernel($app, $router, mcp: $mcp, discoveredOpenApiMiddleware: [OpenApiScopedMiddleware::class]);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/openapi.json'));
        self::assertSame([OpenApiScopedMiddleware::class], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle(new ServerRequest('GET', '/docs'));
        self::assertSame([OpenApiScopedMiddleware::class], RecordingMiddleware::$log);

        RecordingMiddleware::$log = [];
        $kernel->handle($this->mcpToolsListRequest());
        self::assertSame([], RecordingMiddleware::$log);
    }

    public function test_scoped_mcp_middleware_runs_inside_the_global_pipeline_not_instead_of_it(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();
        $router = new Router();
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));

        $kernel = new Kernel($app, $router, mcp: $mcp, discoveredMcpMiddleware: [McpScopedMiddleware::class]);

        RecordingMiddleware::$log = [];
        $kernel->handle($this->mcpToolsListRequest());

        self::assertSame([GlobalMiddleware::class, McpScopedMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_mcp_endpoint_returns_202_for_a_notification(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(202, $response->getStatusCode());
    }

    public function test_mcp_endpoint_returns_405_for_get_since_no_server_initiated_stream_is_supported(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $response = $kernel->handle(new ServerRequest('GET', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
    }

    public function test_mcp_endpoint_returns_405_for_delete_since_session_termination_is_not_supported(): void
    {
        // Checked directly against the real 2026-07-28 spec text, not
        // assumed: a server implementing only this revision "SHOULD"
        // answer 405 to a DELETE /mcp the same way it does a GET —
        // DELETE used to terminate a session under the now-removed
        // Mcp-Session-Id mechanism from earlier Streamable HTTP
        // revisions. This route is deliberately intercepted by the same
        // scoped $mcpPipeline as GET now, rather than falling through to
        // the router's own 404 for an unmatched path/method.
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $response = $kernel->handle(new ServerRequest('DELETE', '/mcp'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
    }

    /**
     * @return array<string, mixed>
     */
    private function modernMcpMeta(): array
    {
        return [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => [],
        ];
    }

    public function test_modern_mcp_request_with_matching_headers_succeeds(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'server/discover');

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('complete', $body['result']['resultType']);
    }

    public function test_modern_mcp_request_missing_the_protocol_version_header_is_rejected(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('Mcp-Method', 'server/discover');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_mcp_request_with_a_mismatched_method_header_is_rejected(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32020, $body['error']['code']);
    }

    public function test_modern_mcp_unknown_method_maps_to_a_404(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'does/not/exist',
            'params' => ['_meta' => $this->modernMcpMeta()],
        ])))
            ->withHeader('MCP-Protocol-Version', '2026-07-28')
            ->withHeader('Mcp-Method', 'does/not/exist');

        $response = $kernel->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32601, $body['error']['code']);
    }

    public function test_modern_mcp_unsupported_protocol_version_maps_to_a_400(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcp = new McpServer(new McpRegistry(), new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = (new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '1999-01-01',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]],
        ])))
            ->withHeader('MCP-Protocol-Version', '1999-01-01')
            ->withHeader('Mcp-Method', 'tools/list');

        $response = $kernel->handle($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(-32022, $body['error']['code']);
    }

    public function test_legacy_mcp_request_ignores_missing_headers(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(AccountController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = $kernel->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('get_user_status', $body['result']['tools'][0]['name']);
    }

    public function test_forces_a_gc_cycle_at_the_end_of_a_request_when_persistent(): void
    {
        $GLOBALS['kinetisGcCollectCyclesCallCount'] = 0;

        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);

        (new Kernel($app, $router, isPersistent: true))->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame(1, $GLOBALS['kinetisGcCollectCyclesCallCount']);
    }

    public function test_does_not_force_a_gc_cycle_when_not_persistent(): void
    {
        $GLOBALS['kinetisGcCollectCyclesCallCount'] = 0;

        $app = new AppScope();
        $app->boot();
        $router = new Router();
        $router->register(UserController::class);

        (new Kernel($app, $router, isPersistent: false))->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame(0, $GLOBALS['kinetisGcCollectCyclesCallCount']);
    }

    public function test_a_tools_call_with_a_progress_token_returns_a_streamed_sse_response(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(ProgressReportingController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three', '_meta' => ['progressToken' => 'tok']],
        ]));

        $response = $kernel->handle($request);

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        // The emitter itself calls ob_flush()/flush() to push each chunk out
        // immediately — a single ob_start() here would have those calls push
        // straight to real stdout instead of accumulating. Nesting a second
        // buffer lets the emitter's own flushes land in the outer one, which
        // we then read back.
        ob_start();
        ob_start();
        ($response->getEmitter())();
        ob_end_clean();
        $output = ob_get_clean();

        $events = array_values(array_filter(explode("\n\n", trim($output))));
        self::assertCount(4, $events);

        $first = json_decode(substr($events[0], strlen('data: ')), true);
        self::assertSame('notifications/progress', $first['method']);
        self::assertSame(1, $first['params']['progress']);

        $last = json_decode(substr($events[3], strlen('data: ')), true);
        self::assertSame(1, $last['id']);
        self::assertFalse($last['result']['isError']);
    }

    public function test_a_tools_call_without_a_progress_token_stays_a_buffered_json_response(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $mcpRegistry = new McpRegistry();
        $mcpRegistry->register(ProgressReportingController::class);
        $mcp = new McpServer($mcpRegistry, new McpDispatcher($app));
        $kernel = new Kernel($app, $router, mcp: $mcp);

        $request = new ServerRequest('POST', '/mcp', body: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'count_to_three'],
        ]));

        $response = $kernel->handle($request);

        self::assertNotInstanceOf(StreamedResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_openapi_json_lazily_loads_and_serves_the_compiled_document_verbatim_when_a_cache_is_present(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        // Deliberately a different document than OpenApiGenerator would
        // ever produce live — proves the cached path is actually taken,
        // not just that the output happens to match.
        $stubOpenApi = ['openapi' => '3.1.0', 'paths' => ['stub' => true]];
        $directory = sys_get_temp_dir() . '/kinetis_kernel_test_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);
        $store->writeAll(new CompiledCache(
            http: new HttpCache(formatVersion: CacheFormat::VERSION, routes: [], httpBindingPlans: [], hydrationPlans: [], globalMiddleware: [], mcpMiddleware: [], openApiMiddleware: [], compiledAt: '2026-01-01T00:00:00+00:00'),
            mcp: new McpCache(formatVersion: CacheFormat::VERSION, mcpTools: [], mcpResources: [], mcpBindingPlans: [], hydrationPlans: [], compiledAt: '2026-01-01T00:00:00+00:00'),
            openApi: new OpenApiCache(formatVersion: CacheFormat::VERSION, openApi: $stubOpenApi, compiledAt: '2026-01-01T00:00:00+00:00'),
            commands: new CommandCache(formatVersion: CacheFormat::VERSION, commands: [], compiledAt: '2026-01-01T00:00:00+00:00'),
            events: new EventCache(formatVersion: CacheFormat::VERSION, listeners: [], compiledAt: '2026-01-01T00:00:00+00:00'),
        ));

        try {
            $kernel = new Kernel($app, $router, cacheStore: $store);
            $response = $kernel->handle(new ServerRequest('GET', '/openapi.json'));

            self::assertSame($stubOpenApi, json_decode((string) $response->getBody(), true));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }

    public function test_dispatch_uses_the_compiled_binding_plan_end_to_end(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $compiler = new Compiler();
        $compiled = $compiler->compile($router, new McpRegistry());

        $kernel = new Kernel($app, $router, httpCache: $compiled->http);
        $request = new ServerRequest('POST', '/users', body: json_encode(['name' => 'Alon', 'email' => 'alon@noy.cc']));

        $response = $kernel->handle($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(
            ['name' => 'Alon', 'email' => 'alon@noy.cc'],
            json_decode((string) $response->getBody(), true),
        );
    }

    protected function setUp(): void
    {
        RecordingMiddleware::$log = [];
    }

    public function test_global_middleware_runs_for_a_matched_route(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        (new Kernel($app, $router))->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame([GlobalMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_global_middleware_runs_even_for_a_request_that_matches_no_route(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();

        $response = (new Kernel($app, $router))->handle(new ServerRequest('GET', '/does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([GlobalMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_discovered_global_middleware_runs_for_a_matched_route(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $kernel = new Kernel($app, $router, discoveredGlobalMiddleware: [DiscoveredGlobalMiddleware::class]);
        $kernel->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame([DiscoveredGlobalMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_explicit_global_middleware_runs_more_outer_than_discovered_middleware(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        $kernel = new Kernel($app, $router, discoveredGlobalMiddleware: [DiscoveredGlobalMiddleware::class]);
        $kernel->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame([GlobalMiddleware::class, DiscoveredGlobalMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_a_class_registered_both_explicitly_and_as_discovered_runs_only_once(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);

        // The exact same class both explicitly registered and reported as
        // discovered — GlobalMiddlewareDiscovery would produce this if the
        // class also happened to carry #[AsGlobalMiddleware]. Discovery
        // must never add a second copy of a class already explicit.
        $kernel = new Kernel($app, $router, discoveredGlobalMiddleware: [GlobalMiddleware::class]);
        $kernel->handle(new ServerRequest('GET', '/users/1'));

        self::assertSame([GlobalMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_route_middleware_runs_class_level_then_method_level_after_global_middleware(): void
    {
        $app = new AppScope();
        $app->middleware(GlobalMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $response = (new Kernel($app, $router))->handle(new ServerRequest('GET', '/middleware-test'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            [GlobalMiddleware::class, ClassLevelMiddleware::class, MethodLevelMiddleware::class],
            RecordingMiddleware::$log,
        );
    }

    public function test_route_middleware_does_not_run_for_a_different_route_on_the_same_controller(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(MiddlewareTestController::class);

        (new Kernel($app, $router))->handle(new ServerRequest('GET', '/middleware-test/short-circuit'));

        // #[Middleware(ClassLevelMiddleware::class)] is on the controller,
        // so it still runs here too — only MethodLevelMiddleware (attached
        // to a different method) must be absent.
        self::assertSame([ClassLevelMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_a_route_middleware_can_short_circuit_before_the_controller_runs(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $response = (new Kernel($app, $router))->handle(new ServerRequest('GET', '/middleware-test/short-circuit'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'short-circuited'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_uncaught_exception_from_a_controller_becomes_a_500_instead_of_propagating(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $response = (new Kernel($app, $router))->handle(new ServerRequest('GET', '/middleware-test/throws'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Internal server error.'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_a_consumer_registered_logger_receives_the_exception_end_to_end(): void
    {
        $logger = new InMemoryLogger();

        $app = new AppScope();
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        $router = new Router();
        $router->register(MiddlewareTestController::class);

        (new Kernel($app, $router))->handle(new ServerRequest('GET', '/middleware-test/throws'));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
    }

    public function test_middleware_can_register_a_current_user_the_controller_then_receives(): void
    {
        $app = new AppScope();
        $app->boot();

        $router = new Router();
        $router->register(CurrentUserController::class);

        $response = (new Kernel($app, $router))->handle(new ServerRequest('GET', '/me'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['userId' => 'user-42'], json_decode((string) $response->getBody(), true));
    }

    public function test_a_controller_can_dispatch_an_event_a_registered_listener_receives(): void
    {
        $registry = new EventListenerRegistry();
        $registry->register(SendOrderConfirmationListener::class);

        $log = new EventLog();

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(EventLog::class, $log);
        $app->boot();

        $router = new Router();
        $router->register(EventDispatchingController::class);

        $response = (new Kernel($app, $router))->handle(new ServerRequest('POST', '/orders'));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame([42], $log->orderIds);
    }
}
