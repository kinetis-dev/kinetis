<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Config\Config;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Registered unconditionally as global middleware by Kernel, right after
 * ExceptionHandlerMiddleware — the same "costs nothing when nothing's
 * wrong, closes a real gap when something does" reasoning
 * TransactionGuard::rollbackDangling() already uses. Without this,
 * nothing checks how large a request body is before #[Body] reads the
 * whole thing into memory and json_decode()s it.
 *
 * Checks the declared Content-Length header only, not the actual bytes
 * read as they're read — a client sending an inaccurate or absent
 * Content-Length falls outside this check's scope; closing that would
 * need a stream-wrapping decorator around the request body, a materially
 * bigger change than the declared-size check built here.
 *
 * Resolved through the container (see Kernel), so $maxBytes is read once
 * per worker boot from Config, not re-read on every request.
 */
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    private readonly int $maxBytes;

    public function __construct(Config $config)
    {
        $this->maxBytes = $config->int('MAX_BODY_SIZE', 2_097_152);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentLength = $request->getHeaderLine('Content-Length');

        if ($contentLength !== '' && is_numeric($contentLength) && (int) $contentLength > $this->maxBytes) {
            return ErrorResponse::create(
                413,
                "Request body of {$contentLength} bytes exceeds the maximum allowed size of {$this->maxBytes} bytes.",
            );
        }

        return $handler->handle($request);
    }
}
