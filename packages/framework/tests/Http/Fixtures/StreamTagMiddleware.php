<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Container\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Publishes the request's own X-Tag header on its RequestScope, the way
 * a real auth middleware publishes a resolved identity.
 */
final readonly class StreamTagMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->scope->instance(StreamTagInterface::class, new StreamTag($request->getHeaderLine('X-Tag')));

        return $handler->handle($request);
    }
}
