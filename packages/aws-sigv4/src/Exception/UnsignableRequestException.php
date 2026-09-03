<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * Thrown by `SigV4SigningClient::sendRequest()` for any failure that
 * happens while resolving credentials, resolving the request URI,
 * capturing a replayable body, or signing — i.e. everything this class
 * itself does before ever delegating to the wrapped PSR-18 client.
 * Implements `Psr\Http\Client\RequestExceptionInterface` (which extends
 * `ClientExceptionInterface`) specifically because `SigV4SigningClient`
 * itself implements `Psr\Http\Client\ClientInterface`: a caller catching
 * PSR-18 exceptions around `sendRequest()` must be able to catch a
 * failure produced by this decorator's own processing too, not only one
 * from the wrapped client.
 *
 * The message is a fixed, non-secret string — never the request's own
 * URI, body, or anything credential-related — since a caller catching
 * and logging this exception must not have any of that copied into a
 * log by doing so. The real cause (a `SigningException`, a
 * `StreamException`, an AsyncAws-native exception, or whatever the
 * configured `CredentialProvider` itself throws) is always preserved as
 * `getPrevious()`, for a caller or log that specifically wants it.
 *
 * Deliberately never thrown around the wrapped client's own
 * `sendRequest()` call — that call's real `NetworkException`/
 * `RequestException`/`ClientException` must reach the caller by
 * identity, completely unmodified, exactly as PSR-18 already guarantees
 * for any ordinary PSR-18 client.
 */
final class UnsignableRequestException extends RuntimeException implements RequestExceptionInterface
{
    /**
     * Exposed so callers/tests can compare against it symbolically rather
     * than retyping the literal — this string never varies per failure,
     * which is itself the whole point: no request/credential detail ever
     * reaches it.
     */
    public const string MESSAGE
        = 'The request could not be prepared and signed before being delegated to the wrapped HTTP client.';

    private function __construct(
        private readonly RequestInterface $request,
        Throwable $previous,
    ) {
        parent::__construct(self::MESSAGE, 0, $previous);
    }

    /**
     * $request is the original, caller-supplied request handed to
     * `sendRequest()` — not whatever partially-transformed request this
     * class had reached by the point of failure — since that's the only
     * one the caller itself actually constructed and can meaningfully
     * correlate this exception back to. PSR-18's own
     * `RequestExceptionInterface` docblock explicitly permits either.
     */
    public static function causedBy(RequestInterface $request, Throwable $previous): self
    {
        return new self($request, $previous);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
