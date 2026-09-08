<?php

declare(strict_types=1);

namespace Kinetis\SearchOpenSearch;

use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchClient;
use OpenSearch\Client;
use OpenSearch\Exception\HttpExceptionInterface;

/**
 * {@see SearchClient} over the official OpenSearch\Client: the five calls
 * an application can make against either engine, in this engine's terms.
 *
 * Only mapping happens here. A parameter array is assembled the way
 * opensearch-php expects it, and an HTTP error status the client raised
 * becomes either an ordinary absent-document answer or a
 * {@see SearchRequestException}; a request that never completed is
 * already a SearchNetworkException by the time it reaches this class.
 * Every response body is the cluster's own, untouched.
 *
 * The wrapped client stays available: kinetis/search-opensearch binds
 * OpenSearch\Client too, and an application that needs anything outside
 * these five calls injects that instead.
 */
final readonly class OpenSearchClient implements SearchClient
{
    public function __construct(private Client $client)
    {
    }

    #[\Override]
    public function index(string $index, ?string $id, array $document, bool $refresh = false): array
    {
        $params = ['index' => $index, 'body' => $document];

        if ($id !== null) {
            $params['id'] = $id;
        }

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        return $this->send(fn (): mixed => $this->client->index($params));
    }

    #[\Override]
    public function get(string $index, string $id): ?array
    {
        return $this->sendAllowingAbsence(fn (): mixed => $this->client->get(['index' => $index, 'id' => $id]));
    }

    #[\Override]
    public function delete(string $index, string $id, bool $refresh = false): bool
    {
        $params = ['index' => $index, 'id' => $id];

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        return $this->sendAllowingAbsence(fn (): mixed => $this->client->delete($params)) !== null;
    }

    #[\Override]
    public function search(string $index, array $body): array
    {
        return $this->send(fn (): mixed => $this->client->search(['index' => $index, 'body' => $body]));
    }

    #[\Override]
    public function bulk(array $operations, bool $refresh = false): array
    {
        $params = ['body' => BulkOperation::body($operations)];

        if ($refresh) {
            $params['refresh'] = 'true';
        }

        return $this->send(fn (): mixed => $this->client->bulk($params));
    }

    /**
     * Every error status the cluster answered with is a
     * {@see SearchRequestException} carrying the client's own exception,
     * where the cluster's error text lives.
     *
     * @param callable(): mixed $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            /** @var array<string, mixed> */
            return $call();
        } catch (HttpExceptionInterface $e) {
            throw SearchRequestException::status($e->getStatusCode(), $e);
        }
    }

    /**
     * The same, for the two calls that name one document: a 404 there is
     * an answer rather than a failure, whether the index or only the id
     * is the part that does not exist.
     *
     * @param callable(): mixed $call
     * @return array<string, mixed>|null
     */
    private function sendAllowingAbsence(callable $call): ?array
    {
        try {
            return $this->send($call);
        } catch (SearchRequestException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }
}
