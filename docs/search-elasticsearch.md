# Search (Elasticsearch)

Install the Elasticsearch engine package:

```{code-block} sh
composer require kinetis/search-elasticsearch
```

```{code-block} text
:caption: .env

SEARCH_ELASTICSEARCH_HOST=https://search.internal:9200
SEARCH_ELASTICSEARCH_USERNAME=app
SEARCH_ELASTICSEARCH_PASSWORD=secret
```

With `SEARCH_ELASTICSEARCH_HOST` set, Kinetis binds both the
engine-neutral `Kinetis\Search\SearchClient` and the real
`Elastic\Elasticsearch\Client`. Inject `SearchClient` for the first
index and search in {doc}`search-engines`; inject the engine client for
mappings, aliases and other Elasticsearch APIs. Configuration is checked
at boot, without opening a connection. Each resolution receives a fresh
client over a shared non-blocking transport; resolve it within a request
or job rather than keeping it in a worker-lifetime service
({ref}`why-the-client-is-short-lived`).

## Match your client to the cluster

The package permits Elasticsearch client major 8 or 9. Composer can
choose 9; an Elasticsearch 8 cluster needs the client pinned in your
application:

```{code-block} sh
composer require elasticsearch/elasticsearch:^8.19
```

Do not point this package at an OpenSearch cluster. Elasticsearch's
product check rejects its responses; use {doc}`search-opensearch`.

## Use an API key

For an Elastic Cloud API key, set its encoded value instead of Basic-auth
username and password:

```{code-block} text
SEARCH_ELASTICSEARCH_API_KEY=encoded-key-from-elastic-cloud
```

When the key ID and secret are separate, set
`SEARCH_ELASTICSEARCH_API_KEY_ID` to the ID and
`SEARCH_ELASTICSEARCH_API_KEY` to the secret. Do not combine an API key
with `SEARCH_ELASTICSEARCH_USERNAME`: Kinetis refuses two credentials
for one request at boot. The key travels in the `Authorization` header,
never in the URL. {doc}`config` lists the other connection keys.

## Failure and second connections

`SearchClient` reports a cluster error as `SearchRequestException` and
an incomplete request as `SearchNetworkException`; the concrete client
reports the engine's own exceptions. A failed write can have reached the
cluster, so check the document before repeating it. The built client
does not automatically retry it. See {ref}`search-reference-failures`
for the exact contract.

Build a second connection with
`ElasticsearchClientFactory::fromConfig($config, 'name')` and scoped keys
({ref}`search-reference-named`). The package binds only the default
connection; use {doc}`bootstrapping` to register another.

## See also

- {doc}`search-engines` — common setup and indexing.
- {doc}`appendix-search` — configuration, client and transport contracts.
- {doc}`telemetry` — tracing search calls.
