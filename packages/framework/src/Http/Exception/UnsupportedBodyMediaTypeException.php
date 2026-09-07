<?php

declare(strict_types=1);

namespace Kinetis\Http\Exception;

use RuntimeException;

/**
 * A `#[Body]`-bound route was sent a nonblank body under a media type
 * the framework does not read: anything outside `application/json`, an
 * `application/*+json` subtype, `application/x-www-form-urlencoded` and
 * `multipart/form-data`.
 *
 * Raised and caught inside {@see \Kinetis\Http\Dispatcher}, which owns
 * the 415 it maps to — never handled by global middleware, since the
 * status belongs to the typed-body binding rather than to the request as
 * a whole. The message is fixed and names the supported media types; it
 * never echoes the header received, which is client-controlled text.
 */
final class UnsupportedBodyMediaTypeException extends RuntimeException
{
    public static function forTypedBody(): self
    {
        return new self(
            'Request body media type is not supported. Send application/json, an application/*+json '
            . 'subtype, application/x-www-form-urlencoded, or multipart/form-data.',
        );
    }
}
