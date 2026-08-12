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

final class MaxBodySizeMiddlewareTest extends TestCase
{
    private function handler(): CallableRequestHandler
    {
        return new CallableRequestHandler(static fn () => new Response(200));
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

    public function test_a_request_with_no_content_length_header_passes_through(): void
    {
        // Out of scope by design: only the declared Content-Length is
        // checked, not the actual bytes read — an absent or inaccurate
        // header isn't caught here.
        $middleware = new MaxBodySizeMiddleware(new Config(['MAX_BODY_SIZE' => '1000']));
        $request = new ServerRequest('POST', '/');

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
