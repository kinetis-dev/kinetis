<?php

declare(strict_types=1);

namespace Kinetis\Tests\Cache;

use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\Address;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\CreateOrderRequest;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\OrderController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\Http\Fixtures\VersionPrefixedController;
use Kinetis\Validation\Hydrator;
use PHPUnit\Framework\TestCase;

final class CompilerTest extends TestCase
{
    public function test_compile_produces_binding_plans_for_every_registered_route(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $compiled = (new Compiler())->compile($router);

        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::store', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::index', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::show', $compiled->http->httpBindingPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UserController::updateStatus', $compiled->http->httpBindingPlans);
    }

    public function test_compile_discovers_every_body_dto_class_and_produces_a_hydration_plan_for_it(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $compiled = (new Compiler())->compile($router);

        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\CreateUserRequest', $compiled->http->hydrationPlans);
        self::assertArrayHasKey('Kinetis\Tests\Http\Fixtures\UpdateStatusRequest', $compiled->http->hydrationPlans);
        self::assertTrue($compiled->http->hydrationPlans['Kinetis\Tests\Http\Fixtures\CreateUserRequest']['hasConstructor']);
    }




    public function test_compile_project_discovers_routes_and_commands_by_namespace(): void
    {
        $compiled = (new Compiler())->compileProject(__DIR__ . '/Fixtures');

        // Routes and commands are discovered by namespace — Fixtures/Http/
        // and Fixtures/Console/ are found via Fixtures/composer.json's own
        // PSR-4 mapping. Kinetis's own real Kinetis\Http/Kinetis\Console
        // classes are discovered right alongside them, so these assertions
        // check for the fixture's specific entries rather than exact
        // equality.
        self::assertArrayHasKey('Kinetis\Tests\Cache\Fixtures\Http\DiscoveredPingController::ping', $compiled->http->httpBindingPlans);
        self::assertContains('fixture:ping', array_column($compiled->commands->commands, 'name'));
        self::assertContains('Kinetis\Tests\Cache\Fixtures\Http\DiscoveredGlobalMiddleware', $compiled->http->globalMiddleware);
    }

    public function test_compile_carries_an_explicitly_passed_global_middleware_list_verbatim(): void
    {
        $compiled = (new Compiler())->compile(new Router(), middleware: ['global' => ['App\\RequestIdMiddleware']]);

        self::assertSame(['App\\RequestIdMiddleware'], $compiled->http->globalMiddleware);
    }

    public function test_compile_carries_explicitly_passed_plugin_data_verbatim(): void
    {
        $compiled = (new Compiler())->compile(new Router(), pluginData: ['App\\SomeRegistry' => ['x' => 1]]);

        self::assertSame(['App\\SomeRegistry' => ['x' => 1]], $compiled->plugins->data);
    }

    public function test_compile_project_discovers_a_packages_own_declared_discovery_class(): void
    {
        $compiled = (new Compiler())->compileProject(__DIR__ . '/Fixtures/PackageVendor');

        self::assertArrayHasKey(
            'Kinetis\Tests\Cache\Fixtures\AcmePackage\AcmeCacheableDiscovery',
            $compiled->plugins->data,
        );
    }

    public function test_compile_project_discovers_named_middleware_groups(): void
    {
        $compiled = (new Compiler())->compileProject(__DIR__ . '/Fixtures');

        self::assertSame(
            [
                'Kinetis\Tests\Cache\Fixtures\Http\GroupedAuthMiddleware',
                'Kinetis\Tests\Cache\Fixtures\Http\GroupedAdminMiddleware',
            ],
            $compiled->http->middlewareGroups['admin'],
        );
    }

    public function test_middleware_groups_survive_a_real_var_export_cache_file_round_trip(): void
    {
        $compiled = (new Compiler())->compile(
            new Router(),
            middleware: ['groups' => ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']]],
        );

        $directory = sys_get_temp_dir() . '/kinetis_group_cache_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);

        try {
            $store->writeAll($compiled);
            $reloaded = $store->loadHttp();

            self::assertNotNull($reloaded);
            self::assertSame(
                ['admin' => ['App\\AuthMiddleware', 'App\\RequireAdminMiddleware']],
                $reloaded->middlewareGroups,
            );
        } finally {
            CacheStore::destroy($directory);
        }
    }

    public function test_a_middleware_owned_route_prefix_survives_the_full_compile_and_reload_round_trip(): void
    {
        // The prefix is already baked into pathTemplate by the time
        // Router::register() returns — compile()/toArray()/fromArray()
        // never re-derive it, they just carry the string through, the
        // same as any other route path. This proves that carry-through
        // survives a real var_export() cache file, not just an in-memory
        // toArray()/fromArray() call.
        $router = new Router();
        $router->register(VersionPrefixedController::class);

        $compiled = (new Compiler())->compile($router);

        $directory = sys_get_temp_dir() . '/kinetis_prefix_cache_' . bin2hex(random_bytes(8));
        $store = new CacheStore($directory);

        try {
            $store->writeAll($compiled);
            $reloaded = $store->loadHttp();

            self::assertNotNull($reloaded);

            $router = Router::fromArray($reloaded->routes);
            self::assertSame('index', $router->match('GET', '/v1/users')->route->controllerMethod);
        } finally {
            CacheStore::destroy($directory);
        }
    }

    public function test_route_middleware_survives_the_full_compile_and_reload_round_trip(): void
    {
        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $compiled = (new Compiler())->compile($router);
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

        $compiled = (new Compiler())->compile($router);

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

        $compiled = (new Compiler())->compile($router);

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
            CacheStore::destroy($directory);
        }
    }
}
