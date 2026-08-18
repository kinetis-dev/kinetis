<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteTest extends TestCase
{
    public function test_a_plain_placeholder_matches_any_non_slash_segment(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame(['id' => 'abc'], $route->matchPath('/users/abc'));
        self::assertSame(['id' => '42'], $route->matchPath('/users/42'));
    }

    public function test_a_constrained_placeholder_only_matches_its_own_pattern(): void
    {
        $route = new Route('GET', '/products/{id:\d+}', 'C', 'm', 200);

        self::assertSame(['id' => '42'], $route->matchPath('/products/42'));
        self::assertNull($route->matchPath('/products/abc'));
    }

    public function test_a_constrained_placeholder_pattern_can_span_a_fixed_length(): void
    {
        $route = new Route('GET', '/orders/{code:[0-9a-f]{8}}', 'C', 'm', 200);

        self::assertSame(['code' => 'deadbeef'], $route->matchPath('/orders/deadbeef'));
        self::assertNull($route->matchPath('/orders/deadbee'));
        self::assertNull($route->matchPath('/orders/deadbeefg'));
    }

    public function test_path_parameter_pattern_returns_the_raw_constraint_for_a_constrained_placeholder(): void
    {
        $route = new Route('GET', '/products/{id:\d+}', 'C', 'm', 200);

        self::assertSame('\d+', $route->pathParameterPattern('id'));
    }

    public function test_path_parameter_pattern_returns_null_for_a_plain_placeholder(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertNull($route->pathParameterPattern('id'));
    }

    public function test_path_parameter_pattern_returns_null_for_an_unknown_name(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertNull($route->pathParameterPattern('missing'));
    }

    public function test_path_parameter_names_strips_the_constraint_suffix(): void
    {
        $route = new Route('GET', '/products/{id:\d+}/{sku}', 'C', 'm', 200);

        self::assertSame(['id', 'sku'], $route->pathParameterNames());
    }

    public function test_open_api_path_template_strips_every_constraint_back_to_a_plain_placeholder(): void
    {
        $route = new Route('GET', '/products/{id:\d+}/{sku:[a-z-]+}', 'C', 'm', 200);

        self::assertSame('/products/{id}/{sku}', $route->openApiPathTemplate());
    }

    public function test_open_api_path_template_is_unchanged_when_no_placeholder_has_a_constraint(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame('/users/{id}', $route->openApiPathTemplate());
    }

    /**
     * @return list<array{string, string}>
     */
    public static function pathsToNormalize(): array
    {
        return [
            ['/users', '/users'],
            ['/users/', '/users'],
            ['users', '/users'],
            ['users/', '/users'],
            ['/', '/'],
            ['', '/'],
            ['/users/{id}/', '/users/{id}'],
        ];
    }

    /**
     * Route normalizes whatever it is handed, including a path with no
     * leading slash — Router rejects that earlier
     * (Exception\InvalidRoutePathException), so in practice this only
     * ever fires for the trailing slash, but Route is also constructed by
     * fromArray() and directly, and stays self-consistent for both.
     */
    #[DataProvider('pathsToNormalize')]
    public function test_a_path_is_stored_in_one_canonical_form(string $declared, string $expected): void
    {
        $route = new Route('GET', $declared, 'C', 'm', 200);

        self::assertSame($expected, $route->pathTemplate);
    }

    /**
     * The request path goes through the same rule as the declared one, so
     * one route answers one set of requests however either was written.
     */
    public function test_a_trailing_slash_on_the_request_path_still_matches(): void
    {
        $route = new Route('GET', '/users', 'C', 'm', 200);

        self::assertNotNull($route->matchPath('/users'));
        self::assertNotNull($route->matchPath('/users/'));
    }

    public function test_the_root_route_still_matches_the_root_path(): void
    {
        $route = new Route('GET', '/', 'C', 'm', 200);

        self::assertNotNull($route->matchPath('/'));
        // A PSR-7 URI may carry no path component at all.
        self::assertNotNull($route->matchPath(''));
    }

    public function test_a_trailing_slash_after_a_path_parameter_still_binds_it(): void
    {
        $route = new Route('GET', '/users/{id}', 'C', 'm', 200);

        self::assertSame(['id' => '7'], $route->matchPath('/users/7/'));
    }

    public function test_paths_differing_only_by_a_trailing_slash_are_the_same_route(): void
    {
        $withSlash = new Route('GET', '/users/', 'C', 'm', 200);
        $without = new Route('GET', '/users', 'C', 'm', 200);

        // Which is why registering both is a duplicate rather than two
        // routes each answering half the requests a caller would expect.
        self::assertSame($without->conflictKey(), $withSlash->conflictKey());
    }
}
