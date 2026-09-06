<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sets a header on whatever the handler returned — the ordinary
 * post-handler shape CorsMiddleware and SecurityHeadersMiddleware use,
 * which for a streamed response means a clone of the wrapper Kernel
 * returned.
 */
final readonly class StreamHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Wrapped', 'yes');
    }
}
