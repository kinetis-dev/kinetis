<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_matches_a_static_route(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $match = $router->match('GET', '/users');

        self::assertSame('index', $match->route->controllerMethod);
        self::assertSame([], $match->pathParams);
    }

    public function test_matches_a_route_with_path_parameters(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $match = $router->match('GET', '/users/42');

        self::assertSame('show', $match->route->controllerMethod);
        self::assertSame(['id' => '42'], $match->pathParams);
    }

    public function test_applies_the_configured_status_code(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $match = $router->match('POST', '/users');

        self::assertSame(201, $match->route->status);
    }

    public function test_unknown_path_throws_route_not_found(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/does-not-exist');
    }

    public function test_known_path_with_wrong_method_throws_method_not_allowed(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $this->expectException(MethodNotAllowedException::class);
        $router->match('DELETE', '/users');
    }

    public function test_a_constrained_placeholder_route_matches_a_conforming_segment(): void
    {
        $router = new Router();
        $router->register(ConstrainedParametersController::class);

        $match = $router->match('GET', '/products/42');

        self::assertSame(['id' => '42'], $match->pathParams);
    }

    public function test_a_constrained_placeholder_route_404s_on_a_non_conforming_segment(): void
    {
        $router = new Router();
        $router->register(ConstrainedParametersController::class);

        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/products/not-a-number');
    }

    public function test_method_not_allowed_exposes_the_real_allowed_methods_list(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        try {
            $router->match('DELETE', '/users');
            self::fail('Expected a MethodNotAllowedException.');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['POST', 'GET'], $e->allowedMethods);
        }
    }

    public function test_to_array_from_array_round_trip_behaves_identically_to_live_registration(): void
    {
        $live = new Router();
        $live->register(UserController::class);

        $reconstructed = Router::fromArray($live->toArray());

        $liveMatch = $live->match('GET', '/users/42');
        $reconstructedMatch = $reconstructed->match('GET', '/users/42');

        self::assertSame($liveMatch->route->controllerMethod, $reconstructedMatch->route->controllerMethod);
        self::assertSame($liveMatch->pathParams, $reconstructedMatch->pathParams);
        self::assertSame($liveMatch->route->status, $reconstructedMatch->route->status);
    }

    public function test_to_array_from_array_round_trip_preserves_route_not_found_behavior(): void
    {
        $reconstructed = Router::fromArray((new Router())->toArray());

        $this->expectException(RouteNotFoundException::class);
        $reconstructed->match('GET', '/does-not-exist');
    }

    public function test_a_route_with_no_middleware_attributes_has_an_empty_middleware_list(): void
    {
        $router = new Router();
        $router->register(UserController::class);

        $match = $router->match('GET', '/users');

        self::assertSame([], $match->route->middleware);
    }

    public function test_class_and_method_level_middleware_are_combined_class_first(): void
    {
        $router = new Router();
        $router->register(MiddlewareTestController::class);

        $match = $router->match('GET', '/middleware-test');

        self::assertSame(
            [ClassLevelMiddleware::class, MethodLevelMiddleware::class],
            $match->route->middleware,
        );
    }

    public function test_to_array_from_array_round_trip_preserves_middleware(): void
    {
        $live = new Router();
        $live->register(MiddlewareTestController::class);

        $reconstructed = Router::fromArray($live->toArray());

        $liveMatch = $live->match('GET', '/middleware-test');
        $reconstructedMatch = $reconstructed->match('GET', '/middleware-test');

        self::assertSame($liveMatch->route->middleware, $reconstructedMatch->route->middleware);
        self::assertSame(
            [ClassLevelMiddleware::class, MethodLevelMiddleware::class],
            $reconstructedMatch->route->middleware,
        );
    }
}
