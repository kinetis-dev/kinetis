<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Form\Exception\FormLimitExceededException;
use Kinetis\Http\Form\Exception\UnparseableFormBodyException;
use Kinetis\Http\Form\FormBody;
use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\StagedRequestBody;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Runtime\RuntimeAdapterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The single place a request body becomes something a handler can use.
 * Registered unconditionally as global middleware by
 * {@see GlobalMiddlewareOrder}, inside ExceptionHandlerMiddleware and
 * outside every application middleware, so nothing downstream — a
 * consumer's own middleware included — ever sees a body that has not
 * been through it.
 *
 * Runtime adapters do not do any of this. Each one turns its transport
 * into a raw PSR-7 request and stops there, which is what makes the body
 * contract a single implementation rather than one per runtime: a form
 * the same client sends to a FrankenPHP worker, a Lambda function and a
 * RoadRunner worker is accepted by all three or refused by all three,
 * with the same status and the same message, because all three run this
 * class over the same bytes.
 *
 * Three things happen here, in order.
 *
 * The declared `Content-Length` is checked first, so an honestly-labeled
 * oversized request is refused without being read. Then the body is
 * staged — read once, incrementally, counted, into a seekable temporary
 * stream — and rewound. Then, for the media types that arrive as a form
 * rather than as raw bytes, it is parsed and attached as
 * `getParsedBody()`/`getUploadedFiles()`.
 *
 * Staging happens for every request, not only for forms, and it is what
 * lets everything downstream see one body and one length. The staged
 * stream is complete, seekable and replayable, and no way of reading it
 * can fail: by the time a handler reaches it there is no limit left to
 * enforce. `read()` and `getContents()` still answer from wherever the
 * cursor stands, so a consumer that needs the whole body casts to
 * string — which rewinds first — or rewinds explicitly. Reading it in
 * full is what a counting stream wrapper cannot make safe: `Stringable`
 * forbids `__toString()` from throwing, so such a wrapper has to answer
 * a cast with an empty string once the cap is crossed — which a handler,
 * or any vendor middleware in between, reads as an absent optional body
 * and carries on with. A raw or binary body reaches the handler
 * untouched apart from being staged, and a body this class parsed is
 * still readable in full afterwards, rewound.
 *
 * Two answers to a bad body, and only two. One that cannot be parsed is
 * a `400` carrying the fixed
 * {@see RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE} — never the
 * parser's own text, which is assembled from the input that failed; see
 * {@see UnparseableFormBodyException}. One too large or too complicated
 * is a `413` naming the ceiling it met and nothing from the request.
 * Anything else — a temporary stream that would not open, a bug —
 * propagates to ExceptionHandlerMiddleware, because it is this worker's
 * failure and not a client's.
 *
 * Takes the {@see FormLimits} the entry point already bound, rather than
 * reading `Config` again, so `bootstrap.php` replacing that binding
 * moves every one of these ceilings at once.
 */
final class RequestBodyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly FormLimits $limits,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $staged = StagedRequestBody::stage($request->getBody(), $this->limits, self::declaredContentLength($request));
            $request = FormBody::apply($request->withBody($staged), $this->limits);
        } catch (BodyTooLargeException|FormLimitExceededException $e) {
            // Safe to return as written: a limit message names a
            // configured ceiling and never anything from the request.
            return ErrorResponse::create(413, $e->getMessage());
        } catch (UnparseableFormBodyException $e) {
            // The category, never the message: see that class for why a
            // parser's own text can never reach a log line either.
            error_log('Malformed request body: ' . $e->category);

            return ErrorResponse::create(400, RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE);
        }

        return $handler->handle($request);
    }

    /**
     * The declared length, when the client declared one this framework
     * can act on. A header that is absent, or carries anything but a
     * non-negative integer, yields null: an unusable declaration is the
     * same as no declaration, and the actual byte count bounds the
     * request either way.
     */
    private static function declaredContentLength(ServerRequestInterface $request): ?int
    {
        $declared = $request->getHeaderLine('Content-Length');

        return ctype_digit($declared) ? (int) $declared : null;
    }
}
