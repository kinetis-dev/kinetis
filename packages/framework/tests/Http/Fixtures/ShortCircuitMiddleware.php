<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Never calls $handler->handle() — proves a middleware can short-circuit
 * the pipeline before the controller (or any later middleware) ever runs.
 */
final class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(
            status: 403,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode(['error' => 'short-circuited']),
        );
    }
}
