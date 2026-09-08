<?php

declare(strict_types=1);

namespace Kinetis\Search\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A request that never produced a complete response: the connection, the
 * deadline, or the response-size limit ended it. Every status the
 * cluster does answer with, 4xx and 5xx included, stays the engine
 * client's to map.
 *
 * PSR-18 requires a network failure to carry the request that caused it,
 * so the request travels here. The message is fixed and the transport's
 * own exception is the previous one, where the URL and the reason live;
 * a search host is rejected outright when it carries userinfo, so no
 * credential can reach that chain through the URL.
 *
 * An engine transport that retries on PSR-18's NetworkExceptionInterface
 * would replay a request whose dispatch outcome is unknown. That retry
 * is disabled where the engine client is built, not hidden by throwing
 * something narrower here.
 */
final class SearchNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        Throwable $previous,
    ) {
        parent::__construct('The search request did not complete.', 0, $previous);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
