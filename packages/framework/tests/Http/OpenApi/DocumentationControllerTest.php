<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

/**
 * The document is generated per request in development and cached
 * indefinitely in production. Both go through a real Kernel: the routes
 * are ordinary discovered routes now, so anything that only exercised
 * the controller directly would skip the dispatch it depends on.
 */
final class DocumentationControllerTest extends TestCase
{
    /**
     * @param array<string, string> $config
     */
    private function kernel(array $config, InMemorySimpleCache $cache): Kernel
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));
        $app->instance(CacheInterface::class, $cache);
        // boot() detects the environment from the real process
        // environment, which an array-built Config cannot reach — the
        // same registration TestApplication makes for its own overrides.
        $app->instance(AppEnvironment::class, AppEnvironment::detect($config['APP_ENV'] ?? null));
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        return new Kernel($app, $router);
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

    public function test_development_generates_the_document_without_caching_it(): void
    {
        $cache = new InMemorySimpleCache();
        $kernel = $this->kernel(self::config('development'), $cache);

        $response = $kernel->handle(new ServerRequest('GET', '/openapi.json'));

        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($document);
        self::assertArrayHasKey('/users', $document['paths']);
        self::assertNull(
            $cache->get(DocumentationController::CACHE_KEY),
            'Development stored a document that a code change would immediately make wrong.',
        );
    }

    public function test_production_caches_the_document_and_serves_the_cached_one(): void
    {
        $cache = new InMemorySimpleCache();
        $kernel = $this->kernel(self::config('production'), $cache);

        $kernel->handle(new ServerRequest('GET', '/openapi.json'));

        $stored = $cache->get(DocumentationController::CACHE_KEY);
        self::assertIsArray($stored);

        // Proving the second request reads the cache rather than
        // regenerating: a value only present in the cache comes back out.
        $cache->set(DocumentationController::CACHE_KEY, ['openapi' => '3.1.0', 'paths' => ['/sentinel' => []]]);

        $second = $kernel->handle(new ServerRequest('GET', '/openapi.json'));
        $second->getBody()->rewind();
        $document = json_decode($second->getBody()->getContents(), true);

        self::assertIsArray($document);
        self::assertArrayHasKey('/sentinel', $document['paths']);
    }

    /**
     * No TTL at all: a document that expires on a timer would spend that
     * window describing an API the deployment no longer serves.
     */
    public function test_the_cached_document_is_stored_without_an_expiry(): void
    {
        $cache = new InMemorySimpleCache();
        $this->kernel(self::config('production'), $cache)->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertTrue($cache->has(DocumentationController::CACHE_KEY));
        self::assertNull($cache->expiresAt(DocumentationController::CACHE_KEY));
    }

    public function test_both_paths_are_closed_when_no_environment_names_them(): void
    {
        $cache = new InMemorySimpleCache();
        $kernel = $this->kernel(['APP_ENV' => 'production'], $cache);

        foreach (['/openapi.json', '/openapi'] as $path) {
            $response = $kernel->handle(new ServerRequest('GET', $path));

            self::assertSame(404, $response->getStatusCode(), $path);
            $response->getBody()->rewind();
            // Byte-identical to an unregistered path: a closed endpoint
            // that answered differently would confirm it exists.
            self::assertSame(
                ['error' => sprintf('No route matches path "%s".', $path)],
                json_decode($response->getBody()->getContents(), true),
            );
        }

        self::assertNull($cache->get(DocumentationController::CACHE_KEY));
    }

    public function test_the_routes_are_absent_from_the_document_they_produce(): void
    {
        $kernel = $this->kernel(self::config('development'), new InMemorySimpleCache());

        $response = $kernel->handle(new ServerRequest('GET', '/openapi.json'));
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);

        self::assertIsArray($document);
        self::assertArrayNotHasKey('/openapi.json', $document['paths']);
        self::assertArrayNotHasKey('/openapi', $document['paths']);
    }
}
