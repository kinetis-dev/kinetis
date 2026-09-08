# Search (OpenSearch)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/search-opensearch
```
````

Builds a real `OpenSearch\Client` (from
`opensearch-project/opensearch-php`) for searching and indexing
documents. Every request it makes runs without blocking the rest of your
application.

With `SEARCH_OPENSEARCH_HOST` set, installing the package binds
`OpenSearch\Client` and the engine-neutral `Kinetis\Search\SearchClient`
over it, so a controller, command, or queued job constructor-injects
either with nothing to register. The client is built while the package
registers and opens no connection, so a host that isn't one usable
origin, or an unusable deadline or response bound, fails at boot rather
than inside the first search. Your own `bootstrap.php` runs after that
and can bind a different client. Build one directly with
`OpenSearchClientFactory::fromConfig($config)` for a second, named
connection, or outside the container.

```{code-block} php
use OpenSearch\Client;

$client->index([
    'index' => 'articles',
    'id' => '1',
    'body' => ['title' => 'Kinetis', 'category' => 'framework'],
]);

$results = $client->search([
    'index' => 'articles',
    'body' => ['query' => ['match' => ['category' => 'framework']]],
]);
```

The returned `$client` is the real, unmodified `OpenSearch\Client` —
every method it documents (`search()`, `index()`, `get()`, `delete()`,
`indices()`, `cluster()`, and the rest) works exactly as the library's
own documentation describes, answering plain arrays.

{doc}`search` covers what this package shares with
{doc}`search-elasticsearch`: the configuration keys, named connections,
the `SearchClient` contract, the failure types, and the
transport-decorator seam. This page is what belongs to OpenSearch alone.

## How the client is built

`OpenSearchClientFactory::fromConfig()` goes through OpenSearch's own
`TransportFactory`/`HttpTransport` path, whose
`setHttpClient()` takes a PSR-18 client — the non-deprecated
construction path, unlike the older `ClientBuilder`/`Transport`/
`ConnectionPool` stack, which has no such injection point. The endpoint
factory, serializer, request building and response mapping all stay the
official client's, and every status OpenSearch answers with —
`NotFoundHttpException`, `ConflictHttpException`,
`UnauthorizedHttpException` and the rest — is raised by `opensearch-php`,
unchanged.

One detail is this package's: OpenSearch's own request building never
sets a `Content-Type`, relying on the HTTP client to default a string
body to JSON, while Symfony's clients default an unmarked string body to
`application/x-www-form-urlencoded`, which a node answers with `406`. The
transport sets `application/json` on every request, and no OpenSearch
request replaces it: a `_bulk` body travels as NDJSON lines under that
JSON header, which the engine's bulk handler accepts and this package's
real-cluster checks exercise.

## Amazon OpenSearch Service (IAM authentication)

`OpenSearchClientFactory::fromConfig()` only ever builds the Basic-auth
path {doc}`search` describes. For IAM/SigV4 authentication instead —
Amazon OpenSearch Service's own common case — construct the client
directly using `kinetis/aws-sigv4`; see {doc}`aws-sigv4` for the full
example.

## See also

- {doc}`search` — configuration, the engine-neutral `SearchClient`,
  failures, and the transport seam, all shared with
  {doc}`search-elasticsearch`.
- {doc}`revolt-http-client` — the non-blocking HTTP client this package
  builds every request on.
- {doc}`aws-sigv4` — IAM/SigV4 authentication against Amazon OpenSearch
  Service, as an alternative to Basic auth.
- {doc}`telemetry` — a span per search call over the
  `transportDecorator` seam.
