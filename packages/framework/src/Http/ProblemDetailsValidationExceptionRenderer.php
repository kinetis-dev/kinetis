<?php

declare(strict_types=1);

namespace Kinetis\Http;

use Kinetis\Validation\Exception\ValidationException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default validation response: an RFC 9457 problem details document
 * at 422, carrying the failure's own violations as an `errors`
 * extension member.
 *
 * ```json
 * {
 *     "type": "about:blank",
 *     "title": "Unprocessable Content",
 *     "status": 422,
 *     "detail": "The request data failed validation.",
 *     "errors": [
 *         {
 *             "path": ["items", 0, "quantity"],
 *             "code": "constraint",
 *             "message": "must be greater than 0.",
 *             "parameters": {"constraint": "Kinetis\\Validation\\Constraints\\GreaterThan"}
 *         }
 *     ]
 * }
 * ```
 *
 * `type` is `about:blank` because Kinetis cannot own a problem-type URI
 * an application would resolve, and 422 already carries the generic
 * semantics; `title` is IANA's registered name for that status. The
 * `detail` line is fixed and says nothing about the payload — every
 * per-field fact is in `errors`, where a client can act on it.
 *
 * Each error is one {@see \Kinetis\Validation\Violation} in its own
 * serialized form: a segmented `path` (source-neutral, so it stays
 * truthful for a query, path, form or multipart value, where a JSON
 * Pointer would not), a stable `code`, the default-English `message`,
 * and a `parameters` object.
 *
 * Stateless and immutable: this is the constructor default
 * {@see Middleware\ExceptionHandlerMiddleware} carries, so an
 * application replaces it by binding
 * {@see ValidationExceptionRendererInterface} before `AppScope::boot()`
 * and registers nothing otherwise.
 *
 * JSON_INVALID_UTF8_SUBSTITUTE covers the whole document: a message or
 * parameter that is not valid UTF-8 — an application constraint
 * quoting raw client bytes back, say — degrades to substitute
 * characters rather than throwing from inside the response this class
 * exists to produce.
 */
final readonly class ProblemDetailsValidationExceptionRenderer implements ValidationExceptionRendererInterface
{
    private const int STATUS = 422;

    #[\Override]
    public function render(ValidationException $exception, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(
            status: self::STATUS,
            headers: ['Content-Type' => 'application/problem+json'],
            body: json_encode([
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => self::STATUS,
                'detail' => 'The request data failed validation.',
                'errors' => $exception->violations,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }
}
