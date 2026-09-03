<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Routing;

use Kinetis\Cache\Exception\CacheArtifactExceptionInterface;
use Kinetis\Http\Routing\Exception\ConflictingRegistrationContextException;
use Kinetis\Http\Routing\Exception\DuplicateRouteException;
use Kinetis\Http\Routing\Exception\MethodNotAllowedException;
use Kinetis\Http\Routing\Exception\RouteNotFoundException;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\AtomicRegistrationFailureController;
use Kinetis\Tests\Http\Fixtures\ClassLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\ConstrainedDuplicateRouteController;
use Kinetis\Tests\Http\Fixtures\ConstrainedParametersController;
use Kinetis\Tests\Http\Fixtures\DuplicateRouteControllerA;
use Kinetis\Tests\Http\Fixtures\DuplicateRouteControllerB;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\MiddlewareTestController;
use Kinetis\Tests\Http\Fixtures\MixedAndExactSegmentController;
use Kinetis\Tests\Http\Fixtures\MultiVerbController;
use Kinetis\Tests\Http\Fixtures\PlaceholderBeforeStaticController;
use Kinetis\Tests\Http\Fixtures\SameControllerConflictController;
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
            // Deterministic and deduplicated regardless of registration
            // order — Route::compareForMatching()'s own content-based
            // tiebreak (httpMethod alphabetically, once every path
            // segment ties) is what actually produces this order now,
            // not whichever method UserController happened to declare
            // first.
            self::assertSame(['GET', 'POST'], $e->allowedMethods);
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

    public function test_a_controller_with_an_early_valid_and_later_invalid_route_registers_none_of_its_routes(): void
    {
        $router = new Router();

        try {
            $router->register(AtomicRegistrationFailureController::class);
            self::fail('Expected an InvalidRoutePathException.');
        } catch (InvalidRoutePathException) {
            // Expected — the whole point of this test.
        }

        self::assertSame([], $router->routes());
        $this->expectException(RouteNotFoundException::class);
        $router->match('GET', '/atomic-fail/valid');
    }

    public function test_retrying_a_still_invalid_controller_fails_again_rather_than_becoming_a_no_op(): void
    {
        $router = new Router();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $router->register(AtomicRegistrationFailureController::class);
                self::fail("Expected an InvalidRoutePathException on attempt {$attempt}.");
            } catch (InvalidRoutePathException) {
                // A failed attempt must never be marked registered — retried
                // here to prove the second attempt fails identically rather
                // than silently no-opping the way a successful class would.
            }
        }

        self::assertSame([], $router->routes());
    }

    public function test_registering_the_same_class_twice_is_a_safe_no_op(): void
    {
        $router = new Router();
        $router->register(UserController::class);
        $router->register(UserController::class);

        // UserController declares 7 routes; a second registration must not
        // duplicate any of them.
        self::assertCount(7, $router->routes());
    }

    public function test_a_method_carrying_two_different_verb_attributes_registers_both_independently(): void
    {
        $router = new Router();
        $router->register(MultiVerbController::class);

        self::assertCount(2, $router->routes());
        self::assertSame('handle', $router->match('GET', '/multi')->route->controllerMethod);
        self::assertSame('handle', $router->match('POST', '/multi')->route->controllerMethod);
    }

    public function test_two_methods_on_one_controller_claiming_the_same_request_conflict(): void
    {
        $router = new Router();

        $this->expectException(DuplicateRouteException::class);
        $router->register(SameControllerConflictController::class);
    }

    public function test_a_same_controller_conflict_registers_none_of_its_routes(): void
    {
        $router = new Router();

        try {
            $router->register(SameControllerConflictController::class);
            self::fail('Expected a DuplicateRouteException.');
        } catch (DuplicateRouteException) {
            // Expected — the whole point of this test.
        }

        self::assertSame([], $router->routes());
    }

    public function test_route_precedence_is_independent_of_declaration_order(): void
    {
        $router = new Router();
        // byId() (the unconstrained placeholder) is declared, and therefore
        // reflected, before self() (the static segment) — a router matching
        // in registration/reflection order would resolve "/scoped/self" to
        // byId() instead.
        $router->register(PlaceholderBeforeStaticController::class);

        self::assertSame('self', $router->match('GET', '/scoped/self')->route->controllerMethod);
        self::assertSame('byId', $router->match('GET', '/scoped/anything-else')->route->controllerMethod);
    }

    public function test_to_array_from_array_round_trip_preserves_match_order(): void
    {
        $live = new Router();
        $live->register(PlaceholderBeforeStaticController::class);

        $reconstructed = Router::fromArray($live->toArray());

        self::assertSame(
            array_map(fn ($r) => $r->pathTemplate, $live->routes()),
            array_map(fn ($r) => $r->pathTemplate, $reconstructed->routes()),
        );
        self::assertSame('self', $reconstructed->match('GET', '/scoped/self')->route->controllerMethod);
    }

    public function test_a_fully_literal_segment_beats_a_mixed_segment_regardless_of_declaration_order(): void
    {
        $router = new Router();
        // template() (the mixed "report-{id}.pdf" route) is declared,
        // and therefore reflected, before exact() (the fully literal
        // "report-2026.pdf" route) — proving the exact route still wins
        // for the path it also matches.
        $router->register(MixedAndExactSegmentController::class);

        self::assertSame('exact', $router->match('GET', '/files/report-2026.pdf')->route->controllerMethod);
        self::assertSame('template', $router->match('GET', '/files/report-abc.pdf')->route->controllerMethod);
    }

    public function test_to_array_from_array_round_trip_preserves_mixed_segment_precedence(): void
    {
        $live = new Router();
        $live->register(MixedAndExactSegmentController::class);

        $reconstructed = Router::fromArray($live->toArray());

        self::assertSame(
            'exact',
            $reconstructed->match('GET', '/files/report-2026.pdf')->route->controllerMethod,
        );
    }

    public function test_registering_the_same_class_twice_with_the_same_global_middleware_stays_idempotent(): void
    {
        $router = new Router();
        $router->register(UserController::class, [VersionedMiddleware::class]);
        $router->register(UserController::class, [VersionedMiddleware::class]);

        self::assertCount(7, $router->routes());
        self::assertSame('index', $router->match('GET', '/v1/users')->route->controllerMethod);
    }

    public function test_registering_the_same_class_with_a_different_global_middleware_context_throws(): void
    {
        $router = new Router();
        $router->register(UserController::class, [VersionedMiddleware::class]);

        $this->expectException(ConflictingRegistrationContextException::class);
        $router->register(UserController::class, []);
    }

    public function test_a_conflicting_second_registration_leaves_the_first_contexts_routes_untouched(): void
    {
        $router = new Router();
        $router->register(UserController::class, [VersionedMiddleware::class]);

        try {
            $router->register(UserController::class, []);
            self::fail('Expected a ConflictingRegistrationContextException.');
        } catch (ConflictingRegistrationContextException) {
            // Expected — the routes committed under the first, real
            // context must remain exactly as they were.
        }

        self::assertSame('index', $router->match('GET', '/v1/users')->route->controllerMethod);
        self::assertCount(7, $router->routes());
    }

    public function test_registering_a_class_reconstructed_from_a_compiled_artifact_always_throws(): void
    {
        $live = new Router();
        $live->register(UserController::class);

        $reconstructed = Router::fromArray($live->toArray());

        // The compiled artifact carries no record of the original
        // $globalMiddleware context, so a later live register() call
        // for the same class can never be verified as compatible with
        // it — rejected rather than trusted.
        $this->expectException(ConflictingRegistrationContextException::class);
        $reconstructed->register(UserController::class);
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

        // Canonicalized, not order-dependent: routes() is now sorted by
        // Route::compareForMatching() (a deeper path sorts ahead of a
        // shallower one that could never actually compete with it for
        // the same request), not registration order — this test only
        // cares that both paths were correctly prefixed.
        self::assertEqualsCanonicalizing(
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
        // test_a_route_prefix_must_start_with_a_slash. Canonicalized, not
        // order-dependent — see the identical note above.
        self::assertEqualsCanonicalizing(
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

    /**
     * @return array{httpMethod:string,pathTemplate:string,controllerClass:string,controllerMethod:string,status:int,middleware:list<string>}
     */
    private function validRouteEntry(): array
    {
        return ['httpMethod' => 'GET', 'pathTemplate' => '/x', 'controllerClass' => UserController::class, 'controllerMethod' => 'index', 'status' => 200, 'middleware' => []];
    }

    public function test_from_array_rejects_an_entry_with_an_unexpected_extra_field(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        Router::fromArray([[...$this->validRouteEntry(), 'extra' => 'nope']]);
    }

    public function test_from_array_rejects_an_entry_missing_a_required_field(): void
    {
        $entry = $this->validRouteEntry();
        unset($entry['status']);

        $this->expectException(CacheArtifactExceptionInterface::class);

        Router::fromArray([$entry]);
    }

    public function test_from_array_rejects_two_routes_claiming_the_same_request(): void
    {
        $this->expectException(CacheArtifactExceptionInterface::class);

        Router::fromArray([
            $this->validRouteEntry(),
            [...$this->validRouteEntry(), 'controllerMethod' => 'show'],
        ]);
    }

    public function test_from_array_accepts_two_routes_with_different_path_templates(): void
    {
        $router = Router::fromArray([
            $this->validRouteEntry(),
            [...$this->validRouteEntry(), 'pathTemplate' => '/y'],
        ]);

        self::assertSame('/x', $router->match('GET', '/x')->route->pathTemplate);
        self::assertSame('/y', $router->match('GET', '/y')->route->pathTemplate);
    }
}
