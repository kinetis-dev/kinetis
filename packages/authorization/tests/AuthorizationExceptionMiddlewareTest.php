<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests;

use Kinetis\Authorization\AuthorizationExceptionMiddleware;
use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Http\CallableRequestHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthorizationExceptionMiddlewareTest extends TestCase
{
    public function test_a_response_from_the_inner_handler_passes_through_untouched(): void
    {
        $middleware = new AuthorizationExceptionMiddleware();
        $handler = new CallableRequestHandler(static fn () => new Response(200));

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_thrown_authorization_exception_becomes_a_403(): void
    {
        $middleware = new AuthorizationExceptionMiddleware();
        $handler = new CallableRequestHandler(static function () {
            throw AuthorizationException::denied('This post is locked and cannot be edited.');
        });

        $response = $middleware->process(new ServerRequest('PATCH', '/posts/42'), $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['error' => 'This post is locked and cannot be edited.'],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function test_any_other_throwable_is_left_to_propagate(): void
    {
        $middleware = new AuthorizationExceptionMiddleware();
        $handler = new CallableRequestHandler(static function () {
            throw new RuntimeException('unrelated failure');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unrelated failure');

        $middleware->process(new ServerRequest('GET', '/'), $handler);
    }
}
