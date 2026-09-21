<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests;

use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\GroupedFixtureController;
use Kinetis\AuthJwt\Tests\Fixtures\GroupedJwtAuthMiddleware;
use Kinetis\AuthJwt\Tests\Fixtures\ProtectedFixtureController;
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
    public function test_the_middleware_declares_an_http_bearer_scheme_carrying_a_jwt(): void
    {
        $description = JwtAuthMiddleware::openApiSecurity();

        self::assertSame(
            [
                'bearerJwt' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'A signed JWT, sent as "Authorization: Bearer <token>".',
                ],
            ],
            $description->schemes,
        );
        self::assertSame([['bearerJwt' => []]], $description->requirements);
    }

    public function test_a_protected_route_is_documented_as_requiring_that_scheme(): void
    {
        $router = new Router();
        $router->register(ProtectedFixtureController::class);

        $document = new OpenApiGenerator($router)->generate();

        self::assertSame([['bearerJwt' => []]], $document['paths']['/me']['get']['security']);
        self::assertSame(
            JwtAuthMiddleware::openApiSecurity()->schemes,
            $document['components']['securitySchemes'],
        );
    }

    public function test_a_route_reaching_the_middleware_through_a_group_is_documented_identically(): void
    {
        $router = new Router();
        $router->register(GroupedFixtureController::class);

        // GroupedJwtAuthMiddleware declares no description of its own;
        // it inherits this one along with the behavior.
        $document = new OpenApiGenerator(
            $router,
            middlewareGroups: ['jwt' => [GroupedJwtAuthMiddleware::class]],
        )->generate();

        self::assertSame([['bearerJwt' => []]], $document['paths']['/grouped']['get']['security']);
    }
}
