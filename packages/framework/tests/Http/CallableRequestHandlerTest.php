<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http;

use Kinetis\Http\CallableRequestHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class CallableRequestHandlerTest extends TestCase
{
    public function test_delegates_to_the_wrapped_closure(): void
    {
        $handler = new CallableRequestHandler(
            static fn ($request) => new Response(200, [], $request->getUri()->getPath()),
        );

        $response = $handler->handle(new ServerRequest('GET', '/hello'));

        self::assertSame('/hello', (string) $response->getBody());
    }
}
