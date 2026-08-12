<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Route;
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
}
