<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware;

use Kinetis\Http\Form\FormLimits;
use Kinetis\Http\Form\StagedRequestBody;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
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
 * nothing bounds how large a request body is before #[Body] reads the
 * whole thing into memory and json_decode()s it.
 *
 * The body is settled before the handler, never during it. The declared
 * Content-Length is checked first, so an honestly-labeled oversized
 * request is refused without being read at all; then the body is staged
 * — read once, incrementally, counted, into a replayable temporary
 * stream — and the request the handler receives carries that stream,
 * rewound and complete. Over the ceiling is a 413 and the handler never
 * runs.
 *
 * Everything downstream therefore sees one body and one length.
 * `read()`, `getContents()` and a plain `(string)` cast all return the
 * identical accepted bytes, because by the time any of them is called
 * there is no limit left to enforce and nothing that can fail. That is
 * the property a counting stream wrapper cannot have: `Stringable`
 * forbids `__toString()` from throwing, so such a wrapper has to answer
 * a cast with an empty string once the cap is crossed — which a handler,
 * or any vendor middleware in between, reads as an absent optional body
 * and carries on with. See {@see StagedRequestBody} for the full
 * reasoning: a handler here may read the body any way it likes.
 *
 * What this does not reach is a form body parsed before the Kernel and
 * its pipeline exist — a multipart/form-data or
 * application/x-www-form-urlencoded body arriving as an already-populated
 * parsed-body array. That body is bounded by the same
 * Kinetis\Http\Form\FormLimits instance, in the adapter, against the same
 * byte ceiling plus the complexity ceilings a byte count cannot express.
 * A separate boundary from the one this class enforces, not a gap in it;
 * see docs/runtime-adapters.md for which limit binds where.
 *
 * Takes the FormLimits the entry point already built — the same instance
 * the runtime adapter was constructed with — rather than reading Config
 * again, so the byte cap a form body met before the Kernel existed and
 * the one a raw body meets here cannot be two different numbers.
 */
final class MaxBodySizeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly FormLimits $limits,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $staged = StagedRequestBody::stage($request->getBody(), $this->limits, self::declaredContentLength($request));
        } catch (BodyTooLargeException $e) {
            return ErrorResponse::create(413, $e->getMessage());
        }

        return $handler->handle($request->withBody($staged));
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
