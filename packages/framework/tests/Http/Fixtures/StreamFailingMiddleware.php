<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * Runs the handler, keeps the response it took delivery of, and then
 * throws. Stands in for SecurityHeadersMiddleware, the one global
 * position outside ExceptionHandlerMiddleware, so the failure leaves
 * Kernel::handle() rather than being turned into a 500 on the way out.
 */
final readonly class StreamFailingMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Fail-After-Stream';

    public const string MESSAGE = 'the outermost middleware failed after taking delivery';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$request->hasHeader(self::HEADER)) {
            return $response;
        }

        StreamProbe::$displaced = $response;

        throw new RuntimeException(self::MESSAGE);
    }
}
