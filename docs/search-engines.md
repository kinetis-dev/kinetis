# Search engines

Kinetis supports OpenSearch and Elasticsearch through separate packages.
Install the package for the engine you run; it also installs the shared
`kinetis/search` contract:

```{code-block} sh
composer require kinetis/search-opensearch
# or: composer require kinetis/search-elasticsearch
```

The package binds its engine client and `Kinetis\Search\SearchClient`
when its host is configured. Use {doc}`search-opensearch` for OpenSearch
and Amazon OpenSearch Service, or {doc}`search-elasticsearch` for
Elasticsearch and Elastic Cloud. Each guide covers authentication and
engine-specific setup.

## Connect

Configure one origin for the installed engine:

```{code-block} text
:caption: .env — OpenSearch

SEARCH_OPENSEARCH_HOST=https://search.internal:9200
SEARCH_OPENSEARCH_USERNAME=app
SEARCH_OPENSEARCH_PASSWORD=secret
```

For Elasticsearch, use `SEARCH_ELASTICSEARCH_HOST`, `..._USERNAME` and
`..._PASSWORD` instead. Both packages also accept `..._TIMEOUT` (default
30 seconds), `..._MAX_RESPONSE_BYTES` (default 8 MiB) and
`..._VERIFY_PEER` (default `true`). Put a load balancer in front of a
cluster: the client connects to one origin and does not select another
node after a failure. An `http://` origin needs `..._PLAINTEXT=true`;
use HTTPS when credentials or documents cross an untrusted network.

The host is required. Without it the package leaves its bindings unset.
The package validates configuration when the application boots, without
opening a connection. {doc}`config` lists the keys, and
{ref}`search-reference-config` has the complete origin, TLS and deadline
contract.

## Index and search

Inject the common interface to index a document and search either engine:

```{code-block} php
use Kinetis\Search\SearchClient;

final readonly class Articles
{
    public function __construct(private SearchClient $search) {}

    public function publish(string $id, array $article): void
    {
        $this->search->index('articles', $id, $article);
    }

    public function find(string $term): array
    {
        $result = $this->search->search('articles', [
            'query' => ['match' => ['title' => $term]],
        ]);

        return array_column($result['hits']['hits'], '_source');
    }
}
```

Create the `articles` index and its mapping in your search engine before
using it in production. `SearchClient` also has `get()`, `delete()` and
`bulk()`. `get()` returns `null` when a document is absent; `bulk()` can
return HTTP `200` while individual writes failed, so check its `errors`
flag and `items`. A write with `refresh: true` is visible to search
before the call returns, at the cost of a refresh; use it only where
read-after-write matters. {ref}`search-reference-client` has the full
call and result contract.

The search body is the engine's own query DSL. For index management,
mappings and engine-specific queries, inject the package's concrete
`OpenSearch\Client` or `Elastic\Elasticsearch\Client` and use that
engine's API. The common interface does not translate query languages.

## When a request fails

`SearchRequestException` means the cluster answered with an error
status; inspect its `status`. `SearchNetworkException` means no complete
response arrived. After a failed index or bulk call, the write may have
reached the engine. Check the indexed document or use an idempotent
document ID before repeating it. The clients do not replay a failed
request automatically. The full failure contract is in
{ref}`search-reference-failures`.

## More than one engine or connection

If both engine packages are installed, both bind `SearchClient`; the
last package bootstrap wins. Choose the binding explicitly in
`bootstrap.php` ({doc}`bootstrapping`). Build a second named connection
with the engine factory and scoped keys described in
{ref}`search-reference-named`.

## See also

- {doc}`search-opensearch` and {doc}`search-elasticsearch` — setup for
  each engine.
- {doc}`appendix-search` — configuration, client and transport contracts.
- {doc}`telemetry` — tracing search calls.
