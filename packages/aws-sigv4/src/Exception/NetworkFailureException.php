<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The signed request never got an answer: the connection could not be
 * made, was lost, or timed out. PSR-18 requires this case to be
 * distinguishable from a failure about the request itself — retrying is
 * meaningful here and pointless there — so it is its own type
 * implementing `NetworkExceptionInterface` rather than a variant message
 * on {@see TransportFailureException}.
 *
 * The distinction is the transport's own: this package owns a PSR-18
 * boundary, and the classification that boundary makes is the one
 * carried across. Nothing else about the underlying failure survives —
 * see {@see ClientFailureException} for the request, message, cause, and
 * serialization rules every failure in this namespace shares.
 *
 * Retrying is the caller's decision, and it costs a fresh signature:
 * this package signs one request per `sendRequest()` call and never
 * replays one.
 */
final class NetworkFailureException extends ClientFailureException implements NetworkExceptionInterface
{
    public const string MESSAGE
        = 'The signed request could not be completed over the network.';

    public static function forRequest(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::MESSAGE);
    }
}
