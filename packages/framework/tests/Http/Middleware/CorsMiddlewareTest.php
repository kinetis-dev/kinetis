<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\CorsMiddleware;
use Kinetis\Http\Routing\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CorsMiddlewareTest extends TestCase
{
    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    public function test_a_request_with_no_origin_header_passes_through_untouched(): void
    {
        $middleware = new CorsMiddleware();

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_an_allowed_origin_gets_cors_headers_on_a_normal_response(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_a_disallowed_origin_gets_no_cors_headers_and_the_response_is_otherwise_unchanged(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://evil.example']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_a_disallowed_origin_still_gets_vary_origin_so_a_shared_cache_cannot_poison_an_allowed_one(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://evil.example']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_a_request_without_an_origin_stays_unmarked_under_a_static_wildcard(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertFalse($response->hasHeader('Vary'));
    }

    public function test_a_wildcard_origin_is_echoed_back_literally_without_credentials(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'));
    }

    public function test_echoing_a_specific_origin_adds_vary_origin(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_credentials_force_the_specific_origin_when_a_real_allow_list_is_configured(): void
    {
        // Per spec, browsers reject Access-Control-Allow-Origin: * combined
        // with credentials — the specific origin is echoed instead. This is
        // the legitimate version of that (a real, non-wildcard allow list);
        // the wildcard+credentials combination itself is rejected outright
        // at construction — see the two tests below.
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com'], allowCredentials: true);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_wildcard_origin_combined_with_credentials_is_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CorsMiddleware(allowedOrigins: ['*'], allowCredentials: true);
    }

    public function test_a_matching_wildcard_pattern_combined_with_credentials_is_not_itself_rejected(): void
    {
        // Only the exact '*' entry in $allowedOrigins is guarded against —
        // $allowedOriginPatterns is a real allow-list mechanism (specific
        // patterns, not a blanket wildcard), so this combination is fine.
        $middleware = new CorsMiddleware(
            allowedOriginPatterns: ['#^https://[a-z0-9-]+\.example\.com$#'],
            allowCredentials: true,
        );
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://app.example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function test_the_default_configuration_denies_every_origin(): void
    {
        $middleware = new CorsMiddleware();
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_exposed_headers_are_set_only_when_configured(): void
    {
        $withExposed = new CorsMiddleware(allowedOrigins: ['*'], exposedHeaders: ['X-Request-Id']);
        $withoutExposed = new CorsMiddleware(allowedOrigins: ['*']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $responseWith = $withExposed->process($request, $this->handler());
        $responseWithout = $withoutExposed->process($request, $this->handler());

        self::assertSame('X-Request-Id', $responseWith->getHeaderLine('Access-Control-Expose-Headers'));
        self::assertFalse($responseWithout->hasHeader('Access-Control-Expose-Headers'));
    }

    public function test_a_preflight_request_is_answered_directly_without_calling_the_inner_handler(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $called = false;
        $handler = new CallableRequestHandler(function () use (&$called) {
            $called = true;

            return new Response(200);
        });
        $request = new ServerRequest('OPTIONS', '/users', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $response = $middleware->process($request, $handler);

        self::assertFalse($called);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertNotSame('', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function test_a_preflight_with_a_fixed_header_allow_list_returns_that_list(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://example.com'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        );
        $request = new ServerRequest('OPTIONS', '/users', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Custom-Header',
        ]);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function test_a_preflight_with_a_wildcard_header_allow_list_reflects_the_requested_headers(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com'], allowedHeaders: ['*']);
        $request = new ServerRequest('OPTIONS', '/users', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Custom-Header, X-Another-One',
        ]);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('X-Custom-Header, X-Another-One', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    public function test_an_options_request_without_the_preflight_header_is_not_treated_as_a_preflight(): void
    {
        // A plain OPTIONS request (no Access-Control-Request-Method) isn't
        // a CORS preflight — it should reach the inner handler like any
        // other request, decorated with CORS headers on the way out.
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $called = false;
        $handler = new CallableRequestHandler(function () use (&$called) {
            $called = true;

            return new Response(200);
        });
        $request = new ServerRequest('OPTIONS', '/users', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $handler);

        self::assertTrue($called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_origin_matching_an_allowed_pattern_is_allowed(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: [],
            allowedOriginPatterns: ['#^https://[a-z0-9-]+\.example\.com$#'],
        );
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://app.example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function test_an_origin_matching_no_pattern_is_rejected(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: [],
            allowedOriginPatterns: ['#^https://[a-z0-9-]+\.example\.com$#'],
        );
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * The classic CORS misconfiguration: no anchors and an unescaped
     * ".", so "example.com" reads as any character between "example" and
     * "com" and appears anywhere in the Origin. Requiring the match to
     * cover the whole Origin is what denies it.
     */
    public function test_an_unanchored_pattern_does_not_allow_an_origin_that_merely_contains_it(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: [],
            allowedOriginPatterns: ['#example.com#'],
        );
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://evil-example.com.attacker.net']);

        $response = $middleware->process($request, $this->handler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * Why the pattern is not inspected for ^...$ instead: this one
     * carries both and is still unanchored on its second branch, so an
     * anchor check would pass it while it allows any origin ending in
     * evil.com.
     */
    public function test_an_alternation_carrying_anchors_is_still_matched_against_the_whole_origin(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: [],
            allowedOriginPatterns: ['#^https://good\.com$|evil\.com$#'],
        );

        $denied = $middleware->process(
            new ServerRequest('GET', '/', ['Origin' => 'https://not-evil.com']),
            $this->handler(),
        );
        self::assertFalse($denied->hasHeader('Access-Control-Allow-Origin'));

        // The branch that does describe a whole Origin still works.
        $allowed = $middleware->process(
            new ServerRequest('GET', '/', ['Origin' => 'https://good.com']),
            $this->handler(),
        );
        self::assertSame('https://good.com', $allowed->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * @return list<array{string}>
     */
    public static function uncompilablePatterns(): array
    {
        return [
            ['example.com'],
            ['#^https://[unclosed#'],
            ['#^https://example\\.com$#Z'],
            [''],
        ];
    }

    /**
     * A pattern that cannot compile matches nothing, so it would deny
     * every origin it was written to allow, on every request, quietly.
     */
    #[DataProvider('uncompilablePatterns')]
    public function test_a_pattern_that_cannot_compile_is_rejected_at_construction(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsMiddleware(allowedOriginPatterns: [$pattern]);
    }

    public function test_works_as_global_middleware_and_answers_a_preflight_before_routing_would_404(): void
    {
        $app = new AppScope();
        $app->middleware(CorsMiddleware::class);
        $app->bind(CorsMiddleware::class, static fn () => new CorsMiddleware(allowedOrigins: ['https://example.com']));
        $app->boot();

        $router = new Router();
        $kernel = new Kernel($app, $router);

        // No route at all is registered for /nonexistent — proving CORS
        // intercepts the preflight before routing ever gets a chance to
        // produce its own 404/405.
        $request = new ServerRequest('OPTIONS', '/nonexistent', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);

        $response = $kernel->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
