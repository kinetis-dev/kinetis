<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\ConstrainedDuplicateRouteController;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\DuplicateRouteControllerA;
use Kinetis\Tests\Http\Fixtures\DuplicateRouteControllerB;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Reflection\Exception\AttributeScopeException;
use Kinetis\Tests\Http\Fixtures\PrefixedOrderController;
use Kinetis\Tests\Http\Fixtures\PrefixedUserController;
use Kinetis\Tests\Reflection\Fixtures\AbstractRouted;
use Kinetis\Tests\Reflection\Fixtures\InheritsHelperOnly;
use Kinetis\Tests\Reflection\Fixtures\InheritsRoute;
use Kinetis\Tests\Reflection\Fixtures\UsesRoutedTrait;
use Kinetis\Http\Routing\Exception\InvalidRoutePathException;
use Kinetis\Tests\Http\Fixtures\AdminScopedMiddleware;
use Kinetis\Tests\Http\Fixtures\EmptyPathController;
use Kinetis\Tests\Http\Fixtures\GroupReferencingController;
use Kinetis\Tests\Http\Fixtures\MultiLayerPrefixController;
use Kinetis\Tests\Http\Fixtures\UnrootedMiddlewarePrefixController;
use Kinetis\Tests\Http\Fixtures\UnrootedPathController;
use Kinetis\Tests\Http\Fixtures\UnrootedPrefixController;
use Kinetis\Tests\Http\Fixtures\VersionedMiddleware;
use Kinetis\Tests\Http\Fixtures\VersionPrefixedController;
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

    public function test_registering_a_route_claiming_the_same_requests_throws(): void
    {
        $router = new Router();
        $router->register(DuplicateRouteControllerA::class);

        // A different placeholder name doesn't change which paths match —
        // /dup/{key} claims exactly the requests /dup/{id} already owns.
        $this->expectException(DuplicateRouteException::class);

        $router->register(DuplicateRouteControllerB::class);
    }

    public function test_a_constrained_variant_of_the_same_path_is_a_different_match_set_and_stays_registrable(): void
    {
        $router = new Router();
        $router->register(ConstrainedDuplicateRouteController::class);
        $router->register(DuplicateRouteControllerA::class);

        // First-match-wins ordering between the two genuinely different
        // match sets: the constrained route takes numeric segments, the
        // unconstrained one everything else.
        self::assertSame('showNumeric', $router->match('GET', '/dup/42')->route->controllerMethod);
        self::assertSame('show', $router->match('GET', '/dup/abc')->route->controllerMethod);
    }

    public function test_a_routed_method_inherited_from_a_parent_is_rejected(): void
    {
        $router = new Router();

        // The #[Get] belongs to the parent while #[Hidden], #[Middleware]
        // and everything else on the child would go unread — registering
        // it would honour one class's attributes and ignore the other's.
        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage(AbstractRouted::class);

        $router->register(InheritsRoute::class);
    }

    public function test_inheriting_a_plain_helper_alongside_an_own_route_stays_legal(): void
    {
        $router = new Router();
        $router->register(InheritsHelperOnly::class);

        // Only an inherited *routed* method is an error; a controller may
        // still extend a base class for ordinary shared behaviour.
        self::assertSame(['/own'], array_map(fn ($r) => $r->pathTemplate, $router->routes()));
    }

    public function test_a_routed_method_used_from_a_trait_registers(): void
    {
        $router = new Router();
        $router->register(UsesRoutedTrait::class);

        self::assertSame(['/from-trait'], array_map(fn ($r) => $r->pathTemplate, $router->routes()));
    }

    public function test_an_abstract_controller_cannot_be_registered(): void
    {
        $router = new Router();

        $this->expectException(AttributeScopeException::class);
        $this->expectExceptionMessage('is abstract and cannot be registered');

        $router->register(AbstractRouted::class);
    }

    public function test_a_route_prefix_is_prepended_to_every_route_on_the_controller(): void
    {
        $router = new Router();
        $router->register(PrefixedUserController::class);

        self::assertSame(
            ['/users', '/users/{id}'],
            array_map(fn ($r) => $r->pathTemplate, $router->routes()),
        );
    }

    public function test_a_route_prefix_normalises_stray_slashes(): void
    {
        $router = new Router();
        $router->register(PrefixedOrderController::class);

        // Declared as '/orders/' — the trailing slash is normalised away.
        // A missing *leading* slash is rejected instead; see
        // test_a_route_prefix_must_start_with_a_slash.
        self::assertSame(
            ['/orders', '/orders/{id}'],
            array_map(fn ($r) => $r->pathTemplate, $router->routes()),
        );
    }

    public function test_two_controllers_sharing_a_trait_under_different_prefixes_do_not_conflict(): void
    {
        $router = new Router();
        $router->register(PrefixedUserController::class);

        // Identical route attributes, different prefixes: conflict
        // detection sees the resolved paths, so these are distinct.
        $router->register(PrefixedOrderController::class);

        self::assertSame('show', $router->match('GET', '/users/7')->route->controllerMethod);
        self::assertSame(
            PrefixedOrderController::class,
            $router->match('GET', '/orders/7')->route->controllerClass,
        );
    }

    public function test_a_route_path_must_start_with_a_slash(): void
    {
        $router = new Router();

        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('declares the path "users" — a route path must start with "/"');

        $router->register(UnrootedPathController::class);
    }

    public function test_an_empty_route_path_is_rejected_rather_than_claiming_the_root(): void
    {
        $router = new Router();

        // '' would normalise to '/', so a likely typo would quietly take
        // over the root route.
        $this->expectException(InvalidRoutePathException::class);

        $router->register(EmptyPathController::class);
    }

    public function test_a_route_prefix_must_start_with_a_slash(): void
    {
        $router = new Router();

        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('#[RoutePrefix("users")]');

        $router->register(UnrootedPrefixController::class);
    }

    public function test_a_route_declaring_a_slash_sits_at_its_prefix(): void
    {
        $router = new Router();
        $router->register(PrefixedUserController::class);

        self::assertSame('index', $router->match('GET', '/users')->route->controllerMethod);
    }

    public function test_a_middlewares_own_route_prefix_is_prepended_to_a_referencing_controllers_routes(): void
    {
        $router = new Router();
        $router->register(VersionPrefixedController::class);

        self::assertSame('index', $router->match('GET', '/v1/users')->route->controllerMethod);
    }

    public function test_a_global_middlewares_prefix_is_outermost(): void
    {
        $router = new Router();
        $router->register(UserController::class, [VersionedMiddleware::class]);

        self::assertSame('index', $router->match('GET', '/v1/users')->route->controllerMethod);
    }

    public function test_every_prefix_layer_composes_outer_to_inner(): void
    {
        $router = new Router();

        // Global (VersionedMiddleware, "/v1") -> route-level class-level
        // middleware (also VersionedMiddleware here, "/v1" again, proving
        // the same class can legitimately contribute at two different
        // layers without collapsing) -> route-level method-level
        // middleware (AdminScopedMiddleware, "/admin") -> the controller's
        // own #[RoutePrefix] ("/users") -> the route's own declared path.
        $router->register(MultiLayerPrefixController::class, [VersionedMiddleware::class]);

        self::assertSame(
            ['/v1/v1/admin/users/{id}'],
            array_map(fn ($r) => $r->pathTemplate, $router->routes()),
        );
        self::assertSame(['id' => '42'], $router->match('GET', '/v1/v1/admin/users/42')->pathParams);
    }

    public function test_a_middleware_group_reference_never_contributes_a_prefix(): void
    {
        $router = new Router();
        $router->register(GroupReferencingController::class);

        self::assertSame('index', $router->match('GET', '/reports')->route->controllerMethod);
    }

    public function test_a_middlewares_own_route_prefix_must_start_with_a_slash(): void
    {
        $router = new Router();

        $this->expectException(InvalidRoutePathException::class);
        $this->expectExceptionMessage('#[RoutePrefix("bad")]');

        $router->register(UnrootedMiddlewarePrefixController::class);
    }
}
