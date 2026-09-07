<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * What every per-request failure `SigV4SigningClient` raises has in
 * common. Not `final` — it exists to be extended, and PSR-18 splits its
 * failures in two, so {@see RequestFailureException} and
 * {@see NetworkFailureException} sit underneath it and the concrete,
 * `final` failures sit under those.
 *
 * PSR-18 accessor: `getRequest()` returns the request the caller handed
 * to `sendRequest()`, never a resolved, normalized or signed one, so a
 * signed `Authorization` or `X-Amz-Security-Token` header has no path
 * out through it.
 *
 * Message and cause: the message is one of this namespace's fixed
 * category strings, and no cause is chained. A credential provider, URI
 * parser, signer, or transport `Throwable` carries endpoint text, token
 * file contents, OpenSSL detail, or the signed request itself in its own
 * message and trace, and a chained cause reaches every ordinary error
 * channel — `(string) $e`, PSR-3 normalization, a `getPrevious()` walk.
 * Those causes are discarded rather than stored; diagnose transport
 * problems through the transport's own logger.
 */
abstract class ClientFailureException extends RuntimeException implements ClientExceptionInterface
{
    protected function __construct(
        #[SensitiveParameter] private readonly RequestInterface $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
