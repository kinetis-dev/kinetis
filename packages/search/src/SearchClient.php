<?php

declare(strict_types=1);

namespace Kinetis\Search;

use Kinetis\Search\Exception\SearchNetworkException;
use Kinetis\Search\Exception\SearchRequestException;

/**
 * The five calls an application makes against either engine, with one
 * envelope and one failure vocabulary. An engine package binds an
 * implementation of this beside its own engine client, so an application
 * that stays inside these five calls moves between OpenSearch and
 * Elasticsearch by swapping the package and the configuration prefix.
 *
 * This is a call and result contract, not a query language. A search
 * body is the engine's own query DSL, passed through untouched: the two
 * engines agree on the common ground — `match`, `term`, `range`, `bool`,
 * `aggs`, `from`, `size`, `sort` — and diverge past it, and normalizing
 * that divergence is not something this interface pretends to do. The
 * result envelopes are the ones both engines share, so
 * `$result['hits']['hits']` and `$result['hits']['total']['value']` mean
 * the same thing on either.
 *
 * Anything outside these five calls — index management, mappings,
 * aliases, aggregation-only requests, ES|QL, point-in-time readers — is
 * reached through the engine client itself, which each package binds
 * unwrapped beside this one.
 *
 * A missing document is a return value rather than a failure: absence is
 * an ordinary answer to a lookup. Every other error status the cluster
 * answers with is a {@see SearchRequestException}, and a request that
 * never completed is a {@see SearchNetworkException}.
 */
interface SearchClient
{
    /**
     * Indexes $document under $id, replacing whatever that id held, and
     * lets the cluster assign an id when $id is null.
     *
     * $refresh makes the write visible to search before the call
     * returns. It costs a refresh per call and belongs in a test or a
     * read-your-write path, not in bulk ingestion.
     *
     * A document carries at least one field. Neither engine accepts an
     * empty one where a JSON object belongs, so writing nothing is not
     * an operation either of them offers.
     *
     * @param non-empty-array<string, mixed> $document
     * @return array<string, mixed> the write envelope: `_id`, `_version`, `result`, `_shards`
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    public function index(string $index, ?string $id, array $document, bool $refresh = false): array;

    /**
     * The document envelope, or null when the index or the id holds
     * nothing. The document itself is `$envelope['_source']`; the
     * envelope also carries `_version`, `_seq_no` and `_primary_term`,
     * which is what an optimistic-concurrency write needs.
     *
     * @return array<string, mixed>|null
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    public function get(string $index, string $id): ?array;

    /**
     * True when this call deleted the document, false when the index or
     * the id held nothing.
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    public function delete(string $index, string $id, bool $refresh = false): bool;

    /**
     * $index is one index, alias, or comma-separated list of either.
     * $body is the engine's own search body — query, aggregations,
     * paging and sorting together — and travels unchanged.
     *
     * An index that does not exist is a {@see SearchRequestException}
     * carrying 404, not an empty result: absence is an answer only where
     * one document was named. An empty body is the one exception to the
     * non-empty rule the other calls follow — both engines read it as
     * match-all.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed> the search envelope: `took`, `timed_out`, `_shards`, `hits`
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    public function search(string $index, array $body): array;

    /**
     * One request carrying many writes. The returned envelope's `errors`
     * is true when any single operation was rejected, and `items` holds
     * one per operation in the order they were given: a bulk request
     * answers 200 with failures inside it, so a caller that ignores
     * `errors` silently drops writes. Deleting a document that is not
     * there is not a rejection — that item reports 404 and `errors`
     * stays false.
     *
     * The batch carries at least one operation, and arrives whole rather
     * than as a generator: both engines refuse an empty `_bulk` body, and
     * the request is built as one string either way, so nothing here
     * streams. A caller with nothing to write skips the call.
     *
     * @param non-empty-array<BulkOperation> $operations
     * @return array<string, mixed> the bulk envelope: `took`, `errors`, `items`
     *
     * @throws SearchRequestException
     * @throws SearchNetworkException
     */
    public function bulk(array $operations, bool $refresh = false): array;
}
