<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Middleware\ExceptionHandlerMiddleware;
use Kinetis\Runtime\AppEnvironment;
use Kinetis\Tests\Fixtures\FixtureHttpStatusException;
use Kinetis\Tests\Fixtures\InMemoryLogger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class ExceptionHandlerMiddlewareTest extends TestCase
{
    public function test_a_response_from_the_inner_handler_passes_through_untouched(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static fn () => new Response(200));

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_an_uncaught_throwable_from_the_inner_handler_becomes_a_500(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_uncaught_throwable_is_logged_with_the_request_method_and_path(): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $middleware->process(new ServerRequest('POST', '/users'), $handler);

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('POST', $logger->records[0]['context']['method']);
        self::assertSame('/users', $logger->records[0]['context']['path']);
        self::assertInstanceOf(RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    public function test_the_development_500_body_carries_the_exception_details(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Development);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(500, $response->getStatusCode());
        /** @var array{error: string, exception: string, message: string, location: string} $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Internal server error.', $body['error']);
        self::assertSame(RuntimeException::class, $body['exception']);
        self::assertSame('boom', $body['message']);
        self::assertStringContainsString(basename(__FILE__), $body['location']);
    }

    public function test_the_production_500_body_stays_generic_even_when_passed_explicitly(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('boom');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(['error' => 'Internal server error.'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_http_status_exception_becomes_its_own_declared_status(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger());
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'bad input'], json_decode((string) $response->getBody(), true));
    }

    public function test_an_http_status_exception_is_not_logged(): void
    {
        $logger = new InMemoryLogger();
        $middleware = new ExceptionHandlerMiddleware($logger);
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertCount(0, $logger->records);
    }

    public function test_an_http_status_exception_is_not_gated_by_environment(): void
    {
        $middleware = new ExceptionHandlerMiddleware(new NullLogger(), AppEnvironment::Production);
        $handler = new CallableRequestHandler(static function () {
            throw new FixtureHttpStatusException('bad input');
        });

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'bad input'], json_decode((string) $response->getBody(), true));
    }
}
