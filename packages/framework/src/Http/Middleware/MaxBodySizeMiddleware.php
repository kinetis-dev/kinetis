<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use InvalidArgumentException;
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
 * The backstop protects *any* code that reads this request's body
 * stream via read() or getContents() — not only the raw #[Body] JSON
 * path Dispatcher itself hydrates. `Kinetis\Mcp\Http\McpController`'s
 * `/mcp` endpoint and `Kinetis\Broadcasting\Http\BroadcastAuthController`'s
 * raw application/x-www-form-urlencoded fallback both read the body the
 * same way and are covered by the identical cap — the requirement is
 * simply "call getContents(), never cast the stream to a string":
 * SizeLimitedStream::__toString() cannot throw (the interface it
 * implements forbids it) and silently reports an empty string once the
 * cap is exceeded, which would turn a real oversized-body rejection
 * into a misleading application-level error instead of this
 * middleware's own 413.
 *
 * What this backstop does *not* reach is a body a runtime already
 * parsed before Kinetis code ever sees it: a multipart/form-data or
 * application/x-www-form-urlencoded body handed to Kinetis as an
 * already-populated parsed-body array — by the SAPI under FrankenPHP
 * and FPM, bounded by PHP's own upload_max_filesize/post_max_size; by
 * the adapter itself under kinetis/bref-adapter, where no SAPI limit
 * exists and the only cap is the platform's own invocation payload size
 * (6 MB on AWS Lambda); and by the adapter itself under
 * kinetis/roadrunner-adapter too, where the real defense is RoadRunner's
 * own http.max_request_size setting, not this middleware. That's a
 * separate boundary from the one this class enforces, not a gap in it —
 * BroadcastAuthController's own raw-body fallback only runs when the
 * runtime never parsed the body in the first place, which is exactly
 * why it needs (and gets) this middleware's own protection directly.
 *
 * Resolved through the container (see Kernel), so $maxBytes is read once
 * per worker boot from Config, not re-read on every request.
 */
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    private readonly int $maxBytes;

    public function __construct(Config $config)
    {
        $maxBytes = $config->int('MAX_BODY_SIZE', 2_097_152);

        if ($maxBytes < 1) {
            throw new InvalidArgumentException("MAX_BODY_SIZE must be a positive number of bytes, got {$maxBytes}.");
        }

        $this->maxBytes = $maxBytes;
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
