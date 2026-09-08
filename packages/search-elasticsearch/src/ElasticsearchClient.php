<?php

declare(strict_types=1);

namespace Kinetis\SearchElasticsearch;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Elastic\Transport\Exception\NoNodeAvailableException;
use Kinetis\Search\BulkOperation;
use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchRequestException;
use Kinetis\Search\SearchClient;
use LogicException;

/**
 * {@see SearchClient} over the official Elastic\Elasticsearch\Client: the
 * five calls an application can make against either engine, in this
 * engine's terms.
 *
 * Only mapping happens here. A parameter array is assembled the way
 * elasticsearch-php expects it, the response object it answers with is
 * read as the array both engines' envelopes share, an error status
 * becomes either an ordinary absent-document answer or a
 * {@see SearchRequestException}, and a request that never completed
 * becomes the same {@see SearchNetworkException} the other engine
 * reports. Every response body is the cluster's own, untouched.
 *
 * The wrapped client stays available: kinetis/search-elasticsearch binds
 * Elastic\Elasticsearch\Client too, and an application that needs
 * anything outside these five calls — ES|QL, index management, the bulk
 * helper — injects that instead.
 */
final readonly class ElasticsearchClient implements SearchClient
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
     * where the cluster's error text lives. Both of the client's
     * response exceptions carry the status as their code.
     *
     * A request that never completed is a {@see SearchNetworkException}
     * here as it is on the other engine, which takes unwrapping:
     * Elastic\Transport\Transport reports one as a
     * NoNodeAvailableException over the exception that actually
     * happened. Anything else that pool raises is left as itself.
     *
     * @param callable(): mixed $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ClientResponseException | ServerResponseException $e) {
            throw SearchRequestException::status($e->getCode(), $e);
        } catch (NoNodeAvailableException $e) {
            throw $e->getPrevious() instanceof SearchNetworkException ? $e->getPrevious() : $e;
        }

        if (!$response instanceof Elasticsearch) {
            // Only reachable through setAsync(true), which this package
            // never sets: the client answers a promise there. Inventing
            // an envelope would make delete() report a deletion.
            throw new LogicException('The Elasticsearch client answered no response object.');
        }

        return $response->asArray();
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
