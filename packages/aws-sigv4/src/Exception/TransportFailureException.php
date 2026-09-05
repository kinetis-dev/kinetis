<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Message\RequestInterface;
use SensitiveParameter;

/**
 * The transport rejected the signed request for a reason that is not the
 * network: an option or a URL it would not accept. A connection that
 * could not be made, was lost, or timed out is
 * {@see NetworkFailureException} instead.
 *
 * The transport's own exception carries the signed request — its
 * `Authorization` and `X-Amz-Security-Token` headers included — through
 * `RequestExceptionInterface::getRequest()`, so it is replaced here
 * rather than rethrown. See {@see ClientFailureException} for the
 * message, cause, and serialization rules every failure in this
 * namespace shares.
 */
final class TransportFailureException extends RequestFailureException
{
    public const string MESSAGE
        = 'The HTTP transport rejected the signed request.';

    public static function forRequest(#[SensitiveParameter] RequestInterface $request): self
    {
        return new self($request, self::MESSAGE);
    }
}
