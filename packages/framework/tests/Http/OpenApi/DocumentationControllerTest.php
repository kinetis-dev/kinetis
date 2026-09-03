<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\OpenApi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\OpenApi\DocumentationController;
use Kinetis\Http\Routing\Router;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException;
use Kinetis\SimpleCache\UnavailableSimpleCache;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Fixtures\ThrowingLogger;
use Kinetis\Tests\Fixtures\WriteFailingSimpleCache;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

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

    /**
     * The exact shape a configured-but-unreachable Redis produces, and
     * the one an application that set REDIS_HOST without installing
     * kinetis/cache-redis gets. The document can always be regenerated,
     * so a broken cache must not take the endpoint down with it.
     *
     * UnavailableSimpleCache fails every operation, so this single
     * request genuinely reaches *both* cached()'s and store()'s own
     * catch — cached() catches the failed get() and returns null, so
     * generate() falls through to generating fresh and calling store(),
     * whose own set() then fails too. Both warnings are asserted
     * precisely, in the deterministic order they're produced (the read
     * warning first): this is what "both cache catch sites remain
     * observable when logging works" actually means for this one
     * fixture, not merely that at least one record exists.
     */
    public function test_a_failing_cache_degrades_to_generating_rather_than_failing_the_request(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(self::config('production')));
        $app->instance(CacheInterface::class, new UnavailableSimpleCache());
        $app->instance(AppEnvironment::class, AppEnvironment::Production);
        $logger = new InMemoryLogger();
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        $response = new Kernel($app, $router)->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('/users', $document['paths']);

        // Degraded, not silent — and precisely, not just non-empty.
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Could not read the cached OpenAPI document; generating it instead.', $logger->records[0]['message']);
        self::assertInstanceOf(SimpleCacheUnavailableException::class, $logger->records[0]['context']['exception']);
        self::assertSame('warning', $logger->records[1]['level']);
        self::assertSame('Could not cache the OpenAPI document; it will be regenerated per request.', $logger->records[1]['message']);
        self::assertInstanceOf(SimpleCacheUnavailableException::class, $logger->records[1]['context']['exception']);
    }

    /**
     * The write catch in isolation, unlike the dual-failure fixture
     * above: WriteFailingSimpleCache reads cleanly (a miss), so exactly
     * one record — the write warning — is ever produced, with nothing
     * to disambiguate from a read warning by index.
     */
    public function test_a_failing_cache_write_is_logged_precisely_when_the_logger_is_healthy(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(self::config('production')));
        $app->instance(CacheInterface::class, new WriteFailingSimpleCache());
        $app->instance(AppEnvironment::class, AppEnvironment::Production);
        $logger = new InMemoryLogger();
        $app->instance(LoggerInterface::class, $logger);
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        $response = new Kernel($app, $router)->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('/users', $document['paths']);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Could not cache the OpenAPI document; it will be regenerated per request.', $logger->records[0]['message']);
        self::assertInstanceOf(RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    /**
     * The same recoverable-cache-outage fallback as above, but with a
     * logger that itself throws while cached() tries to report the
     * read failure — SafeLogger must keep that from turning a
     * recoverable outage into an unrecoverable one.
     */
    public function test_a_failing_cache_read_with_a_throwing_logger_still_regenerates_and_returns_200(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(self::config('production')));
        $app->instance(CacheInterface::class, new UnavailableSimpleCache());
        $app->instance(AppEnvironment::class, AppEnvironment::Production);
        $app->instance(LoggerInterface::class, new ThrowingLogger());
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        $response = new Kernel($app, $router)->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('/users', $document['paths']);
    }

    /**
     * The write side of the same guarantee, isolated from the read side:
     * a cache that reads cleanly (a miss) but fails to store what was
     * just generated must still return that generated document, even
     * though the warning about the failed write cannot be logged either.
     */
    public function test_a_failing_cache_write_with_a_throwing_logger_still_returns_the_generated_document(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(self::config('production')));
        $app->instance(CacheInterface::class, new WriteFailingSimpleCache());
        $app->instance(AppEnvironment::class, AppEnvironment::Production);
        $app->instance(LoggerInterface::class, new ThrowingLogger());
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $router->register(DocumentationController::class);

        $response = new Kernel($app, $router)->handle(new ServerRequest('GET', '/openapi.json'));

        self::assertSame(200, $response->getStatusCode());
        $response->getBody()->rewind();
        $document = json_decode($response->getBody()->getContents(), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('/users', $document['paths']);
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
