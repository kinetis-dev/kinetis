<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Http\Routing\Router;
use Kinetis\Mcp\McpRegistry;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\Mcp\Fixtures\AccountController;
use Kinetis\Validation\Hydrator;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    public function test_compile_produces_binding_plans_for_every_registered_route(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());

        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::store', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::index', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::show', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::updateStatus', $compiled->http->httpBindingPlans);
    }

    public function test_compile_discovers_every_body_dto_class_and_produces_a_hydration_plan_for_it(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());

        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\CreateUserRequest', $compiled->http->hydrationPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UpdateStatusRequest', $compiled->http->hydrationPlans);
        self::assertTrue($compiled->http->hydrationPlans['Kinetis\Tests\Http\Fixtures\CreateUserRequest']['hasConstructor']);
    }

    public function test_compile_keeps_http_and_mcp_hydration_plans_independent(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $compiled = (new Compiler())->compile($router, $registry);

        // UserController's DTOs are HTTP-only — must not leak into the MCP
        // artifact, which is meant to be loadable independently of http.php.
        self::assertArrayNotHasKey('Kinetis\Tests\Http\Fixtures\UpdateStatusRequest', $compiled->mcp->hydrationPlans);
    }

    public function test_compile_discovers_dto_classes_reachable_through_mcp_tools_too(): void
    {
        $registry = new McpRegistry();
        $registry->register(AccountController::class);

        $compiled = (new Compiler())->compile(new Router(), $registry);

        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\CreateUserRequest', $compiled->mcp->hydrationPlans);
        self::assertArrayHasKey('Kinetis\Tests\Mcp\Fixtures\AccountController::createUser', $compiled->mcp->mcpBindingPlans);
    }

    public function test_compile_embeds_the_full_openapi_document_matching_the_generator_directly(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());

        self::assertEquals((new OpenApiGenerator($router))->generate(), $compiled->openApi->openApi);
    }

    public function test_compile_project_discovers_routes_mcp_tools_and_commands_by_namespace(): void
    {
        $compiled = (new Compiler())->compileProject(__DIR__ . '/Fixtures');

        // Routes, MCP tools, and commands are all discovered by namespace
        // — Fixtures/Http/, Fixtures/Mcp/, and Fixtures/Console/ are found
        // via Fixtures/composer.json's own PSR-4 mapping. Kinetis's own
        // real Kinetis\Http/Kinetis\Mcp/Kinetis\Console classes are
        // discovered right alongside them, so these assertions check for
        // the fixture's specific entries rather than exact equality.
        self::assertArrayHasKey('Kinetis\Tests\Cache\Fixtures\Http\DiscoveredPingController::ping', $compiled->http->httpBindingPlans);
        self::assertContains('discovered_ping', array_column($compiled->mcp->mcpTools, 'name'));
        self::assertContains('fixture:ping', array_column($compiled->commands->commands, 'name'));
        self::assertContains('Kinetis\Tests\Cache\Fixtures\Http\DiscoveredGlobalMiddleware', $compiled->http->globalMiddleware);
    }

    public function test_compile_carries_an_explicitly_passed_global_middleware_list_verbatim(): void
    {
        $compiled = (new Compiler())->compile(new Router(), new McpRegistry(), null, ['App\\RequestIdMiddleware']);

        self::assertSame(['App\\RequestIdMiddleware'], $compiled->http->globalMiddleware);
    }

    public function test_route_middleware_survives_the_full_compile_and_reload_round_trip(): void
    {
        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());
        $reloaded = Router::fromArray($compiled->http->routes);

        $match = $reloaded->match('GET', '/middleware-test');

        self::assertSame(
            [ClassLevelMiddleware::class, MethodLevelMiddleware::class],
            $match->route->middleware,
        );
    }

    public function test_a_nested_dtos_class_is_discovered_only_through_the_top_level_dto_it_nests_inside(): void
    {
        $router = new Router();
        $router->register(OrderController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());

        // The top-level #[Body] DTO gets its own top-level hydration-plan
        // entry, with Address embedded inline as its nestedPlan — exactly
        // what Hydrator::compilePlan()'s own recursion produces.
        self::assertArrayHasKey(CreateOrderRequest::class, $compiled->http->hydrationPlans);
        $addressParam = $compiled->http->hydrationPlans[CreateOrderRequest::class]['parameters'][1];
        self::assertSame(Address::class, $addressParam['dtoClass']);
        self::assertSame(Address::class, $addressParam['nestedPlan']['className']);

        // Address never appears as an independent top-level key: this
        // discovery pass only ever walks #[Body]/tool-argument classes
        // directly, per Compiler's own doc comment — there's no separate
        // lookup anywhere by a nested DTO's class name for it to serve.
        self::assertArrayNotHasKey(Address::class, $compiled->http->hydrationPlans);
    }

    public function test_a_nested_hydration_plan_survives_the_var_export_cache_round_trip(): void
    {
        $router = new Router();
        $router->register(OrderController::class);

        $compiled = (new Compiler())->compile($router, new McpRegistry());

        $directory = sys_get_temp_dir() . '/kinetis_nested_dto_cache_test_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);
        $store->writeAll($compiled);

        try {
            $reloadedHttp = $store->loadHttp();
            self::assertNotNull($reloadedHttp);

            $plan = $reloadedHttp->hydrationPlans[CreateOrderRequest::class];

            // Not just structural equality — actually hydrate through the
            // plan that came back out of a real var_export()'d/required
            // PHP file, proving a nested plan is genuinely representable in
            // that format, not just assumed to be because it's "plain
            // arrays".
            $dto = Hydrator::hydrate(CreateOrderRequest::class, [
                'customerName' => 'Alon',
                'shippingAddress' => ['street' => '1 Infinite Loop', 'city' => 'Cupertino'],
            ], $plan);

            self::assertSame('1 Infinite Loop', $dto->shippingAddress->street);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
