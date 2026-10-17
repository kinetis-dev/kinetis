<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\OpenApi\OpenApiAccess;
use Kinetis\Testing\TestApplication;
use Kinetis\Tests\Http\Fixtures\DenyingOpenApiMiddleware;
use Kinetis\Tests\Http\Fixtures\DocumentationPathPostController;
use Kinetis\Tests\Http\Fixtures\RecordingMiddleware;
use Kinetis\Tests\Http\Fixtures\UserController;
use Kinetis\Tests\Http\Fixtures\VersionedMiddleware;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Closed documentation is a route table without the documentation
 * routes, so every method on either path answers exactly as a path that
 * was never registered — no 405 and `Allow` header that confirms the
 * route exists, and no `openapi` group middleware that could answer
 * first. Kernels here come from TestApplication, which applies the
 * configured access the way HttpStartup does.
 */
final class DocumentationAccessTest extends TestCase
{
    private const array CLOSED = ['APP_ENV' => 'production'];

    private const array OPEN = ['APP_ENV' => 'production', 'OPENAPI_ENVIRONMENTS' => 'production'];

    private const string UNREGISTERED = '/never-registered';

    /**
     * @param array<string, string> $config
     */
    private static function app(array $config): AppScope
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));

        return $app;
    }

    private static function router(string ...$controllers): Router
    {
        $router = new Router();
        $router->register(UserController::class);

        foreach ($controllers as $controller) {
            $router->register($controller);
        }

        $router->register(DocumentationController::class);

        return $router;
    }

    /**
     * @param array<string, string> $config
     */
    private static function kernel(array $config, ?Router $router = null): Kernel
    {
        return TestApplication::withRouter($router ?? self::router(), self::app($config))->kernel;
    }

    private static function assertSameAsUnregistered(Kernel $kernel, string $method, string $path): void
    {
        $closed = $kernel->handle(new ServerRequest($method, $path));
        $absent = $kernel->handle(new ServerRequest($method, self::UNREGISTERED));
        $label = "{$method} {$path}";

        self::assertSame(404, $closed->getStatusCode(), $label);
        self::assertFalse($closed->hasHeader('Allow'), $label);
        self::assertSame(self::comparableHeaders($absent), self::comparableHeaders($closed), $label);
        self::assertSame(
            str_replace(
                // Escaped first: the raw path is a substring of it.
                [self::jsonEscaped(self::UNREGISTERED), self::UNREGISTERED],
                [self::jsonEscaped($path), $path],
                (string) $absent->getBody(),
            ),
            (string) $closed->getBody(),
            $label,
        );
    }

    private static function jsonEscaped(string $path): string
    {
        return trim(json_encode($path, JSON_THROW_ON_ERROR), '"');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function comparableHeaders(ResponseInterface $response): array
    {
        $headers = $response->getHeaders();
        // The body names the requested path, so its length differs by
        // exactly the difference between the two paths.
        unset($headers['Content-Length']);
        ksort($headers);

        return $headers;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('methods')]
    public function test_a_closed_documentation_path_answers_as_an_unregistered_one(string $method): void
    {
        $kernel = self::kernel(self::CLOSED);

        foreach (['/openapi.json', '/openapi'] as $path) {
            self::assertSameAsUnregistered($kernel, $method, $path);
        }
    }

    #[DataProvider('methods')]
    public function test_a_closed_documentation_path_behind_a_global_route_prefix_answers_as_an_unregistered_one(string $method): void
    {
        $router = new Router();
        $router->register(UserController::class, [VersionedMiddleware::class]);
        $router->register(DocumentationController::class, [VersionedMiddleware::class]);
        $app = self::app(self::CLOSED);
        $app->middleware(VersionedMiddleware::class);

        $kernel = TestApplication::withRouter($router, $app)->kernel;

        foreach (['/v1/openapi.json', '/v1/openapi'] as $path) {
            self::assertSameAsUnregistered($kernel, $method, $path);
        }
    }

    #[DataProvider('methods')]
    public function test_a_closed_documentation_path_from_a_compiled_route_table_answers_as_an_unregistered_one(string $method): void
    {
        $kernel = self::kernel(self::CLOSED, Router::fromArray(self::router()->toArray()));

        foreach (['/openapi.json', '/openapi'] as $path) {
            self::assertSameAsUnregistered($kernel, $method, $path);
        }
    }

    public function test_explicit_openapi_middleware_never_runs_for_closed_documentation(): void
    {
        $app = self::app(self::CLOSED);
        $app->openApiMiddleware(DenyingOpenApiMiddleware::class);
        $kernel = TestApplication::withRouter(self::router(), $app)->kernel;

        RecordingMiddleware::$log = [];

        self::assertSame(404, $kernel->handle(new ServerRequest('GET', '/openapi.json'))->getStatusCode());
        self::assertSame([], RecordingMiddleware::$log);
    }

    /**
     * The open case is what shows the middleware is wired to these
     * routes at all, so the closed assertions cannot pass vacuously.
     */
    public function test_discovered_openapi_middleware_runs_only_for_open_documentation(): void
    {
        foreach ([self::CLOSED, self::OPEN] as $config) {
            $app = self::app($config);
            $app->boot();
            $kernel = new Kernel(
                $app,
                OpenApiAccess::fromConfig(new Config($config))->routes(self::router()),
                discoveredOpenApiMiddleware: [DenyingOpenApiMiddleware::class],
            );

            RecordingMiddleware::$log = [];
            $response = $kernel->handle(new ServerRequest('GET', '/openapi'));

            if ($config === self::OPEN) {
                self::assertSame(401, $response->getStatusCode());
                self::assertSame([DenyingOpenApiMiddleware::class], RecordingMiddleware::$log);
            } else {
                self::assertSame(404, $response->getStatusCode());
                self::assertSame([], RecordingMiddleware::$log);
            }
        }
    }

    public function test_open_documentation_serves_get_and_names_it_for_any_other_method(): void
    {
        $kernel = self::kernel(self::OPEN);

        self::assertSame(200, $kernel->handle(new ServerRequest('GET', '/openapi.json'))->getStatusCode());
        self::assertSame(200, $kernel->handle(new ServerRequest('GET', '/openapi'))->getStatusCode());

        $post = $kernel->handle(new ServerRequest('POST', '/openapi.json'));
        self::assertSame(405, $post->getStatusCode());
        self::assertSame('GET', $post->getHeaderLine('Allow'));
    }

    public function test_an_application_route_on_a_documentation_path_is_served_open_or_closed(): void
    {
        $open = self::kernel(self::OPEN, self::router(DocumentationPathPostController::class));
        $closed = self::kernel(self::CLOSED, self::router(DocumentationPathPostController::class));

        foreach ([$open, $closed] as $kernel) {
            $response = $kernel->handle(new ServerRequest('POST', '/openapi.json'));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(['stored' => true], json_decode((string) $response->getBody(), true));
        }

        self::assertSame(200, $open->handle(new ServerRequest('GET', '/openapi.json'))->getStatusCode());

        // The application's own route still exists, so its method is the
        // only one named.
        $get = $closed->handle(new ServerRequest('GET', '/openapi.json'));
        self::assertSame(405, $get->getStatusCode());
        self::assertSame('POST', $get->getHeaderLine('Allow'));
    }
}
