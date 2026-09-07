<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\Exception\InvalidRateLimitConfigException;
use Kinetis\Http\Middleware\Exception\RateLimitUnavailableException;
use Kinetis\Http\Middleware\RateLimitMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\SimpleCache\NullSimpleCache;
use Kinetis\SimpleCache\Exception\SimpleCacheUnavailableException;
use Kinetis\SimpleCache\UnavailableSimpleCache;
use Kinetis\Tests\Fixtures\InMemorySimpleCache;
use Kinetis\Tests\Fixtures\NonAtomicCache;
use Kinetis\Tests\Http\Fixtures\RateLimitedFixtureController;
use Kinetis\Tests\Http\Fixtures\SharedRateLimitMiddleware;
use Kinetis\Tests\Http\Fixtures\StrictRouteRateLimitedFixtureController;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClassConstant;
use ReflectionMethod;

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
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 2, windowSeconds: 60);

        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('2', $response->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('1', $response->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test_remaining_decreases_with_each_request_in_the_same_window(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 2, windowSeconds: 60);

        $first = $middleware->process($this->request(), $this->handler());
        $second = $middleware->process($this->request(), $this->handler());

        self::assertSame('1', $first->getHeaderLine('X-RateLimit-Remaining'));
        self::assertSame('0', $second->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function test_a_request_at_the_limit_is_rejected_with_429(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 2, windowSeconds: 60);

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
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 1, windowSeconds: 60);
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
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('127.0.0.1'), $this->handler());
        $second = $middleware->process($this->request('192.168.1.1'), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_an_ipv6_identifier_does_not_break_the_cache_key(): void
    {
        // The whole reason the identifier is sha256-hashed before use: PSR-16
        // forbids ":" in a key, and a bare IPv6 address is full of them.
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 1, windowSeconds: 60);

        $response = $middleware->process($this->request('2001:db8::1'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_request_after_the_window_resets_is_allowed_again(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 1, windowSeconds: 1);

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
            'global',
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
                parent::__construct($cache, 'login', maxAttempts: 1, windowSeconds: 60);
            }
        };

        $first = $middleware->process($this->request(), $this->handler());
        $second = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    /**
     * SharedRateLimitMiddleware is the thin subclass an application writes
     * so #[Middleware(...)], which carries only a class-string, reaches a
     * policy with a real ID and limits.
     */
    public function test_works_as_route_middleware_through_a_real_kernel(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySimpleCache());
        $app->boot();

        $router = new Router();
        $router->register(RateLimitedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $ip = ['REMOTE_ADDR' => '127.0.0.1'];

        // The fixture allows 2 per window, and nothing else is registered.
        $first = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));
        $second = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));
        $third = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(429, $third->getStatusCode());
    }

    public function test_a_subclass_can_override_the_identifier_used_for_keying(): void
    {
        // identifierFor() is protected, not private, specifically so a
        // subclass's override actually takes effect when dedupeKey() (still
        // defined on the parent) calls it: a private method binds statically
        // to its defining class regardless of subclassing.
        $middleware = new class (new InMemorySimpleCache()) extends RateLimitMiddleware {
            public function __construct(CacheInterface $cache)
            {
                parent::__construct($cache, 'api', maxAttempts: 1, windowSeconds: 60);
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
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: 1, windowSeconds: 60);

        $first = $middleware->process($this->request('203.0.113.1', ['X-Forwarded-For' => '1.1.1.1']), $this->handler());
        $second = $middleware->process($this->request('203.0.113.1', ['X-Forwarded-For' => '2.2.2.2']), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode());
    }

    public function test_x_forwarded_for_is_honored_when_remote_addr_is_a_trusted_proxy(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            'api',
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
            'api',
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
            'api',
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
            'api',
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

    /**
     * The bucket a request lands in follows from what the peer's address
     * *is*, not from how it is spelled. An edge configured as
     * `2001:db8::1` and connecting as its expanded form is the same
     * host, so the two clients behind it stay two buckets — read as
     * text it would stop being an edge, and every client behind it would
     * share one bucket keyed on the proxy, which is the shape that turns
     * one busy client into a limit for everybody.
     */
    public function test_an_exact_ipv6_proxy_is_trusted_however_the_peer_spells_it(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            'api',
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['2001:db8::1'],
        );

        $first = $middleware->process(
            $this->request('2001:0db8:0000:0000:0000:0000:0000:0001', ['X-Forwarded-For' => '1.1.1.1']),
            $this->handler(),
        );
        $second = $middleware->process(
            $this->request('2001:0db8:0000:0000:0000:0000:0000:0001', ['X-Forwarded-For' => '2.2.2.2']),
            $this->handler(),
        );

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    /**
     * And the chain walk under the same policy: a hop written in the
     * expanded form is stepped over as the trusted proxy it is, so the
     * bucket is the client's rather than the proxy's.
     */
    public function test_a_forwarded_hop_spelled_another_way_is_still_stepped_over(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemorySimpleCache(),
            'api',
            maxAttempts: 1,
            windowSeconds: 60,
            trustedProxies: ['2001:db8::1'],
        );

        $chain = ['X-Forwarded-For' => '9.9.9.9, 2001:0db8:0000:0000:0000:0000:0000:0001'];

        $first = $middleware->process($this->request('2001:db8::1', $chain), $this->handler());
        $second = $middleware->process($this->request('2001:db8::1', $chain), $this->handler());

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(429, $second->getStatusCode(), '9.9.9.9 is the client both times, so both requests key one bucket');
    }

    public function test_construction_over_a_null_cache_throws_instead_of_silently_not_enforcing(): void
    {
        $this->expectException(RateLimitUnavailableException::class);

        new RateLimitMiddleware(new NullSimpleCache(), 'api');
    }

    public function test_construction_over_a_real_cache_succeeds(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api');

        self::assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    /**
     * Redis configured but kinetis/cache-redis missing binds
     * UnavailableSimpleCache (see AppScope::boot()), which is not a
     * NullSimpleCache and so passes this constructor's guard — the
     * request must still fail loudly rather than serve unlimited
     * traffic, and the error names the missing package rather than the
     * symptom.
     */
    public function test_an_unavailable_cache_fails_the_request_instead_of_not_enforcing(): void
    {
        $middleware = new RateLimitMiddleware(new UnavailableSimpleCache(), 'api');

        $this->expectException(SimpleCacheUnavailableException::class);
        $this->expectExceptionMessage('kinetis/cache-redis');
        $middleware->process($this->request(), $this->handler());
    }

    /**
     * @return list<array{string}>
     */
    public static function blankPolicyIds(): array
    {
        return [[''], [' '], ["\t\n"]];
    }

    #[DataProvider('blankPolicyIds')]
    public function test_a_blank_policy_id_is_rejected_at_construction(string $policyId): void
    {
        $this->expectException(InvalidRateLimitConfigException::class);

        new RateLimitMiddleware(new InMemorySimpleCache(), $policyId);
    }

    /**
     * The ID is trusted application configuration, not request input, so
     * it is accepted as written and reduced to a fixed-width hash — which
     * is what keeps PSR-16's forbidden `{}()/\@:` out of the key.
     */
    public function test_a_policy_id_containing_psr16_reserved_characters_still_derives_a_safe_key(): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'login:{v1}/@\\', maxAttempts: 1, windowSeconds: 60);

        $subject = (new ReflectionMethod(RateLimitMiddleware::class, 'dedupeKey'))->invoke($middleware, $this->request());

        self::assertMatchesRegularExpression('/^[0-9a-f.]+$/', (string) $subject);
        self::assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    /**
     * @return list<array{int}>
     */
    public static function nonPositiveWindows(): array
    {
        return [[0], [-1], [-60]];
    }

    #[DataProvider('nonPositiveWindows')]
    public function test_a_window_of_zero_or_less_is_rejected_at_construction(int $windowSeconds): void
    {
        $this->expectException(InvalidRateLimitConfigException::class);

        new RateLimitMiddleware(new InMemorySimpleCache(), 'api', windowSeconds: $windowSeconds);
    }

    /**
     * @return list<array{int}>
     */
    public static function nonPositiveAttempts(): array
    {
        return [[0], [-1]];
    }

    #[DataProvider('nonPositiveAttempts')]
    public function test_a_max_attempts_of_zero_or_less_is_rejected_at_construction(int $maxAttempts): void
    {
        $this->expectException(InvalidRateLimitConfigException::class);

        new RateLimitMiddleware(new InMemorySimpleCache(), 'api', maxAttempts: $maxAttempts);
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedProxies(): array
    {
        return [
            ['not-an-ip'],
            ['10.0.0.0/-1'],
            ['10.0.0.0/33'],
            ['::1/129'],
            ['10.0.0.0/eight'],
            ['10.0.0.0/'],
            ['nope/8'],
        ];
    }

    #[DataProvider('malformedProxies')]
    public function test_a_malformed_trusted_proxy_is_rejected_at_construction(string $proxy): void
    {
        $this->expectException(InvalidRateLimitConfigException::class);

        new RateLimitMiddleware(new InMemorySimpleCache(), 'api', trustedProxies: [$proxy]);
    }

    /**
     * @return list<array{string}>
     */
    public static function usableProxies(): array
    {
        return [
            ['10.0.0.0/8'],
            ['10.0.0.1'],
            ['0.0.0.0/0'],
            ['192.168.1.0/32'],
            ['2001:db8::/32'],
            ['::1'],
            ['::1/128'],
        ];
    }

    #[DataProvider('usableProxies')]
    public function test_a_usable_trusted_proxy_is_accepted(string $proxy): void
    {
        $middleware = new RateLimitMiddleware(new InMemorySimpleCache(), 'api', trustedProxies: [$proxy]);

        self::assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
    }

    public function test_construction_over_a_cache_that_cannot_count_atomically_throws(): void
    {
        $this->expectException(RateLimitUnavailableException::class);
        $this->expectExceptionMessage('AtomicCounterInterface');

        new RateLimitMiddleware(new NonAtomicCache(), 'api');
    }

    /**
     * Two policies guarding different things — a login endpoint and a 2FA
     * endpoint, say — are told apart by their IDs alone, so each keeps its
     * own budget for the same subject in the same window.
     */
    public function test_two_policies_with_different_ids_do_not_share_a_bucket(): void
    {
        $cache = new InMemorySimpleCache();
        $login = new RateLimitMiddleware($cache, 'login', maxAttempts: 1, windowSeconds: 60);
        $twoFactor = new RateLimitMiddleware($cache, '2fa', maxAttempts: 1, windowSeconds: 60);

        $login->process($this->request(), $this->handler());
        $rejected = $login->process($this->request(), $this->handler());

        $stillOk = $twoFactor->process($this->request(), $this->handler());

        self::assertSame(429, $rejected->getStatusCode());
        self::assertSame(200, $stillOk->getStatusCode());
    }

    /**
     * The ID is the whole identity: neither the class nor the limits take
     * part in it, so two instances sharing an ID count one subject against
     * one budget. That is what lets a policy be reconfigured — or reached
     * through a subclass in one place and the base class in another —
     * without splitting the counter a running deployment already holds.
     */
    public function test_two_instances_sharing_a_policy_id_share_one_counter(): void
    {
        $cache = new InMemorySimpleCache();
        $strict = new RateLimitMiddleware($cache, 'api', maxAttempts: 2, windowSeconds: 60);
        $generous = new class ($cache) extends RateLimitMiddleware {
            public function __construct(CacheInterface $cache)
            {
                parent::__construct($cache, 'api', maxAttempts: 5, windowSeconds: 60);
            }
        };

        $strict->process($this->request(), $this->handler());
        $generous->process($this->request(), $this->handler());

        // Two checks have already been counted against the one shared
        // counter, so the third exceeds $strict's own limit of 2 even
        // though $generous counted the second one.
        $rejected = $strict->process($this->request(), $this->handler());

        self::assertSame(429, $rejected->getStatusCode());
    }

    /**
     * A shared, mutable, in-process clock closure — advanced directly
     * between calls rather than by a real sleep(), so a real window
     * boundary can be crossed deterministically, with no timing
     * dependency at all.
     *
     * @return array{0: \Closure, 1: \Closure}
     */
    private function fakeClock(int $start): array
    {
        $now = $start;
        $clock = static function () use (&$now): int {
            return $now;
        };
        $advance = static function (int $seconds) use (&$now): void {
            $now += $seconds;
        };

        return [$clock, $advance];
    }

    /**
     * Crosses a real window boundary between the outer occurrence
     * deciding and the inner occurrence running — the same thing a slow
     * intervening middleware could do in a real pipeline. Dedup keys on
     * policy+subject alone, never on $window, or the two occurrences
     * (each computing a different window independently) fail to recognize
     * each other as the same check.
     */
    public function test_the_dedup_reuses_a_decision_even_if_a_window_boundary_passes_before_the_inner_policy_runs(): void
    {
        $cache = new InMemorySimpleCache();
        [$clock, $advance] = $this->fakeClock(1_000_000);
        $outer = new RateLimitMiddleware($cache, 'api', maxAttempts: 1, windowSeconds: 1, clock: $clock);
        $inner = new RateLimitMiddleware($cache, 'api', maxAttempts: 1, windowSeconds: 1, clock: $clock);

        $crossingHandler = new CallableRequestHandler(function (ServerRequestInterface $req) use ($inner, $advance) {
            $advance(2); // crosses at least one 1-second window boundary
            return $inner->process($req, $this->handler());
        });

        $response = $outer->process($this->request(), $crossingHandler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('X-RateLimit-Remaining'));

        // If $inner had treated the boundary-crossed call as a
        // separate check, it would already have consumed the
        // new window's own budget of 1 — leaving nothing for this
        // direct, undeduped call to $inner alone (still within that
        // same new window) to be the *first* real increment against it.
        $directToInner = $inner->process($this->request(), $this->handler());

        self::assertSame(200, $directToInner->getStatusCode());
        self::assertSame('0', $directToInner->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * The real pipeline cannot construct this input itself — a rejecting
     * occurrence returns 429 without ever calling the next handler, so
     * nothing downstream observes a rejected decision through it. Driving
     * the reuse branch directly, via a manually recorded rejection, pins
     * that tooManyRequestsResponse() answers against the *original*
     * window.
     */
    public function test_a_reused_rejection_reports_the_original_windows_retry_after_not_a_recomputed_one(): void
    {
        $cache = new InMemorySimpleCache();
        [$clock, $advance] = $this->fakeClock(1_000_000);
        $middleware = new RateLimitMiddleware($cache, 'api', maxAttempts: 1, windowSeconds: 10, clock: $clock);

        $dedupeKeyMethod = new ReflectionMethod(RateLimitMiddleware::class, 'dedupeKey');
        $attributeName = (string) (new ReflectionClassConstant(RateLimitMiddleware::class, 'EXECUTED_ATTRIBUTE'))->getValue();

        $request = $this->request();
        $subject = $dedupeKeyMethod->invoke($middleware, $request);
        $originalWindow = intdiv(1_000_000, 10);

        $request = $request->withAttribute($attributeName, [
            $subject => ['attempts' => 2, 'window' => $originalWindow],
        ]);

        // The clock has since moved well past that original window's
        // own end. A correct reuse still resolves Retry-After against
        // the *original* window — already expired by now, so 0,
        // correctly clamped — never a fresh window resolved from the
        // current, later clock reading, which would instead report a
        // full, wrong 10-second wait.
        $advance(1000);

        $reused = $middleware->process($request, $this->handler());

        self::assertSame(429, $reused->getStatusCode());
        self::assertSame('0', $reused->getHeaderLine('Retry-After'));
    }

    /**
     * The same policy appearing twice in one request's pipeline — the
     * shape a global registration plus a redundant route one takes.
     * $second reads $first's already-recorded decision off the request
     * instead of incrementing the shared counter a second time.
     */
    public function test_two_instances_of_the_identical_policy_processing_the_same_request_count_once(): void
    {
        $cache = new InMemorySimpleCache();
        $first = new RateLimitMiddleware($cache, 'api', maxAttempts: 2, windowSeconds: 60);
        $second = new RateLimitMiddleware($cache, 'api', maxAttempts: 2, windowSeconds: 60);
        $innerHandler = new CallableRequestHandler(
            fn (ServerRequestInterface $req) => $second->process($req, $this->handler()),
        );

        $onlyOneRealRequest = $first->process($this->request(), $innerHandler);

        self::assertSame(200, $onlyOneRealRequest->getStatusCode());
        self::assertSame('1', $onlyOneRealRequest->getHeaderLine('X-RateLimit-Remaining'));

        // A separate HTTP request still counts as a second
        // real check — maxAttempts: 2 allows it, at exactly zero
        // remaining — proving the dedup only ever applies within one
        // request's own attribute chain, never across requests.
        $secondRealRequest = $first->process($this->request(), $innerHandler);

        self::assertSame(200, $secondRealRequest->getStatusCode());
        self::assertSame('0', $secondRealRequest->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * The outer policy here is generous and will not itself reject; the
     * inner one is strict. Both wrap the same handler chain the way
     * Kernel's own global-then-route pipelines do.
     */
    public function test_an_inner_policys_headers_survive_being_wrapped_by_an_outer_successful_policy(): void
    {
        $cache = new InMemorySimpleCache();
        $outer = new RateLimitMiddleware($cache, 'global', maxAttempts: 100, windowSeconds: 60);
        $inner = new class ($cache) extends RateLimitMiddleware {
            public function __construct(CacheInterface $cache)
            {
                parent::__construct($cache, 'route', maxAttempts: 1, windowSeconds: 60);
            }
        };
        $innerHandler = new CallableRequestHandler(fn ($req) => $inner->process($req, $this->handler()));

        $ok = $outer->process($this->request(), $innerHandler);

        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('1', $ok->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $ok->getHeaderLine('X-RateLimit-Remaining'));

        $rejected = $outer->process($this->request(), $innerHandler);

        self::assertSame(429, $rejected->getStatusCode());
        // The outer policy is still within its own generous budget and
        // must not overwrite the inner, rejecting policy's real numbers
        // with its own unrelated ones.
        self::assertSame('1', $rejected->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $rejected->getHeaderLine('X-RateLimit-Remaining'));
    }

    /**
     * A generous global policy (maxAttempts: 3) wraps a strict route
     * policy (maxAttempts: 1 — see StrictRouteRateLimitMiddleware)
     * through a real Kernel: the exact composition a real application
     * configures both policies through.
     */
    public function test_a_global_and_a_route_policy_compose_with_independent_counters_and_truthful_headers(): void
    {
        $app = new AppScope();
        $cache = new InMemorySimpleCache();
        $app->instance(CacheInterface::class, $cache);
        $app->middleware(RateLimitMiddleware::class);
        $app->bind(RateLimitMiddleware::class, static fn ($c) => new RateLimitMiddleware(
            $c->get(CacheInterface::class),
            'global',
            maxAttempts: 3,
            windowSeconds: 60,
        ));
        $app->boot();

        $router = new Router();
        $router->register(StrictRouteRateLimitedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $ip = ['REMOTE_ADDR' => '127.0.0.1'];

        $first = $kernel->handle(new ServerRequest('GET', '/strict-limited', serverParams: $ip));

        self::assertSame(200, $first->getStatusCode());
        // The route's own strict limit (1), not the global's generous
        // one (3), is what a successful response shows too.
        self::assertSame('1', $first->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $first->getHeaderLine('X-RateLimit-Remaining'));

        $second = $kernel->handle(new ServerRequest('GET', '/strict-limited', serverParams: $ip));

        self::assertSame(429, $second->getStatusCode());
        // The outer, still-within-budget global policy must not
        // overwrite the rejecting route policy's own honest headers —
        // the real defect this composes to catch.
        self::assertSame('1', $second->getHeaderLine('X-RateLimit-Limit'));
        self::assertSame('0', $second->getHeaderLine('X-RateLimit-Remaining'));

        // The global counter's own budget (3) is independent of the
        // route's own rejection: exactly two real requests have reached
        // the global policy so far (both to /strict-limited), so one
        // more, to an entirely unmatched path with no route middleware
        // at all, is still allowed — and only the one after that is
        // rejected by the global policy itself.
        $third = $kernel->handle(new ServerRequest('GET', '/unmatched', serverParams: $ip));
        self::assertSame(404, $third->getStatusCode());

        $fourth = $kernel->handle(new ServerRequest('GET', '/unmatched', serverParams: $ip));
        self::assertSame(429, $fourth->getStatusCode());
    }

    /**
     * One policy class registered both globally and, redundantly, on the
     * matched route. It allows 2 per window, so if each request counted
     * twice the budget would already be gone after the first one.
     */
    public function test_the_identical_policy_registered_both_globally_and_on_the_route_counts_once_per_request(): void
    {
        $app = new AppScope();
        $app->instance(CacheInterface::class, new InMemorySimpleCache());
        $app->middleware(SharedRateLimitMiddleware::class);
        $app->boot();

        $router = new Router();
        $router->register(RateLimitedFixtureController::class);
        $kernel = new Kernel($app, $router);

        $ip = ['REMOTE_ADDR' => '127.0.0.1'];

        $first = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));
        $second = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));
        $third = $kernel->handle(new ServerRequest('GET', '/limited', serverParams: $ip));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(429, $third->getStatusCode());
    }
}
