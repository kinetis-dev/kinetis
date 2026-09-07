<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Http\Fixtures\NoteController;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Every case goes through a real Kernel: the routes are ordinary
 * discovered routes, so anything that only exercised the controller
 * directly would skip the dispatch it depends on — including the
 * OpenApiDocumentProvider the Kernel builds for its own Router.
 *
 * Registering a controller on a Router a Kernel already holds stands in
 * for a route table that changed; a second Kernel over that same Router
 * stands in for the deployment that would really produce one.
 */
final class DocumentationControllerTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function kernel(array $config, Router $router): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        // boot() detects the environment from the real process
        // environment, which an array-built Config cannot reach — the
        // same registration TestApplication makes for its own overrides.
        $app->instance(AppEnvironment::class, AppEnvironment::detect($config['APP_ENV'] ?? null));
        $app->boot();

        return new Kernel($app, $router);
    }

    private static function router(): Router
    {
        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        return $router;
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private static function config(string $environment, array $extra = []): array
    {
        return [
            'APP_ENV' => $environment,
            'OPENAPI_ENVIRONMENTS' => $environment,
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);

        self::assertIsArray($document);

        return $document;
    }

    public function test_development_regenerates_the_document_on_every_request(): void
    {
        $router = self::router();
        $kernel = $this->kernel(self::config('development'), $router);

        $first = self::decode($kernel->handle(new ServerRequest('GET', '/openapi.json')));
        self::assertArrayHasKey('/users', $first['paths']);

        $router->register(NoteController::class);

        $second = self::decode($kernel->handle(new ServerRequest('GET', '/openapi.json')));
        self::assertArrayHasKey('/notes', $second['paths']);
    }

    public function test_production_serves_one_document_for_the_life_of_a_kernel(): void
    {
        $router = self::router();
        $kernel = $this->kernel(self::config('production'), $router);

        $first = self::decode($kernel->handle(new ServerRequest('GET', '/openapi.json')));

        $router->register(NoteController::class);

        $second = self::decode($kernel->handle(new ServerRequest('GET', '/openapi.json')));

        self::assertSame($first, $second);
        self::assertArrayNotHasKey('/notes', $second['paths']);
    }

    /**
     * What a deployment does: nothing is cleared, because the new
     * process's Kernel carries a provider tied to its own Router.
     */
    public function test_a_new_kernel_serves_the_route_table_it_was_built_with(): void
    {
        $router = self::router();
        $this->kernel(self::config('production'), $router)->handle(new ServerRequest('GET', '/openapi.json'));

        $router->register(NoteController::class);

        $document = self::decode(
            $this->kernel(self::config('production'), $router)->handle(new ServerRequest('GET', '/openapi.json')),
        );

        self::assertArrayHasKey('/notes', $document['paths']);
        self::assertArrayHasKey('/users', $document['paths']);
    }

    public function test_both_paths_are_closed_when_no_environment_names_them(): void
    {
        $kernel = $this->kernel(['APP_ENV' => 'production'], self::router());

        foreach (['/openapi.json', '/openapi'] as $path) {
            $response = $kernel->handle(new ServerRequest('GET', $path));

            self::assertSame(404, $response->getStatusCode(), $path);
            // Byte-identical to an unregistered path: a closed endpoint
            // that answered differently would confirm it exists.
            self::assertSame(
                ['error' => sprintf('No route matches path "%s".', $path)],
                self::decode($response),
            );
        }
    }

    public function test_the_routes_are_absent_from_the_document_they_produce(): void
    {
        $kernel = $this->kernel(self::config('development'), self::router());

        $document = self::decode($kernel->handle(new ServerRequest('GET', '/openapi.json')));

        self::assertArrayNotHasKey('/openapi.json', $document['paths']);
        self::assertArrayNotHasKey('/openapi', $document['paths']);
    }
}
