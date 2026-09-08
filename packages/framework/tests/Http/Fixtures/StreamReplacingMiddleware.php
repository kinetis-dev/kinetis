<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the handler and then answers with a buffered response of its own
 * — the shape a middleware rewriting or refusing whatever dispatch
 * produced takes, and the one that leaves a streamed response no owner
 * downstream will ever emit. Records when it ran, so a test can order
 * the replacement against the release.
 */
final readonly class StreamReplacingMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Replace-Stream';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$request->hasHeader(self::HEADER)) {
            return $response;
        }

        StreamProbe::$events[] = 'replaced';

        return new Response(202);
    }
}
