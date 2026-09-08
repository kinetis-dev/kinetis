<?php

declare(strict_types=1);

namespace Kinetis\Search;

use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchRequestException;

/**
 * The half of a {@see SearchClient} that is the same on either engine:
 * the parameters each of the five calls is made with, and the absence
 * rule the two calls that name one document share.
 *
 * An adapter supplies the other half — one {@see SearchCall} sent
 * through its own engine client, and that engine's failures mapped to
 * this package's. Nothing here names either engine's library, which is
 * what lets both engine packages depend on this one and neither the
 * other way around.
 *
 * Both engines take the same parameter keys for these five calls, which
 * is why the assembly is shared rather than written twice: `index` and
 * `id` name the target, `body` carries the document, the search body or
 * the bulk lines, and `refresh` is a query parameter added only when it
 * is asked for, so an ordinary write takes the cluster's own default.
 */
abstract readonly class AbstractSearchClient implements SearchClient
{
    #[\Override]
    public function index(string $index, ?string $id, array $document, bool $refresh = false): array
    {
        $params = ['index' => $index, 'body' => $document];

        if ($id !== null) {
            $params['id'] = $id;
        }

        return $this->send(SearchCall::Index, self::refreshing($params, $refresh));
    }

    #[\Override]
    public function get(string $index, string $id): ?array
    {
        return $this->sendAllowingAbsence(SearchCall::Get, ['index' => $index, 'id' => $id]);
    }

    #[\Override]
    public function delete(string $index, string $id, bool $refresh = false): bool
    {
        $params = self::refreshing(['index' => $index, 'id' => $id], $refresh);

        return $this->sendAllowingAbsence(SearchCall::Delete, $params) !== null;
    }

    #[\Override]
    public function search(string $index, array $body): array
    {
        return $this->send(SearchCall::Search, ['index' => $index, 'body' => $body]);
    }

    #[\Override]
    public function bulk(array $operations, bool $refresh = false): array
    {
        $params = self::refreshing(['body' => BulkOperation::body($operations)], $refresh);

        return $this->send(SearchCall::Bulk, $params);
    }

    /**
     * One call through the engine's own client, with that engine's
     * failures mapped: every error status the cluster answered with to a
     * {@see SearchRequestException} carrying it, and a request that never
     * completed to a {@see SearchNetworkException}. The response body is
     * the cluster's own, untouched.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    abstract protected function send(SearchCall $call, array $params): array;

    /**
     * The two calls that name one document answer absence rather than
     * failing, whether the index or only the id is the part that is not
     * there. Every other status stays a failure.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function sendAllowingAbsence(SearchCall $call, array $params): ?array
    {
        try {
            return $this->send($call, $params);
        } catch (SearchRequestException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function refreshing(array $params, bool $refresh): array
    {
        if ($refresh) {
            $params['refresh'] = 'true';
        }

        return $params;
    }
}
