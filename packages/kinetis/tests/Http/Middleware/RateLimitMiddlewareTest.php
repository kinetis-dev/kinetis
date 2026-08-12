<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Container\AppScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\RateLimitMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Http\Fixtures\RateLimitedFixtureController;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;

final class RateLimitMiddlewareTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private function request(string $ip = '127.0.0.1', array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', '/', headers: $headers, serverParams: ['REMOTE_ADDR' => $ip]);
    }

    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    public function test_a_request_under_the_limit_passes_through_with_rate_limit_headers(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 2, windowSeconds: 60);

        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test_remaining_decreases_with_each_request_in_the_same_window(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 2, windowSeconds: 60);

        $first = $middleware->process($this->request(), $this->handler());
        $second = $middleware->process($this->request(), $this->handler());

        self::assertSame('1', $first->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('0', $second->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test_a_request_at_the_limit_is_rejected_with_429(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 2, windowSeconds: 60);

        $middleware->process($this->request(), $this->handler());
        $middleware->process($this->request(), $this->handler());
        $third = $middleware->process($this->request(), $this->handler());

        self::assertSame(429, $third->getStatusCode());
        self::assertSame('application/json', $third->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Too many requests.'], json_decode((string) $third->getBody(), true));
        self::assertSame('0', $third->getHeaderLine('X-RateLimit-Remaining'));
        self::assertNotSame('', $third->getHeaderLine('Retry-After'));
    }

    public function test_the_inner_handler_never_runs_once_the_limit_is_reached(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 1, windowSeconds: 60);
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });

        $middleware->process($this->request(), $handler);
        $middleware->process($this->request(), $handler);

        self::assertSame(1, $calls);
    }

    public function test_different_identifiers_get_independent_buckets(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('127.0.0.1'), $this->handler());
        $second = $middleware->process($this->request('192.168.1.1'), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_an_ipv6_identifier_does_not_break_the_cache_key(): void
    {
        // The whole reason the identifier is sha256-hashed before use: PSR-16
        // forbids ":" in a key, and a bare IPv6 address is full of them.
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 1, windowSeconds: 60);

        $response = $middleware->process($this->request('2001:db8::1'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_request_after_the_window_resets_is_allowed_again(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 1, windowSeconds: 1);

        $middleware->process($this->request(), $this->handler());
        $rejected = $middleware->process($this->request(), $this->handler());

        self::assertSame(429, $rejected->getStatusCode());

        sleep(2);

        $allowedAgain = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $allowedAgain->getStatusCode());
    }

    public function test_works_as_global_middleware_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySimpleCache());
        $app->middleware(RateLimitMiddleware::class);
        $app->bind(RateLimitMiddleware::class, static fn ($c) => new RateLimitMiddleware(
            $c->get(CacheInterface::class),
            maxAttempts: 1,
            windowSeconds: 60,
        ));
        $app->boot();

        $router = new Router();
        $kernel = new Kernel($app, $router);

        $first = $kernel->handle(new ServerRequest('GET', '/nonexistent', serverParams: ['REMOTE_ADDR' => '127.0.0.1']));
        $second = $kernel->handle(new ServerRequest('GET', '/nonexistent', serverParams: ['REMOTE_ADDR' => '127.0.0.1']));

        // The first request 404s (no route registered) — proving global
        // middleware wraps routing failures too, not just successful
        // dispatches — and the second is rejected by the rate limiter
        // before routing even runs again.
        self::assertSame(404, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_a_subclass_can_override_the_constructor_defaults_for_a_stricter_limit(): void
    {
        $middleware = new class (new InMemorySimpleCache()) extends RateLimitMiddleware {
            public function __construct(CacheInterface $cache)
            {
                parent::__construct($cache, maxAttempts: 1, windowSeconds: 60);
            }
        };

        $first = $middleware->process($this->request(), $this->handler());
        $second = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_works_as_route_middleware_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySimpleCache());
        $app->bind(RateLimitMiddleware::class, static fn ($c) => new RateLimitMiddleware(
            $c->get(CacheInterface::class),
            maxAttempts: 1,
            windowSeconds: 60,
        ));
        $app->boot();

        $router = new Router();
        $router->register(RateLimitedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $first = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: ['REMOTE_ADDR' => '127.0.0.1']));
        $second = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: ['REMOTE_ADDR' => '127.0.0.1']));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_a_subclass_can_override_the_identifier_used_for_keying(): void
    {
        // identifierFor() is protected, not private, specifically so a
        // subclass's override actually takes effect when cacheKey() (still
        // defined on the parent) calls it — a real, previously-unexercised
        // constraint: a private method binds statically to its defining
        // class regardless of subclassing.
        $middleware = new class (new InMemorySimpleCache()) extends RateLimitMiddleware {
            public function __construct(CacheInterface $cache)
            {
                parent::__construct($cache, maxAttempts: 1, windowSeconds: 60);
            }

            protected function identifierFor(ServerRequestInterface $request): string
            {
                return 'fixed-key-regardless-of-request';
            }
        };

        $first = $middleware->process($this->request('127.0.0.1'), $this->handler());
        $second = $middleware->process($this->request('192.168.1.1'), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_x_forwarded_for_is_ignored_when_remote_addr_is_not_a_trusted_proxy(): void
    {
        // No trustedProxies configured — two requests through the same
        // (untrusted) REMOTE_ADDR share one bucket regardless of what
        // X-Forwarded-For claims, since a client can set that header to
        // anything it likes.
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('203.0.113.1', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());
        $second = $middleware->process($this->request('203.0.113.1', ['X-Forwarded-For' => '2.2.2.2']), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_x_forwarded_for_is_honored_when_remote_addr_is_a_trusted_proxy(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['10.0.0.0/8'],
        );

        $first = $middleware->process($this->request('10.0.0.1', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());
        $second = $middleware->process($this->request('10.0.0.1', ['X-Forwarded-For' => '2.2.2.2']), $this->handler());

        // Both come through the same trusted proxy but claim different
        // real clients — independent buckets, proving X-Forwarded-For is
        // actually being read once the proxy is trusted.
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_x_forwarded_for_chain_is_walked_skipping_trusted_hops(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['10.0.0.0/8'],
        );

        // REMOTE_ADDR (10.0.0.2) is the nearest trusted hop; the chain's
        // last entry (10.0.0.1) is also trusted, so the real client is the
        // first untrusted entry walking backward: 9.9.9.9.
        $request = $this->request('10.0.0.2', ['X-Forwarded-For' => '9.9.9.9, 10.0.0.1']);
        $first = $middleware->process($request, $this->handler());
        $second = $middleware->process($request, $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_an_ipv6_cidr_trusted_proxy_matches(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['2001:db8::/32'],
        );

        $first = $middleware->process($this->request('2001:db8::1', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());
        $second = $middleware->process($this->request('2001:db8::1', ['X-Forwarded-For' => '2.2.2.2']), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_a_bare_ip_with_no_cidr_suffix_matches_only_that_exact_address(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['10.0.0.1'],
        );

        $trusted = $middleware->process($this->request('10.0.0.1', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());
        $untrusted = $middleware->process($this->request('10.0.0.2', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());

        // 10.0.0.1's forwarded client (1.1.1.1) is a fresh bucket (200);
        // 10.0.0.2 is not the trusted address, so it's keyed by its own
        // REMOTE_ADDR — also a fresh bucket, not colliding with 1.1.1.1's.
        self::assertSame(200, $trusted->getStatusCode());
        self::assertSame(200, $untrusted->getStatusCode());
    }
}
