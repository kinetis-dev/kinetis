<?php

declare(strict_types=1);

namespace Kinetis\BrefAdapter\Exception;

use RuntimeException;

final class BrefAdapterException extends RuntimeException
{
    public static function streamingNotSupported(): self
    {
        return new self(
            'BrefLambdaAdapter cannot emit a streaming response — the Lambda Runtime API '
            . 'supports exactly one response payload per invocation, with no mechanism for '
            . 'incremental delivery.',
        );
    }

    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open a php://temp stream to parse a multipart body.');
    }

    /**
     * The event declared isBase64Encoded: true but $body isn't valid
     * base64 — reported rather than silently treated as an empty body,
     * which would otherwise be indistinguishable from a genuinely empty
     * one and from the valid decoded values "" and "0".
     */
    public static function malformedBase64Body(): self
    {
        return new self(
            'The Lambda event declared isBase64Encoded: true, but the body is not valid base64.',
        );
    }

    public static function runtimeApiUnreachable(string $url): self
    {
        return new self("Could not reach the Lambda Runtime API at {$url}.");
    }

    public static function runtimeApiRequestFailed(string $url, ?int $status, string $body): self
    {
        $statusDescription = $status !== null ? (string) $status : 'no parseable status';

        return new self(
            "The Lambda Runtime API request to {$url} failed with HTTP {$statusDescription}. Response: {$body}",
        );
    }

    /**
     * The Runtime API's own invocation event body failed to decode as
     * JSON, or decoded to something other than a JSON array/object — a
     * protocol-boundary failure, not application input. Thrown so it's
     * posted to the invocation error endpoint rather than silently
     * becoming an empty, plausible-looking GET / request that reaches
     * application routing.
     */
    public static function malformedInvocationEvent(string $reason): self
    {
        return new self("The Lambda Runtime API's invocation event is malformed: {$reason}");
    }
}
