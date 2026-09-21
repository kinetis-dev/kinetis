<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\Exception\OpenApiSecurityException;
use Kinetis\OpenApi\OpenApiGenerator;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\OpenApi\Fixtures\AdminKeyMiddleware;
use Kinetis\Tests\OpenApi\Fixtures\ClassSecuredController;
use Kinetis\Tests\OpenApi\Fixtures\ConflictingSecurityController;
use Kinetis\Tests\OpenApi\Fixtures\ConflictingTokenMiddleware;
use Kinetis\Tests\OpenApi\Fixtures\GroupedTokenAuthMiddleware;
use Kinetis\Tests\OpenApi\Fixtures\GroupSecuredController;
use Kinetis\Tests\OpenApi\Fixtures\HiddenSecuredController;
use Kinetis\Tests\OpenApi\Fixtures\InheritingController;
use Kinetis\Tests\OpenApi\Fixtures\InvalidProviderController;
use Kinetis\Tests\OpenApi\Fixtures\SecuredController;
use Kinetis\Tests\OpenApi\Fixtures\TokenAuthMiddleware;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * What the generated document says about authentication, from the
 * middleware that enforces it and from an explicit declaration that
 * replaces the inference.
 *
 * Every assertion on a requirement list is assertSame, so it holds the
 * canonical form too: alternatives in composition order, scheme names
 * sorted within one alternative, scopes sorted and deduplicated.
 */
final class OpenApiSecurityTest extends TestCase
{
    /**
     * @param list<class-string> $controllers
     * @param list<class-string> $globalMiddleware
     * @param array<string, list<class-string>> $groups
     * @return array<string, mixed>
     */
    private static function generate(array $controllers, array $globalMiddleware = [], array $groups = []): array
    {
        $router = new Router();

        foreach ($controllers as $controller) {
            $router->register($controller);
        }

        return new OpenApiGenerator($router, globalMiddleware: $globalMiddleware, middlewareGroups: $groups)->generate();
    }

    public function test_global_security_middleware_becomes_the_documents_root_security(): void
    {
        $spec = self::generate(
            [SecuredController::class],
            [MethodLevelMiddleware::class, TokenAuthMiddleware::class],
        );

        self::assertSame([['token' => []]], $spec['security']);
        self::assertSame(TokenAuthMiddleware::DEFINITION, $spec['components']['securitySchemes']['token']);
    }

    public function test_a_routes_own_security_middleware_is_required_on_top_of_the_global_one(): void
    {
        $spec = self::generate([SecuredController::class], [TokenAuthMiddleware::class]);

        // Both run, in that order, so both are required — one
        // alternative naming both schemes, not two alternatives.
        self::assertSame(
            [['adminKey' => [], 'token' => []]],
            $spec['paths']['/secured/both']['get']['security'],
        );
    }

    public function test_a_route_adding_no_security_to_the_global_one_publishes_none_of_its_own(): void
    {
        $spec = self::generate([SecuredController::class], [TokenAuthMiddleware::class]);

        // The global pipeline already said this, at the root.
        self::assertArrayNotHasKey('security', $spec['paths']['/secured/plain']['get']);
        self::assertArrayNotHasKey('security', $spec['paths']['/secured/token']['get']);
    }

    public function test_middleware_describing_no_security_leaves_the_document_without_any(): void
    {
        $spec = self::generate([SecuredController::class], [MethodLevelMiddleware::class]);

        self::assertArrayNotHasKey('security', $spec);
        self::assertArrayNotHasKey('security', $spec['paths']['/secured/plain']['get']);
    }

    public function test_two_alternatives_stay_alternatives(): void
    {
        $spec = self::generate([SecuredController::class]);

        self::assertSame(
            [['token' => []], ['session' => []]],
            $spec['paths']['/secured/either']['get']['security'],
        );
    }

    public function test_one_scheme_required_twice_keeps_the_union_of_its_scopes(): void
    {
        $spec = self::generate([SecuredController::class]);

        self::assertSame(
            [['orders' => ['audit', 'read', 'write']]],
            $spec['paths']['/secured/scopes']['get']['security'],
        );
    }

