<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An authentication gate on the documentation routes: records that it
 * ran, then answers 401 without calling the handler.
 */
final class DenyingOpenApiMiddleware extends RecordingMiddleware
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$log[] = self::class;

        return ErrorResponse::create(401, 'Authentication required.');
    }
}
