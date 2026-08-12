<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Http\Fixtures\AuthenticatedRateLimitedFixtureController;
use Kinetis\Tests\Http\Fixtures\FakeCurrentUser;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

final class AuthenticatedRateLimitMiddlewareTest extends TestCase
{
    private function scope(): RequestScope
    {
        $app = new AppScope();
        $app->boot();

        return $app->createRequestScope();
    }

    private function request(string $ip = '127.0.0.1'): ServerRequest
    {
        return new ServerRequest('GET', '/', serverParams: ['REMOTE_ADDR' => $ip]);
    }

    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    public function test_falls_back_to_ip_when_no_user_is_resolved(): void
    {
        $middleware = new AuthenticatedRateLimitMiddleware(new InMemorySimpleCache(), $this->scope(), maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('127.0.0.1'), $this->handler());
        $second = $middleware->process($this->request('127.0.0.1'), $this->handler());
        $thirdDifferentIp = $middleware->process($this->request('192.168.1.1'), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
        self::assertSame(200, $thirdDifferentIp->getStatusCode());
    }

    public function test_keys_by_the_resolved_user_instead_of_ip(): void
    {
        $scope = $this->scope();
        $scope->instance(CurrentUserInterface::class, new FakeCurrentUser('user-42'));
        $middleware = new AuthenticatedRateLimitMiddleware(new InMemorySimpleCache(), $scope, maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('127.0.0.1'), $this->handler());
        // Same resolved user, deliberately a different IP — proving the
        // bucket follows the user, not the address, once one is present.
        $second = $middleware->process($this->request('192.168.1.1'), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_different_users_get_independent_buckets_even_from_the_same_ip(): void
    {
        $cache = new InMemorySimpleCache();

        $scopeA = $this->scope();
        $scopeA->instance(CurrentUserInterface::class, new FakeCurrentUser('user-a'));
        $middlewareA = new AuthenticatedRateLimitMiddleware($cache, $scopeA, maxAttempts: 1, windowSeconds: 60);

        $scopeB = $this->scope();
        $scopeB->instance(CurrentUserInterface::class, new FakeCurrentUser('user-b'));
        $middlewareB = new AuthenticatedRateLimitMiddleware($cache, $scopeB, maxAttempts: 1, windowSeconds: 60);

        $responseA = $middlewareA->process($this->request('127.0.0.1'), $this->handler());
        $responseB = $middlewareB->process($this->request('127.0.0.1'), $this->handler());

        self::assertSame(200, $responseA->getStatusCode());
        self::assertSame(200, $responseB->getStatusCode());
    }

    public function test_the_rate_limit_headers_still_work(): void
    {
        $middleware = new AuthenticatedRateLimitMiddleware(new InMemorySimpleCache(), $this->scope(), maxAttempts: 2, windowSeconds: 60);

        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test_works_as_route_middleware_through_a_real_kernel_keyed_by_the_authenticated_user(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySimpleCache());
        $app->boot();

        $router = new Router();
        $router->register(AuthenticatedRateLimitedFixtureController::class);
        $kernel = new Kernel($app, $router);

        // CurrentUserMiddleware always resolves the same fixed user
        // ('user-42'), so varying REMOTE_ADDR between these two calls is
        // deliberate: if this were still IP-keyed, both would succeed.
        $first = $kernel->handle(new ServerRequest('GET', '/limited-by-user', serverParams: ['REMOTE_ADDR' => '127.0.0.1']));
        $second = $kernel->handle(new ServerRequest('GET', '/limited-by-user', serverParams: ['REMOTE_ADDR' => '192.168.1.1']));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }
}
