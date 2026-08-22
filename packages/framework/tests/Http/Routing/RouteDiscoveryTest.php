<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Tests\Http\Fixtures\VersionedMiddleware;
use PHPUnit\Framework\TestCase;

final class RouteDiscoveryTest extends TestCase
{
    /**
     * A project contributing nothing still gets the framework's own two
     * routes, which is what makes /openapi.json and /docs ordinary
     * discovered routes rather than something Kernel intercepts.
     */
    public function test_discovers_only_the_frameworks_own_routes_when_the_project_root_does_not_exist(): void
    {
        $router = RouteDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures/does-not-exist');

        self::assertSame(
            ['/openapi', '/openapi.json'],
            self::sortedPaths($router),
        );
    }

    /**
     * @return list<string>
     */
    private static function sortedPaths(\Kinetis\Http\Routing\Router $router): array
    {
        $paths = array_map(static fn ($route): string => $route->pathTemplate, $router->routes());
        sort($paths);

        return array_values($paths);
    }

    public function test_discovers_a_projects_own_controllers_anywhere_under_its_psr4_root(): void
    {
        $router = RouteDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures');

        $match = $router->match('GET', '/fixture-ping');
        self::assertSame('ping', $match->route->controllerMethod);

        $unconventionalMatch = $router->match('GET', '/fixture-unconventional');
        self::assertSame('ping', $unconventionalMatch->route->controllerMethod);
    }

    public function test_paths_restricts_the_project_wide_scan(): void
    {
        $router = RouteDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures', ['Http']);

        $match = $router->match('GET', '/fixture-ping');
        self::assertSame('ping', $match->route->controllerMethod);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/fixture-unconventional');
    }

    public function test_paths_falls_back_to_the_route_discovery_paths_env_var(): void
    {
        putenv('ROUTE_DISCOVERY_PATHS=Http');

        try {
            $router = RouteDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures');

            $match = $router->match('GET', '/fixture-ping');
            self::assertSame('ping', $match->route->controllerMethod);

            $this->expectException(RouteNotFoundException::class);
            $router->match('GET', '/fixture-unconventional');
        } finally {
            putenv('ROUTE_DISCOVERY_PATHS');
        }
    }

    public function test_an_explicit_paths_argument_wins_over_the_env_var(): void
    {
        putenv('ROUTE_DISCOVERY_PATHS=DoesNotExist');

        try {
            $router = RouteDiscovery::discover(dirname(__DIR__, 2) . '/Cache/Fixtures', []);

            $match = $router->match('GET', '/fixture-unconventional');
            self::assertSame('ping', $match->route->controllerMethod);
        } finally {
            putenv('ROUTE_DISCOVERY_PATHS');
        }
    }

    public function test_discovering_against_the_real_kinetis_root_does_not_throw_or_duplicate_routes(): void
    {
        // Same overlap CommandDiscoveryTest's identical regression test
        // covers: project root and framework root are the same repository
        // when developing Kinetis itself. Router::register() has no
        // duplicate-name check either, so core having zero route-attributed
        // classes today means this can only prove "runs without error and
        // finds nothing extra" — not duplicate-row counting — but it's the
        // exact call CommandDiscoveryTest's own regression would have
        // crashed on, run here against the sibling discovery class.
        $router = RouteDiscovery::discover(dirname(__DIR__, 3));

        // Exactly one row each: the framework root and the project root
        // are the same repository here, so a missing cross-pass dedupe
        // would register DocumentationController's routes twice.
        self::assertSame(['/openapi', '/openapi.json'], self::sortedPaths($router));
    }

    public function test_global_middleware_is_threaded_through_to_every_discovered_controller(): void
    {
        $router = RouteDiscovery::discover(
            dirname(__DIR__, 2) . '/Cache/Fixtures',
            globalMiddleware: [VersionedMiddleware::class],
        );

        $match = $router->match('GET', '/v1/fixture-ping');
        self::assertSame('ping', $match->route->controllerMethod);
    }
}
