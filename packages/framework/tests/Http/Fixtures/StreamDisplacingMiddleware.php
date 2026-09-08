<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\StreamedResponse;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the handler and answers with a streamed response of its own — the
 * one replacement shape that is still a StreamableResponseInterface, so a
 * test can separate "the final response streams" from "the final response
 * is the wrapper the Kernel produced". Keeps the response it took
 * delivery of, so a test can settle it afterwards and prove that does
 * nothing further.
 */
final readonly class StreamDisplacingMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Displace-Stream';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$request->hasHeader(self::HEADER)) {
            return $response;
        }

        StreamProbe::$displaced = $response;
        StreamProbe::$events[] = 'displaced';

        return new StreamedResponse(
            new Response(203, ['Content-Type' => 'text/plain']),
            static function (): void {
                StreamProbe::$events[] = 'replacement-emitted';
            },
        );
    }
}
