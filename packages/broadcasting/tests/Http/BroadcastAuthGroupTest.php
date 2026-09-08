<?php

declare(strict_types=1);

namespace Kinetis\Broadcasting\Tests\Http;

use Kinetis\Broadcasting\BroadcasterInterface;
use Kinetis\Broadcasting\BroadcastChannelRegistry;
use Kinetis\Broadcasting\Driver\PusherBroadcaster;
use Kinetis\Broadcasting\Http\BroadcastAuthController;
use Kinetis\Broadcasting\Http\BroadcastOriginMiddleware;
use Kinetis\Broadcasting\Tests\Fixtures\GroupProject\ApplicationAuthMiddleware;
use Kinetis\Broadcasting\Tests\Fixtures\GroupProject\AuthAttemptLog;
use Kinetis\Broadcasting\Tests\Fixtures\OrderChannelAuthorizer;
use Kinetis\Broadcasting\Tests\Fixtures\TrackedChannelAuthorizer;
use Kinetis\Cache\CacheStore;
use Kinetis\Cache\Compiler;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\GlobalMiddlewareDiscovery;
use Kinetis\Http\Routing\Router;
use Kinetis\RevoltHttpClient\Http;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The `broadcasting` middleware group through a real Kernel: what an
 * application's own authentication middleware reaches by joining it,
 * what an anonymous authorizer gets without one, and where
 * BroadcastOriginMiddleware settles a request before either runs.
 *
 * OrderChannelAuthorizer carries both cases — `orders.{orderId}` takes a
 * CurrentUserInterface, `lobby` takes nothing — so one registry serves
 * the identity tests and the anonymous ones.
 */
final class BroadcastAuthGroupTest extends TestCase
{
    private const string KEY = 'testkey';

    private const string SECRET = 'testsecret';

    private const string ORIGIN = 'https://app.example';

    private const string PROJECT_ROOT = __DIR__ . '/../Fixtures/GroupProject';

    private AuthAttemptLog $authLog;

    private TrackedChannelAuthorizer $tracked;

