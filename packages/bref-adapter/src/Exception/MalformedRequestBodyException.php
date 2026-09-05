<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Exception;

use RuntimeException;

/**
 * The one body failure that is Lambda's alone: an event declaring
 * `isBase64Encoded: true` over something that isn't base64. Everything
 * further in — a form body that will not parse, a form past a limit —
 * is expressed in the framework's own shared vocabulary
 * ({@see \Kinetis\Http\Form\Exception\UnparseableFormBodyException},
 * {@see \Kinetis\Http\Form\Exception\FormLimitExceededException}), so
 * those failures mean the same thing and reach a client the same way
 * under every runtime.
 *
 * The client's fault, not a protocol-boundary failure with the Runtime
 * API, and the distinction decides what the client sees:
 * {@see \Kinetis\BrefAdapter\BrefLambdaAdapter::handleEvent()} answers
 * this with a plain 400, whereas a Runtime API failure is posted as an
 * invocation error (a 502 from API Gateway). The message is logged and
 * never returned: it can echo a fragment of the input.
 */
final class MalformedRequestBodyException extends RuntimeException
{
    /**
     * The event declared isBase64Encoded: true but $body isn't valid
     * base64 — reported rather than silently treated as an empty body,
     * which would otherwise be indistinguishable from a genuinely empty
     * one and from the valid decoded values "" and "0".
     */
    public static function invalidBase64(): self
    {
        return new self('The Lambda event declared isBase64Encoded: true, but the body is not valid base64.');
    }
}
