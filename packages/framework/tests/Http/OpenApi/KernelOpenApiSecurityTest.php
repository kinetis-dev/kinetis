<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;
use Kinetis\Tests\OpenApi\Fixtures\GroupedTokenAuthMiddleware;
use Kinetis\Tests\OpenApi\Fixtures\GroupSecuredController;
use Kinetis\Tests\OpenApi\Fixtures\SecuredController;
use Kinetis\Tests\OpenApi\Fixtures\TokenAuthMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * The document as a running Kernel serves it: the global pipeline it
 * dispatches through is the one the root security is read from, a group
 * reaches the document exactly as it reaches dispatch, and the JSON on
 * the wire says what was composed.
 */
final class KernelOpenApiSecurityTest extends TestCase
{
    /**
     * @param list<class-string> $globalMiddleware
     * @param array<string, list<class-string>> $groups
     */
    private static function kernel(Router $router, array $globalMiddleware = [], array $groups = []): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['APP_ENV' => 'development']));
        $app->instance(AppEnvironment::class, AppEnvironment::Development);
        $app->boot();

        return new Kernel(
            $app,
            $router,
            exposeOpenApi: true,
            discoveredGlobalMiddleware: $globalMiddleware,
            middlewareGroups: $groups,
        );
    }

    private static function router(string ...$controllers): Router
    {
        $router = new Router();

        foreach ($controllers as $controller) {
            $router->register($controller);
        }

        $router->register(DocumentationController::class);

        return $router;
    }

    private static function document(Kernel $kernel): string
    {
        return (string) $kernel->handle(new ServerRequest('GET', '/openapi.json'))->getBody();
    }

    public function test_a_group_reaches_the_document_exactly_as_it_reaches_dispatch(): void
    {
        $kernel = self::kernel(
            self::router(GroupSecuredController::class),
            groups: ['secure' => [GroupedTokenAuthMiddleware::class]],
        );

        RecordingMiddleware::$log = [];
        $response = $kernel->handle(new ServerRequest('GET', '/group/secured'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([GroupedTokenAuthMiddleware::class], RecordingMiddleware::$log);

        /** @var array<string, mixed> $document */
        $document = json_decode(self::document($kernel), true, flags: JSON_THROW_ON_ERROR);

        // The class that ran is the class that was described, and it
        // describes nothing of its own: the scheme is its parent's.
        self::assertSame([['token' => []]], $document['paths']['/group/secured']['get']['security']);
        self::assertSame(TokenAuthMiddleware::DEFINITION, $document['components']['securitySchemes']['token']);
    }

    public function test_the_global_pipeline_becomes_the_root_security(): void
    {
        $kernel = self::kernel(self::router(SecuredController::class), [TokenAuthMiddleware::class]);

        /** @var array<string, mixed> $document */
        $document = json_decode(self::document($kernel), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([['token' => []]], $document['security']);
        // The two documentation routes are hidden, and nothing about
        // them reaches the document.
        self::assertArrayNotHasKey('/openapi.json', $document['paths']);
    }

    public function test_a_declaration_without_providers_drops_the_root_security_on_the_wire(): void
    {
        $kernel = self::kernel(self::router(SecuredController::class), [TokenAuthMiddleware::class]);

        $document = self::document($kernel);

        // An empty list, not the empty object an anonymous alternative
        // would be, and not an absent key: it is what removes the root
        // security this operation would otherwise inherit.
        self::assertStringContainsString('"security":[]}', $document);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($document, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['paths']['/secured/anonymous']['get']['security']);
        self::assertSame([['token' => []]], $decoded['security']);
    }

    public function test_a_declaration_without_providers_leaves_the_routes_middleware_running(): void
    {
        $kernel = self::kernel(self::router(SecuredController::class));

        RecordingMiddleware::$log = [];
        $response = $kernel->handle(new ServerRequest('GET', '/secured/anonymous'));

        // The document says this operation needs no credential; the
        // pipeline is untouched, so what admits the request is the
        // middleware, never the attribute.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([TokenAuthMiddleware::class], RecordingMiddleware::$log);
    }

    public function test_the_anonymous_alternative_is_an_object_on_the_wire(): void
    {
        $kernel = self::kernel(self::router(SecuredController::class));

        self::assertStringContainsString('"security":[{"token":[]},{}]', self::document($kernel));
    }
}
