<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\CorsMiddleware;
use Kinetis\Http\Routing\Router;
use Kinetis\Tests\Http\Fixtures\UserController;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class CorsMiddlewareTest extends TestCase
{
    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    /**
     * A handler whose response already carries $lines as separate Vary
     * header *lines* (one withAddedHeader() call per element) — the shape
     * an application's own middleware could plausibly leave behind before
     * CorsMiddleware ever touches the response, used to exercise
     * withVary()'s merge behavior rather than its own from-empty case.
     */
    private function handlerWithVary(string ...$lines): CallableRequestHandler
    {
        return new CallableRequestHandler(static function () use ($lines) {
            $response = new Response(200);

            foreach ($lines as $line) {
                $response = $response->withAddedHeader('Vary', $line);
            }

            return $response;
        });
    }

    /**
     * $response's own Vary header, normalized into a sorted, lowercased
     * token set — the same parse withVary() itself performs (split every
     * header line on commas, trim, drop empties) plus a sort so exact-set
     * assertions never depend on insertion order, which withVary() never
     * promises to preserve for tokens it added itself.
     *
     * @return list<string>
     */
    private static function varyTokens(ResponseInterface $response): array
    {
        $tokens = [];

        foreach ($response->getHeader('Vary') as $line) {
            foreach (explode(',', $line) as $token) {
                $token = strtolower(trim($token));

                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        sort($tokens);

        return $tokens;
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

    public function test_a_request_without_an_origin_still_gets_vary_origin_under_a_wildcard_allow_list(): void
    {
        // A wildcard allow-list always answers with the literal "*" value,
        // but a request with no Origin header takes process()'s
        // disallowed/absent branch (no Access-Control-Allow-Origin at all)
        // while any Origin-bearing request takes the allowed branch — two
        // different response shapes a shared cache must be able to tell
        // apart, so this configuration is not origin-independent despite
        // the header's value never changing.
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->handler());

        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_a_wildcard_origin_is_echoed_back_literally_without_credentials(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['*']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('Origin', $response->getHeaderLine('Vary'));
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

    /**
     * Demonstrates the actual cache boundary Access-Control-Request-Method
     * exists to mark, through a real Kernel rather than the middleware in
     * isolation: the same method (OPTIONS), the same URI, and the same
     * allowed Origin produce two genuinely different response shapes —
     * an ordinary OPTIONS request falls through CorsMiddleware into
     * routing itself (a 405, since /users only registers GET), while a
     * preflight (the identical request plus Access-Control-Request-Method)
     * never reaches routing at all, answered directly with a 204. A cache
     * keyed only on method/URI/Origin cannot tell these two apart without
     * the Vary token both responses carry.
     */
    public function test_kernel_partitions_ordinary_options_from_preflight_at_the_same_uri_before_routing(): void
    {
        $app = new AppScope();
        $app->middleware(CorsMiddleware::class);
        $app->bind(CorsMiddleware::class, static fn () => new CorsMiddleware(allowedOrigins: ['https://example.com']));
        $app->boot();

        $router = new Router();
        $router->register(UserController::class);
        $kernel = new Kernel($app, $router);

        $ordinary = $kernel->handle(new ServerRequest('OPTIONS', '/users', [
            'Origin' => 'https://example.com',
        ]));
        $preflight = $kernel->handle(new ServerRequest('OPTIONS', '/users', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
        ]));

        // The ordinary request reached routing (GET-only) and got a real
        // 405; the preflight never reached routing at all.
        self::assertSame(405, $ordinary->getStatusCode());
        self::assertSame(204, $preflight->getStatusCode());

        self::assertSame(['access-control-request-method', 'origin'], self::varyTokens($ordinary));
        self::assertSame(['access-control-request-method', 'origin'], self::varyTokens($preflight));
    }

    public function test_a_fully_unconfigured_middleware_never_adds_vary_since_every_request_takes_the_same_branch(): void
    {
        $middleware = new CorsMiddleware();

        $withoutOrigin = $middleware->process(new ServerRequest('GET', '/'), $this->handler());
        $withOrigin = $middleware->process(
            new ServerRequest('GET', '/', ['Origin' => 'https://example.com']),
            $this->handler(),
        );

        self::assertSame([], self::varyTokens($withoutOrigin));
        self::assertSame([], self::varyTokens($withOrigin));
    }

    // --- withVary() canonical merge --------------------------------------

    public function test_vary_merge_with_no_existing_header_adds_the_new_token_alone(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame('Origin', $response->getHeaderLine('Vary'));
    }

    public function test_vary_merge_with_one_unrelated_existing_token_appends_rather_than_replaces(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handlerWithVary('Accept-Encoding'));

        self::assertSame(['accept-encoding', 'origin'], self::varyTokens($response));
    }

    public function test_vary_merge_with_a_comma_separated_existing_value_splits_and_dedupes(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process(
            $request,
            $this->handlerWithVary('Accept-Encoding, Accept-Language'),
        );

        self::assertSame(['accept-encoding', 'accept-language', 'origin'], self::varyTokens($response));
    }

    public function test_vary_merge_with_multiple_existing_header_lines_folds_into_one_canonical_line(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process(
            $request,
            $this->handlerWithVary('Accept-Encoding', 'Accept-Language'),
        );

        self::assertCount(1, $response->getHeader('Vary'));
        self::assertSame(['accept-encoding', 'accept-language', 'origin'], self::varyTokens($response));
    }

    public function test_vary_merge_does_not_duplicate_origin_present_under_a_different_case(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handlerWithVary('origin'));

        // Exactly one Origin token — the application's own casing is kept
        // rather than replaced, since withVary() only ever adds a token
        // that isn't already present under any casing.
        self::assertSame('origin', $response->getHeaderLine('Vary'));
        self::assertSame(['origin'], self::varyTokens($response));
    }

    public function test_vary_merge_dedupes_a_token_repeated_in_the_existing_value(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handlerWithVary('Accept-Encoding, Accept-Encoding'));

        self::assertSame(['accept-encoding', 'origin'], self::varyTokens($response));
    }

    public function test_vary_merge_trims_surrounding_whitespace_around_existing_tokens(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handlerWithVary('  Accept-Encoding  ,  Accept-Language  '));

        self::assertSame(['accept-encoding', 'accept-language', 'origin'], self::varyTokens($response));
    }

    public function test_vary_merge_leaves_an_existing_wildcard_untouched(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $request = new ServerRequest('GET', '/', ['Origin' => 'https://example.com']);

        $response = $middleware->process($request, $this->handlerWithVary('*'));

        // "*" already means "varies on everything" — appending "Origin"
        // next to it would only invite a downstream cache to misread the
        // field as a literal, finite list instead.
        self::assertSame('*', $response->getHeaderLine('Vary'));
    }

    // --- Cache-conformance regression matrix -----------------------------

    /**
     * Each row is one request against a fixed method/URI, asserting both
     * the response's representation (whether Access-Control-Allow-Origin
     * is present, and its exact value) and its exact normalized Vary
     * token set — the two things a shared cache actually keys and
     * partitions on. Paired rows sharing a $name prefix are the same
     * method/URI answered by two different request shapes, which is the
     * actual variance under test: two rows producing the same
     * representation but different Vary tokens would mean the cache
     * cannot tell them apart even though nothing about the response
     * itself actually differs, and two rows producing different
     * representations with the same (or no) Vary token would mean a
     * cache genuinely could conflate them.
     *
     * @return array<string, array{0: ServerRequest, 1: CorsMiddleware, 2: bool, 3: list<string>}>
     */
    public static function cacheConformanceMatrix(): array
    {
        $exactOrigin = new CorsMiddleware(allowedOrigins: ['https://example.com']);
        $wildcardOrigin = new CorsMiddleware(allowedOrigins: ['*']);
        $patternOrigin = new CorsMiddleware(allowedOriginPatterns: ['#^https://[a-z0-9-]+\.example\.com$#']);
        $fixedPreflightHeaders = new CorsMiddleware(
            allowedOrigins: ['https://example.com'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        );
        $wildcardPreflightHeaders = new CorsMiddleware(allowedOrigins: ['https://example.com'], allowedHeaders: ['*']);

        return [
            'no origin, wildcard allow-list' => [
                new ServerRequest('GET', '/resource'),
                $wildcardOrigin,
                false,
                ['origin'],
            ],
            'wildcard origin, present' => [
                new ServerRequest('GET', '/resource', ['Origin' => 'https://example.com']),
                $wildcardOrigin,
                true,
                ['origin'],
            ],
            'exact allow-list, allowed origin' => [
                new ServerRequest('GET', '/resource', ['Origin' => 'https://example.com']),
                $exactOrigin,
                true,
                ['origin'],
            ],
            'exact allow-list, disallowed origin' => [
                new ServerRequest('GET', '/resource', ['Origin' => 'https://evil.example']),
                $exactOrigin,
                false,
                ['origin'],
            ],
            'pattern allow-list, matching origin' => [
                new ServerRequest('GET', '/resource', ['Origin' => 'https://app.example.com']),
                $patternOrigin,
                true,
                ['origin'],
            ],
            'pattern allow-list, non-matching origin' => [
                new ServerRequest('GET', '/resource', ['Origin' => 'https://example.com']),
                $patternOrigin,
                false,
                ['origin'],
            ],
            'ordinary OPTIONS (no Access-Control-Request-Method)' => [
                new ServerRequest('OPTIONS', '/resource', ['Origin' => 'https://example.com']),
                $exactOrigin,
                true,
                ['access-control-request-method', 'origin'],
            ],
            'preflight, Access-Control-Request-Method: GET' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'GET',
                ]),
                $exactOrigin,
                true,
                ['access-control-request-method', 'origin'],
            ],
            'preflight, Access-Control-Request-Method: POST' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                ]),
                $exactOrigin,
                true,
                ['access-control-request-method', 'origin'],
            ],
            'preflight, fixed allowedHeaders, requested X-One' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'X-One',
                ]),
                $fixedPreflightHeaders,
                true,
                ['access-control-request-method', 'origin'],
            ],
            'preflight, fixed allowedHeaders, requested X-Two' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'X-Two',
                ]),
                $fixedPreflightHeaders,
                true,
                ['access-control-request-method', 'origin'],
            ],
            'preflight, wildcard allowedHeaders, requested X-One' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'X-One',
                ]),
                $wildcardPreflightHeaders,
                true,
                ['access-control-request-headers', 'access-control-request-method', 'origin'],
            ],
            'preflight, wildcard allowedHeaders, requested X-Two' => [
                new ServerRequest('OPTIONS', '/resource', [
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'X-Two',
                ]),
                $wildcardPreflightHeaders,
                true,
                ['access-control-request-headers', 'access-control-request-method', 'origin'],
            ],
        ];
    }

    #[DataProvider('cacheConformanceMatrix')]
    public function test_cache_conformance_matrix(
        ServerRequest $request,
        CorsMiddleware $middleware,
        bool $expectAllowOrigin,
        array $expectedVaryTokens,
    ): void {
        $response = $middleware->process($request, $this->handler());

        self::assertSame($expectAllowOrigin, $response->hasHeader('Access-Control-Allow-Origin'));
        self::assertSame($expectedVaryTokens, self::varyTokens($response));
    }

    /**
     * The two wildcard-header preflight rows above share a representation
     * (Access-Control-Allow-Origin present, same Vary set) while actually
     * differing in Access-Control-Allow-Headers itself — the dimension
     * the shared Vary token exists to mark, checked here directly since
     * the matrix above only asserts the Vary set, not this header.
     */
    public function test_wildcard_preflight_headers_reflect_the_request_and_therefore_genuinely_differ(): void
    {
        $middleware = new CorsMiddleware(allowedOrigins: ['https://example.com'], allowedHeaders: ['*']);

        $one = $middleware->process(new ServerRequest('OPTIONS', '/resource', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-One',
        ]), $this->handler());
        $two = $middleware->process(new ServerRequest('OPTIONS', '/resource', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Two',
        ]), $this->handler());

        self::assertSame('X-One', $one->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('X-Two', $two->getHeaderLine('Access-Control-Allow-Headers'));
    }

    /**
     * The two fixed-allowedHeaders preflight rows above are genuinely
     * byte-for-byte identical despite requesting different headers —
     * confirming the matrix correctly omits Access-Control-Request-Headers
     * from their Vary set, since a cache serving one for the other could
     * never actually observe a difference.
     */
    public function test_fixed_preflight_headers_are_identical_regardless_of_what_was_requested(): void
    {
        $middleware = new CorsMiddleware(
            allowedOrigins: ['https://example.com'],
            allowedHeaders: ['Content-Type', 'Authorization'],
        );

        $one = $middleware->process(new ServerRequest('OPTIONS', '/resource', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-One',
        ]), $this->handler());
        $two = $middleware->process(new ServerRequest('OPTIONS', '/resource', [
            'Origin' => 'https://example.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'X-Two',
        ]), $this->handler());

        self::assertSame($one->getHeaderLine('Access-Control-Allow-Headers'), $two->getHeaderLine('Access-Control-Allow-Headers'));
    }
}
