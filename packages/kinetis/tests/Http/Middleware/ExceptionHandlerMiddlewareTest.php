<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Middleware;

use Kinetis\Http\CallableRequestHandler;
use Kinetis\Http\Middleware\ExceptionHandlerMiddleware;
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
}
