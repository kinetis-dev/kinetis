<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The request target does not resolve to the trusted origin
 * `SigV4SigningClient` was configured with — a different scheme, host,
 * or effective port, a network-path reference, userinfo, a target
 * carrying a control character or a backslash, or a path that leaves the
 * configured base path once its dot segments are resolved.
 *
 * Raised before the credential provider is called, before the request
 * body is read, and before anything is handed to the transport, so a
 * rejected request costs no credential resolution and produces no
 * network traffic. See {@see ClientFailureException} for the message,
 * cause, and serialization rules every failure in this namespace shares.
 */
final class UntrustedOriginException extends RequestFailureException
{
    public const string MESSAGE
        = 'The request target does not match the configured trusted origin.';

    public static function forRequest(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::MESSAGE);
    }
}
