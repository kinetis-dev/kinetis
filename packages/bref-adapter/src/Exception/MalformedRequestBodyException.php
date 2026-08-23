<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Exception;

use RuntimeException;

/**
 * The client's request body could not be turned into a request — the
 * one class of conversion failure that is the *client's* fault rather
 * than a protocol-boundary failure with the Runtime API. The
 * distinction decides what the client sees: {@see \Kinetis\BrefAdapter\BrefLambdaAdapter::handleEvent()}
 * answers this with a plain 400 response, the same one
 * SuperglobalsBridge gives under FrankenPHP/FPM, whereas any other
 * failure is posted as an invocation error (a 502 from API Gateway).
 * The message is logged and never returned: it can echo a fragment of
 * the input.
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

    public static function unparseableMultipart(string $reason): self
    {
        return new self("The multipart/form-data body could not be parsed: {$reason}");
    }
}
