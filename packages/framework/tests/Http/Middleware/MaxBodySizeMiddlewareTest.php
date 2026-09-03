<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Kernel;
use Kinetis\Http\Middleware\MaxBodySizeMiddleware;
use Kinetis\Http\Routing\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class MaxBodySizeMiddlewareTest extends TestCase
{
    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
    }

    public function test_a_non_positive_max_body_size_throws_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('MAX_BODY_SIZE must be a positive number of bytes');

        new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '0']));
    }

    public function test_a_request_under_the_limit_passes_through(): void
    {
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', headers: ['Content-Length' => '500']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_request_over_the_limit_is_rejected_with_413(): void
    {
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', headers: ['Content-Length' => '1001']);

        $response = $middleware->process($request, $this->handler());

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true));
    }

    public function test_the_inner_handler_never_runs_once_the_limit_is_exceeded(): void
    {
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', headers: ['Content-Length' => '2000']);
        $calls = 0;
        $handler = new CallableRequestHandler(function () use (&$calls) {
            $calls++;

            return new Response(200);
        });

        $middleware->process($request, $handler);

        self::assertSame(0, $calls);
    }

    public function test_a_small_request_with_no_content_length_header_passes_through(): void
    {
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', body: 'small body');

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_oversized_body_with_no_content_length_header_is_still_rejected(): void
    {
        // The declared-header check alone can't catch this — a missing
        // Content-Length skips it entirely. The wrapped body stream is
        // the backstop: rejected once the handler actually reads past
        // the limit, not before.
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', body: str_repeat('x', 1500));
        $handler = new CallableRequestHandler(static fn (ServerRequestInterface $r) => new Response(200, body: $r->getBody()->getContents()));

        $response = $middleware->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function test_an_oversized_body_with_an_inaccurate_content_length_header_is_still_rejected(): void
    {
        // A dishonest header claiming a small size skips the fast-path
        // check; the actual bytes read still trip the backstop.
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', headers: ['Content-Length' => '10'], body: str_repeat('x', 1500));
        $handler = new CallableRequestHandler(static fn (ServerRequestInterface $r) => new Response(200, body: $r->getBody()->getContents()));

        $response = $middleware->process($request, $handler);

        self::assertSame(413, $response->getStatusCode());
    }

    public function test_a_handler_that_never_reads_the_body_is_unaffected_by_the_backstop(): void
    {
        // The backstop only fires once something actually reads past
        // the limit — a route that never touches the body (a GET, or a
        // #[Query]-only route) is never affected by an oversized body
        // it was never going to read anyway.
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/', body: str_repeat('x', 1500));

        $response = $middleware->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_defaults_to_two_mebibytes_when_unconfigured(): void
    {
        $middleware = new MaxBodySizeMiddleware(new Config([]));

        $underDefault = $middleware->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => (string) (2 * 1024 * 1024)]),
            $this->handler(),
        );
        $overDefault = $middleware->process(
            new ServerRequest('POST', '/', headers: ['Content-Length' => (string) (2 * 1024 * 1024 + 1)]),
            $this->handler(),
        );

        self::assertSame(200, $underDefault->getStatusCode());
        self::assertSame(413, $overDefault->getStatusCode());
    }

    public function test_runs_unconditionally_as_global_middleware_right_after_the_exception_handler(): void
    {
        $app = new AppScope();
        $app->instance(Config::class, new Config(['MAX_BODY_SIZE' => '1000']));
        $app->boot();

        $kernel = new Kernel($app, new Router());

        $response = $kernel->handle(new ServerRequest('POST', '/nonexistent', headers: ['Content-Length' => '1001']));

        // 413 rather than the 404 an unregistered route would otherwise
        // produce — proving this runs before routing, with no explicit
        // registration needed.
        self::assertSame(413, $response->getStatusCode());
    }
}
