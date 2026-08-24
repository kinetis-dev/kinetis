<?php

declare(strict_types=1);

namespace Kinetis\Authorization;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Registered globally by PackageBootstrap. Holds no per-request state, so
 * it's safe as the worker-lifetime singleton every global middleware is —
 * the same criterion Kinetis\Http\Middleware\RateLimitMiddleware's own
 * docblock already establishes.
 *
 * Sits inside Kernel's own always-outermost ExceptionHandlerMiddleware:
 * this catches AuthorizationException specifically and turns it into a
 * clean 403 before it ever reaches that outer, generic 500 handler.
 */
final class AuthorizationExceptionMiddleware implements MiddlewareInterface
{
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthorizationException $e) {
            return ErrorResponse::create(403, $e->getMessage());
        }
    }
}
