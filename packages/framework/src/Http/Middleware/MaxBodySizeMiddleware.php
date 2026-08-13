<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Config\Config;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Middleware\Support\SizeLimitedStream;
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
 * Two layers. The declared Content-Length header, checked first: a
 * cheap rejection for an honestly-labeled oversized request before the
 * body is touched at all. Underneath that, every request's body is
 * wrapped in a SizeLimitedStream enforcing the same cap against the
 * actual bytes read — catching a request with no Content-Length header,
 * or an inaccurate one, that the first check alone would miss.
 *
 * Only the raw #[Body] JSON path is guarded this way. A
 * multipart/form-data or application/x-www-form-urlencoded body is
 * already parsed by the SAPI before Kinetis code reads it, bounded by
 * PHP's own upload_max_filesize/post_max_size instead.
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
            return $this->tooLargeResponse();
        }

        $limitedRequest = $request->withBody(new SizeLimitedStream($request->getBody(), $this->maxBytes));

        try {
            return $handler->handle($limitedRequest);
        } catch (BodyTooLargeException) {
            return $this->tooLargeResponse();
        }
    }

    private function tooLargeResponse(): ResponseInterface
    {
        return ErrorResponse::create(413, BodyTooLargeException::exceeds($this->maxBytes)->getMessage());
    }
}
