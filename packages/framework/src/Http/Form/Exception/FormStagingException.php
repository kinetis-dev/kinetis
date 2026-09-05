<?php

declare(strict_types=1);

namespace Kinetis\Http\Form\Exception;

use RuntimeException;
use Throwable;

/**
 * Staging a request body failed on this side of the wire — a temporary
 * stream that could not be opened, a read that stalled, a write that
 * stopped short, a handle that could not be closed. Infrastructure, not
 * client input: the same request would succeed on a healthy worker, so
 * it is never a `400`/`413`. Inside the Kernel it reaches
 * `ExceptionHandlerMiddleware` like any other server-side failure; in an
 * adapter, before the Kernel exists, each one lets it reach its own
 * worker-level failure path ({@see \Kinetis\BrefAdapter\BrefLambdaAdapter}'s
 * invocation error, RoadRunner's `Worker::error()`), where it is logged
 * and the client gets an opaque server error.
 */
final class FormStagingException extends RuntimeException
{
    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open a php://temp stream to parse a form body.');
    }

    /**
     * A write returned `false`, or accepted zero bytes while bytes were
     * still outstanding. Either way the staged copy is a prefix of the
     * body, and a prefix of a multipart body parses into a plausible,
     * silently incomplete form — which is exactly what must never reach
     * a handler.
     */
    public static function bodyWriteFailed(int $written, int $total): self
    {
        return new self("Failed to stage a form body for parsing: wrote {$written} of {$total} bytes.");
    }

    /**
     * A body stream reported more to come and then handed back nothing.
     * The staged copy is a prefix of the request, so it is refused for
     * the same reason a short write is: a prefix of a form body parses
     * into a plausible, silently incomplete form.
     */
    public static function bodyReadStalled(int $read): self
    {
        return new self("Failed to read a request body: the stream stopped yielding bytes after {$read} of them.");
    }

    public static function couldNotCloseTempStream(?Throwable $previous = null): self
    {
        return new self('Failed to close the php://temp stream a form body was staged in.', previous: $previous);
    }
}
