<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi;

use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiDocumentProvider;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Http\Fixtures\NoteController;
use Kinetis\Tests\Http\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

/**
 * A provider is bound to the Router it was constructed with, and a
 * changed Router stands in here for what a deployment actually is: a new
 * process building a new Router and a new provider around it.
 */
final class OpenApiDocumentProviderTest extends TestCase
{
    private static function router(): Router
    {
        $router = new Router();
        $router->register(UserController::class);

        return $router;
    }

    public function test_production_generates_once_per_provider(): void
    {
        $router = self::router();
        $provider = new OpenApiDocumentProvider($router, AppEnvironment::Production);

        $first = $provider->document();
        $router->register(NoteController::class);

        self::assertSame($first, $provider->document());
        self::assertArrayNotHasKey('/notes', $provider->document()['paths']);
    }

    public function test_a_new_production_provider_describes_the_router_it_was_given(): void
    {
        $router = self::router();
        new OpenApiDocumentProvider($router, AppEnvironment::Production)->document();

        $router->register(NoteController::class);

        $document = new OpenApiDocumentProvider($router, AppEnvironment::Production)->document();

        self::assertArrayHasKey('/notes', $document['paths']);
        self::assertArrayHasKey('/users', $document['paths']);
    }

    public function test_development_generates_on_every_call(): void
    {
        $router = self::router();
        $provider = new OpenApiDocumentProvider($router, AppEnvironment::Development);

        $provider->document();
        $router->register(NoteController::class);

        self::assertArrayHasKey('/notes', $provider->document()['paths']);
    }
}
