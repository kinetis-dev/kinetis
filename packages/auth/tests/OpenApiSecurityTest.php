<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests;

use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Auth\Tests\Fixtures\GroupedBearerAuthMiddleware;
use Kinetis\Auth\Tests\Fixtures\GroupedFixtureController;
use Kinetis\Auth\Tests\Fixtures\ProtectedFixtureController;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiGenerator;
use PHPUnit\Framework\TestCase;

/**
 * What a route this middleware protects says in the generated OpenAPI
 * document. The definition is the published contract of this package:
 * a client reads the scheme name and reuses it.
 */
final class OpenApiSecurityTest extends TestCase
{
    public function test_the_middleware_declares_an_http_bearer_scheme(): void
    {
        $description = BearerAuthMiddleware::openApiSecurity();

        self::assertSame(
            [
                'bearerToken' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'An opaque token issued by this application, sent as "Authorization: Bearer <token>".',
                ],
            ],
            $description->schemes,
        );
        self::assertSame([['bearerToken' => []]], $description->requirements);
    }

    public function test_a_protected_route_is_documented_as_requiring_that_scheme(): void
    {
        $router = new Router();
        $router->register(ProtectedFixtureController::class);

        $document = new OpenApiGenerator($router)->generate();

        self::assertSame([['bearerToken' => []]], $document['paths']['/me']['get']['security']);
        self::assertSame(
            BearerAuthMiddleware::openApiSecurity()->schemes,
            $document['components']['securitySchemes'],
        );
    }

    public function test_a_route_reaching_the_middleware_through_a_group_is_documented_identically(): void
    {
        $router = new Router();
        $router->register(GroupedFixtureController::class);

        // GroupedBearerAuthMiddleware declares no description of its
        // own; it inherits this one along with the behavior.
        $document = new OpenApiGenerator(
            $router,
            middlewareGroups: ['api' => [GroupedBearerAuthMiddleware::class]],
        )->generate();

        self::assertSame([['bearerToken' => []]], $document['paths']['/grouped']['get']['security']);
    }
}
