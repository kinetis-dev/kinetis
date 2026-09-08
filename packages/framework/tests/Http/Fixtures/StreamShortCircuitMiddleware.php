<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers a request outright without ever calling its handler — the
 * shape a CORS preflight, a rejected request body and a rate limit all
 * take. Records when it ran, so a test can order it against the
 * previous request's stream release.
 */
final readonly class StreamShortCircuitMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Short-Circuit';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->hasHeader(self::HEADER)) {
            return $handler->handle($request);
        }

        StreamProbe::$events[] = 'short-circuit';

        return new Response(204);
    }
}
