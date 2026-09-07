<?php

declare(strict_types=1);

namespace Kinetis\Http\Form\Exception;

use RuntimeException;

/**
 * Staging a request body failed on this side of the wire — a temporary
 * stream that could not be opened, a read that stalled, a write that
 * stopped short. Infrastructure, not client input: the same request
 * would succeed on a healthy worker, so it is never a `400`/`413`. It
 * reaches `ExceptionHandlerMiddleware` like any other server-side
 * failure, where it is logged and the client gets an opaque server
 * error.
 *
 * Every request body is staged, not only a form one, which is what each
 * message below names.
 */
final class FormStagingException extends RuntimeException
{
    public static function couldNotOpenTempStream(): self
    {
        return new self('Failed to open an in-memory stream to stage a request body.');
    }

    /**
     * A write returned `false`, or accepted zero bytes while bytes were
     * still outstanding. Either way the staged copy is a prefix of the
     * body, and a prefix is what must never reach a handler: a truncated
     * multipart body parses into a plausible, silently incomplete form,
     * and truncated raw bytes are a payload the client never sent.
     */
    public static function bodyWriteFailed(int $written, int $total): self
    {
        return new self("Failed to stage a request body: wrote {$written} of {$total} bytes.");
    }

    /**
     * A body stream reported more to come and then handed back nothing.
     * The staged copy is a prefix of the request, so it is refused for
     * the same reason a short write is.
     */
    public static function bodyReadStalled(int $read): self
    {
        return new self("Failed to read a request body: the stream stopped yielding bytes after {$read} of them.");
    }
}
