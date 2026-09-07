<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

/**
 * A request that never produced a complete response: the connection, the
 * deadline, or the response-size limit ended it. It is the one failure
 * kinetis/search-opensearch reports itself; every status OpenSearch does
 * answer with, 4xx and 5xx included, stays the official client's to map.
 *
 * PSR-18 requires a network failure to carry the request that caused it,
 * so the request travels here. The message is fixed and the transport's
 * own exception is the previous one, where the URL and the reason live;
 * SEARCH_OPENSEARCH_HOST is rejected outright when it carries userinfo,
 * so no credential can reach that chain through the URL.
 */
final class OpenSearchNetworkException extends RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        Throwable $previous,
    ) {
        parent::__construct('The OpenSearch request did not complete.', 0, $previous);
    }

    #[\Override]
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
