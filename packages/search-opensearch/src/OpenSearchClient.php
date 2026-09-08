<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\Search\AbstractSearchClient;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchCall;
use Kinetis\Search\SearchClient;
use OpenSearch\Client;
use OpenSearch\Exception\HttpExceptionInterface;

/**
 * {@see SearchClient} over the official OpenSearch\Client: the five calls
 * an application can make against either engine, in this engine's terms.
 *
 * Only this engine's half of a call happens here. The parameters are
 * {@see AbstractSearchClient}'s, opensearch-php builds the request and
 * reads the response from them, and an HTTP error status the client
 * raised becomes a {@see SearchRequestException}; a request that never
 * completed is already a SearchNetworkException by the time it reaches
 * this class. Every response body is the cluster's own, untouched.
 *
 * The wrapped client stays available: kinetis/search-opensearch binds
 * OpenSearch\Client too, and an application that needs anything outside
 * these five calls injects that instead.
 */
final readonly class OpenSearchClient extends AbstractSearchClient
{
    public function __construct(private Client $client)
    {
    }

    #[\Override]
    protected function send(SearchCall $call, array $params): array
    {
        try {
            /** @var array<string, mixed> */
            return match ($call) {
                SearchCall::Index => $this->client->index($params),
                SearchCall::Get => $this->client->get($params),
                SearchCall::Delete => $this->client->delete($params),
                SearchCall::Search => $this->client->search($params),
                SearchCall::Bulk => $this->client->bulk($params),
            };
        } catch (HttpExceptionInterface $e) {
            throw SearchRequestException::status($e->getStatusCode(), $e);
        }
    }
}