    public function test_the_anonymous_alternative_is_kept_and_serializes_as_an_object(): void
    {
        $spec = self::generate([SecuredController::class]);
        $security = $spec['paths']['/secured/optional']['get']['security'];

        self::assertSame(['token' => []], $security[0]);
        self::assertInstanceOf(stdClass::class, $security[1]);
        // An empty PHP array would encode as [], which is not a
        // requirement object at all.
        self::assertStringContainsString(
            '"security":[{"token":[]},{}]',
            json_encode($spec['paths']['/secured/optional']['get'], JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_declaration_without_providers_publishes_an_empty_security_list(): void
    {
        $spec = self::generate([SecuredController::class], [TokenAuthMiddleware::class]);
        $operation = $spec['paths']['/secured/anonymous']['get'];

        self::assertSame([], $operation['security']);
        // `security: []` is what removes the root security; an absent
        // key would leave the operation inheriting it.
        self::assertStringContainsString('"security":[]', json_encode($operation, JSON_THROW_ON_ERROR));
    }

    public function test_a_declaration_replaces_the_security_its_middleware_would_infer(): void
    {
        $spec = self::generate([SecuredController::class]);

        // TokenAuthMiddleware still wraps the route; the declaration is
        // the whole published answer and does not combine with it.
        self::assertSame([['adminKey' => []]], $spec['paths']['/secured/explicit']['get']['security']);
    }

    public function test_a_method_declaration_replaces_the_class_one_which_replaces_inference(): void
    {
        $spec = self::generate([ClassSecuredController::class]);

        // AdminKeyMiddleware wraps every route of this controller, and
        // is what inference would publish for both.
        self::assertSame([['token' => []]], $spec['paths']['/class/inherited']['get']['security']);
        self::assertSame([['adminKey' => []]], $spec['paths']['/class/overridden']['get']['security']);
    }

    public function test_a_declaration_on_a_parent_class_does_not_reach_the_controller_extending_it(): void
    {
        $spec = self::generate([InheritingController::class]);

        self::assertSame([['token' => []]], $spec['paths']['/inheriting']['get']['security']);
        self::assertArrayNotHasKey('adminKey', $spec['components']['securitySchemes']);
    }

    public function test_a_group_reference_publishes_the_security_its_members_describe(): void
    {
        $spec = self::generate(
            [GroupSecuredController::class],
            groups: ['secure' => [GroupedTokenAuthMiddleware::class]],
        );

        // GroupedTokenAuthMiddleware declares nothing itself; the
        // description is its parent's.
        self::assertSame([['token' => []]], $spec['paths']['/group/secured']['get']['security']);
        self::assertSame(TokenAuthMiddleware::DEFINITION, $spec['components']['securitySchemes']['token']);
    }

    public function test_one_scheme_name_declared_identically_by_two_providers_is_published_once(): void
    {
        $spec = self::generate([SecuredController::class]);
        $schemes = $spec['components']['securitySchemes'];
        $names = array_keys($schemes);
        sort($names);

        // `token` is declared by three of the controller's providers,
        // identically each time, and published once.
        self::assertSame(['adminKey', 'orders', 'session', 'token'], $names);
        self::assertSame(TokenAuthMiddleware::DEFINITION, $schemes['token']);
    }

    public function test_one_scheme_name_declared_differently_by_two_providers_fails_generation(): void
    {
        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage(TokenAuthMiddleware::class);
        $this->expectExceptionMessage(ConflictingTokenMiddleware::class);
        $this->expectExceptionMessage('"token"');

        self::generate([ConflictingSecurityController::class]);
    }

    public function test_a_declaration_naming_a_class_that_describes_nothing_fails_generation(): void
    {
        $this->expectException(OpenApiSecurityException::class);
        $this->expectExceptionMessage(MethodLevelMiddleware::class);

        self::generate([InvalidProviderController::class]);
    }

    public function test_a_hidden_route_contributes_neither_an_operation_nor_a_scheme(): void
    {
        $spec = self::generate([HiddenSecuredController::class]);

        self::assertSame([], $spec['paths']);
        self::assertArrayNotHasKey('components', $spec);
    }

    public function test_security_schemes_are_published_beside_the_dto_schemas(): void
    {
        $spec = self::generate([SecuredController::class, UserController::class]);

        self::assertArrayHasKey('CreateUserRequest', $spec['components']['schemas']);
        self::assertArrayHasKey('token', $spec['components']['securitySchemes']);
    }
}
