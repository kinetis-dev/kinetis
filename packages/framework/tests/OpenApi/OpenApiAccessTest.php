<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

final class OpenApiAccessTest extends TestCase
{
    private static function router(): Router
    {
        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        return $router;
    }

    public function test_enabled_access_serves_the_route_table_it_is_given(): void
    {
        $router = self::router();

        self::assertSame($router, OpenApiAccess::enabled()->routes($router));
    }

    public function test_disabled_access_removes_only_the_documentation_routes(): void
    {
        $router = self::router();

        $routes = OpenApiAccess::disabled()->routes($router)->routes();

        self::assertNotContains(DocumentationController::class, array_column($routes, 'controllerClass'));
        self::assertSame(
            array_values(array_filter(
                $router->routes(),
                static fn ($route): bool => $route->controllerClass === UserController::class,
            )),
            $routes,
        );
        self::assertContains(DocumentationController::class, array_column($router->routes(), 'controllerClass'));
    }
}