    private string $cacheDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->authLog = new AuthAttemptLog();
        $this->tracked = new TrackedChannelAuthorizer();
        $this->cacheDirectory = sys_get_temp_dir() . '/kinetis_broadcasting_group_' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->cacheDirectory . '/' . CacheStore::ARTIFACT_FILENAME);
        @rmdir($this->cacheDirectory);
    }

    /**
     * Live discovery against this package's own `src/`, reached the way
     * an installed package is: the fixture project declares
     * `kinetis/broadcasting` in its `vendor/composer/installed.json` and
     * contributes one middleware of its own at the attribute's default
     * priority, which the origin guard's 100 outranks.
     */
    public function test_discovery_orders_the_group_origin_first_then_application_auth(): void
    {
        self::assertSame(
            [BroadcastOriginMiddleware::class, ApplicationAuthMiddleware::class],
            $this->discoveredGroup(),
        );
    }

    /**
     * The point of the group: an authorizer declaring
     * CurrentUserInterface authorizes because a group member published
     * an identity on this request's own scope.
     */
    public function test_an_identity_published_by_a_group_member_reaches_a_current_user_authorizer(): void
    {
        $response = $this->kernel($this->discoveredGroup())->handle(
            $this->authRequest('private-orders.42')->withHeader(ApplicationAuthMiddleware::TOKEN_HEADER, 'valid'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->signature('private-orders.42'), $this->decode($response)['auth'] ?? null);
        self::assertSame(1, $this->authLog->attempts);
    }

    /**
     * An install whose authorizers are all anonymous adds no middleware:
     * the group holds this package's origin guard alone, and the
     * endpoint authorizes without any identity.
     */
    public function test_an_anonymous_authorizer_works_with_only_the_packages_own_group_member(): void
    {
        $response = $this->kernel([BroadcastOriginMiddleware::class])->handle($this->authRequest('private-lobby'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->signature('private-lobby'), $this->decode($response)['auth'] ?? null);
    }

    /**
     * @return iterable<string, array{string, ?string, string, int}>
     */
    public static function origins(): iterable
    {
        $endpoint = self::ORIGIN . '/broadcasting/auth';
        $ported = 'http://app.example:8080/broadcasting/auth';

        yield 'no Origin header' => [$endpoint, null, '', 200];
        yield 'the request\'s own origin' => [$endpoint, self::ORIGIN, '', 200];
        yield 'a configured origin' => [$endpoint, 'https://spa.example', 'https://other.example, https://spa.example', 200];
        yield 'the default port on neither side' => [self::ORIGIN . ':443/broadcasting/auth', self::ORIGIN, '', 200];
        yield 'a non-default port on both sides' => [$ported, 'http://app.example:8080', '', 200];
        yield 'an unconfigured origin' => [$endpoint, 'https://evil.example', '', 403];
        yield 'the same authority on another scheme' => [$endpoint, 'http://app.example', '', 403];
        yield 'the same authority without its port' => [$ported, 'http://app.example', '', 403];
    }

    #[DataProvider('origins')]
    public function test_the_origin_guard_compares_exact_strings(
        string $uri,
        ?string $origin,
        string $allowed,
        int $expected,
    ): void {
        $kernel = $this->kernel($this->discoveredGroup(), ['BROADCAST_ALLOWED_ORIGINS' => $allowed]);
        $request = $this->authRequest('private-lobby', $uri);

        $response = $kernel->handle($origin === null ? $request : $request->withHeader('Origin', $origin));

        self::assertSame($expected, $response->getStatusCode());
    }

    /**
     * The refusal is settled before the rest of the group: the
     * application's authentication middleware never ran and the channel
     * authorizer was never called.
     */
    public function test_a_disallowed_origin_is_refused_before_auth_or_the_controller_runs(): void
    {
        $response = $this->kernel($this->discoveredGroup())->handle(
            $this->authRequest('private-tracked.9')
                ->withHeader('Origin', 'https://evil.example')
                ->withHeader(ApplicationAuthMiddleware::TOKEN_HEADER, 'valid'),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->authLog->attempts);
        self::assertSame(0, $this->tracked->calls);
    }

    /**
     * Production boot: the fixture project compiled and published as the
     * real `compiled.php` artifact, then read back. Both members reach
     * it in order, the route's own `@broadcasting` reference survives
     * with them, and the reconstructed group serves a request — so a
     * cached boot resolves the same identity a discovered one does.
     */
    public function test_the_compiled_artifact_carries_the_group_and_serves_the_route(): void
    {
        $store = new CacheStore($this->cacheDirectory);
        $store->write(new Compiler()->compileProject(self::PROJECT_ROOT));

        $http = $store->load()?->http;
        self::assertNotNull($http);
        self::assertSame(
            [BroadcastOriginMiddleware::class, ApplicationAuthMiddleware::class],
            $http->middlewareGroups['broadcasting'] ?? null,
        );

        $kernel = new Kernel(
            $this->app(),
            Router::fromArray($http->routes),
            httpCache: $http,
            middlewareGroups: $http->middlewareGroups,
        );

        $response = $kernel->handle(
            $this->authRequest('private-orders.42')->withHeader(ApplicationAuthMiddleware::TOKEN_HEADER, 'valid'),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($this->signature('private-orders.42'), $this->decode($response)['auth'] ?? null);
    }

    /**
     * @return list<class-string>
     */
    private function discoveredGroup(): array
    {
        return GlobalMiddlewareDiscovery::discoverAll(self::PROJECT_ROOT)['groups']['broadcasting'];
    }

    /**
     * @param list<class-string> $group
     * @param array<string, string> $config
     */
    private function kernel(array $group, array $config = []): Kernel
    {
        $router = new Router();
        $router->register(BroadcastAuthController::class);

        return new Kernel($this->app($config), $router, middlewareGroups: ['broadcasting' => $group]);
    }

    /**
     * @param array<string, string> $config
     */
    private function app(array $config = []): AppScope
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config($config));

        $registry = new BroadcastChannelRegistry();
        $registry->register(OrderChannelAuthorizer::class);
        $registry->register(TrackedChannelAuthorizer::class);
        $app->instance(BroadcastChannelRegistry::class, $registry);
        $app->instance(TrackedChannelAuthorizer::class, $this->tracked);
        $app->instance(AuthAttemptLog::class, $this->authLog);
        $app->instance(
            BroadcasterInterface::class,
            new PusherBroadcaster(new Http(new MockHttpClient()), '12345', self::KEY, self::SECRET),
        );
        $app->boot();

        return $app;
    }

    private function authRequest(string $channel, string $uri = self::ORIGIN . '/broadcasting/auth'): ServerRequest
    {
        return new ServerRequest(
            'POST',
            $uri,
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            body: http_build_query(['socket_id' => '1234.1234', 'channel_name' => $channel]),
        );
    }

    private function signature(string $channel): string
    {
        return self::KEY . ':' . hash_hmac('sha256', "1234.1234:{$channel}", self::SECRET);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), associative: true);

        return $decoded;
    }
}
