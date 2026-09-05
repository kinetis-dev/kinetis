<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use RuntimeException;

/**
 * A proxy this application trusts sent a forwarded header it could not
 * read. Trusted, so the value is not the client's to choose and cannot
 * be ignored the way an untrusted peer's is; unreadable, so there is no
 * scheme to act on. Answered with the same fixed `400` a body that
 * cannot be parsed gets, before the handler runs.
 *
 * The message names the header and nothing from it: the value came from
 * the edge, but an edge can pass a client's own bytes through, so it is
 * treated as attacker-controlled like any other header.
 */
final class UntrustedForwardedHeaderException extends RuntimeException
{
    public static function unreadableScheme(): self
    {
        return new self('A trusted proxy sent an X-Forwarded-Proto header that names neither http nor https.');
    }
}
