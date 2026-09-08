<?php

declare(strict_types=1);

namespace Kinetis\Tests\Runtime\Fixtures\StartupProject\Http;

use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps every response it wraps, so a test can tell from the response
 * alone whether the discovered global middleware list reached the Kernel.
 */
#[AsGlobalMiddleware]
final class StartupStampMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Startup-Stamp';

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader(self::HEADER, 'ran');
    }
}
