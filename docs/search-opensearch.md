# Search (OpenSearch)

Install the OpenSearch engine package:

```{code-block} sh
composer require kinetis/search-opensearch
```

```{code-block} text
:caption: .env

SEARCH_OPENSEARCH_HOST=https://search.internal:9200
SEARCH_OPENSEARCH_USERNAME=app
SEARCH_OPENSEARCH_PASSWORD=secret
```

With `SEARCH_OPENSEARCH_HOST` set, Kinetis binds both the engine-neutral
`Kinetis\Search\SearchClient` and the real `OpenSearch\Client`. Inject
`SearchClient` for indexing and queries shared with Elasticsearch; see
{doc}`search-engines` for a first index and search. Inject
`OpenSearch\Client` for mappings, aliases and other engine APIs. The
client is built at boot, so invalid configuration fails before a route
or worker job runs; no connection is opened until the first request.

For Amazon OpenSearch Service with IAM credentials, Basic auth is not
enough. Build a signed OpenSearch client using `kinetis/aws-sigv4` as
shown in {doc}`aws-sigv4` and bind it in `bootstrap.php` if application
code injects it. Your `bootstrap.php` binding wins over the
package default ({doc}`bootstrapping`).

One `SEARCH_OPENSEARCH_HOST` points at one node or load balancer. An
`http://` origin requires `SEARCH_OPENSEARCH_PLAINTEXT=true`; TLS peer
verification is on by default. Configure a second connection with
`OpenSearchClientFactory::fromConfig($config, 'name')` and scoped keys
({ref}`search-reference-named`).

An OpenSearch error status raises the engine client's own exception when
you call `OpenSearch\Client` directly. `SearchClient` presents
`SearchRequestException` instead. A request with no complete response
may already have indexed a document; check it before repeating a
non-idempotent write. {ref}`search-reference-failures` describes the
failure vocabulary and {doc}`appendix-search` records the transport
details.

## See also

- {doc}`search-engines` — common setup and indexing.
- {doc}`appendix-search` — configuration, client and transport contracts.
- {doc}`aws-sigv4` — IAM authentication for Amazon OpenSearch Service.
- {doc}`telemetry` — tracing search calls.
