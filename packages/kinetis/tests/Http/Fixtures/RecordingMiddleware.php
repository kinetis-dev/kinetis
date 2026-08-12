<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records its own class name into a shared, static log every time
 * process() runs, then delegates to the next handler — lets tests assert
 * the exact order a pipeline actually ran in. Subclassed rather than
 * parameterized so each recorded identity is distinguishable without
 * needing a constructor argument the container would have to supply,
 * since #[Middleware] only ever stores a bare class-string.
 */
class RecordingMiddleware implements MiddlewareInterface
{
    /** @var list<class-string> */
    public static array $log = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$log[] = static::class;

        return $handler->handle($request);
    }
}
