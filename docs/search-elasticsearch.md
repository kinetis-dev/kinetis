# Search (Elasticsearch)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/search-elasticsearch
```
````

Builds a real `Elastic\Elasticsearch\Client` (from
`elasticsearch/elasticsearch`) for searching and indexing documents.
Every request it makes runs without blocking the rest of your
application.

With `SEARCH_ELASTICSEARCH_HOST` set, installing the package binds
`Elastic\Elasticsearch\Client` and the engine-neutral
`Kinetis\Search\SearchClient` over it, so a controller, command, or
queued job constructor-injects either with nothing to register. The
transport is built while the package registers, and the credentials are
checked with it, opening no connection — so a host that isn't one usable
origin, an unusable deadline or response bound, or two credentials for
one request fails at boot rather than inside the first search. No client
is built until something resolves one. Your own `bootstrap.php` runs after that and can bind a different
client. Build one directly with
`ElasticsearchClientFactory::fromConfig($config)` for a second, named
connection, or outside the container.

Each injection gets its own client over the one shared transport, rather
than one client for the whole worker — see [Why the client is
short-lived](#why-the-client-is-short-lived).

```{code-block} php
use Elastic\Elasticsearch\Client;

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

The returned `$client` is the real, unmodified
`Elastic\Elasticsearch\Client` — every method it documents (`search()`,
`index()`, `get()`, `delete()`, `indices()`, `cluster()`, `esql()`, and
the rest) works exactly as the library's own documentation describes,
answering its own `Elastic\Elasticsearch\Response\Elasticsearch` objects.

{doc}`search` covers what this package shares with
{doc}`search-opensearch`: the configuration keys, named connections, the
`SearchClient` contract, the failure types, and the transport-decorator
seam. This page is what belongs to Elasticsearch alone.

## Matching the client to your cluster

The package accepts `elasticsearch/elasticsearch` `^8.19 || ^9.0`, and
the major has to match your cluster's: a 9.x client sends
`compatible-with=9`, which an 8.x cluster rejects. Composer resolves to
the newest allowed release, so an Elasticsearch 8 cluster needs the
constraint pinned in your own application:

```{code-block} sh
composer require elasticsearch/elasticsearch:^8.19
```

Everything on this page and in {doc}`search` behaves identically on
either major.

## Authenticating with an API key

Basic auth is configured as {doc}`search` describes. Elastic Cloud
issues API keys instead, and this package takes them:

```{code-block} text
SEARCH_ELASTICSEARCH_API_KEY=VnVhQ2ZHY0JDZGJrU...
```

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_ELASTICSEARCH_API_KEY` | — | The `encoded` value the cluster hands out, or the key's secret alongside the id below. |
| `SEARCH_ELASTICSEARCH_API_KEY_ID` | — | The key's id, when you hold the id and secret separately rather than the encoded pair. |

The key travels as an `Authorization: ApiKey` header and never in the
URL. Configuring an API key and `SEARCH_ELASTICSEARCH_USERNAME` together
raises a `SearchConfigurationException` while the client is built: they
are two credentials for one request, and which one reached the cluster
would depend on header precedence rather than on your decision.

## What this package pins on the official client

Three of `ClientBuilder`'s own settings are deliberately not used, and
one default is replaced. Each is a guarantee the rest of Kinetis makes.

**No retry.** `Elastic\Transport\Transport` catches PSR-18's
`NetworkExceptionInterface` and re-sends the request, and `ClientBuilder`
otherwise leaves one retry armed. A deadline or a dropped connection
part-way through an `index` or `bulk` request has an unknown dispatch
outcome, and re-sending it can write the document twice, so retries are
pinned to zero on the built transport. A request that never completed
therefore surfaces as
`Elastic\Transport\Exception\NoNodeAvailableException` with this
project's own `SearchNetworkException` as its previous, where the reason
and the request are.

**One node, always in service.** The node pool is this package's
`SingleNode` rather than Elasticsearch's `SimpleNodePool`. That default
marks the node it failed on dead and never revives it, which for a
one-node client would mean one dropped connection ends searching for as
long as that client lives.

**Credentials outside the URL.**
`ClientBuilder::setBasicAuthentication()` is never called: it reaches
`Transport::setUserInfo()`, which puts the credentials into the request
URI's userinfo, where a transport error message can quote them. Basic
credentials stay in the HTTP client's own option — which is also why
`SEARCH_ELASTICSEARCH_HOST` refuses a host carrying userinfo.

**TLS on the transport.** No `setSSLVerification()`, `setCABundle()` or
`setSSLKey()` call is made; `ClientBuilder` routes those through an
adapter chosen by the HTTP client's class name and rejects one it does
not recognize. TLS is configured by `SEARCH_ELASTICSEARCH_VERIFY_PEER`.

(why-the-client-is-short-lived)=

## Why the client is short-lived

`Elastic\Transport\Transport` keeps the last request and the last
response it saw, for `getLastRequest()` and `getLastResponse()`, and
`Client::setAsync()` is a mode any holder can flip. A single client for
the whole worker would therefore hold one request's documents and its
search results — up to `SEARCH_ELASTICSEARCH_MAX_RESPONSE_BYTES` of
them — until the next search displaced them, which is request-owned state
outliving its request.

So the binding is not shared: the transport, which owns the connection
pool and keeps nothing per call, is built once for the worker, and each
resolution builds its own client over it. A client holds no connection,
so this costs a few objects and no I/O.
`ElasticsearchClientFactory::over()` is that seam if you wire the client
yourself.

A client then lives exactly as long as whatever resolved it. A controller
or queued job, resolved per request, lets its client go with the request.
A service that is itself worker-lifetime and injects the client once
keeps that one alive — and with it the last request it made — so such a
service should resolve a client per operation instead.

{doc}`search-opensearch` binds one shared client instead: OpenSearch's
`HttpTransport` keeps nothing between calls, and its endpoint factory
builds a fresh endpoint per call.

## The product check

Elasticsearch's client verifies an `X-Elastic-Product: Elasticsearch`
response header on every successful response and raises
`Elastic\Elasticsearch\Exception\ProductCheckException` without it. That
check is the library's own and reaches it intact, so pointing this
package at an OpenSearch cluster fails loudly rather than half-working.
Use {doc}`search-opensearch` for OpenSearch.

## See also

- {doc}`search` — configuration, the engine-neutral `SearchClient`,
  failures, and the transport seam, all shared with
  {doc}`search-opensearch`.
- {doc}`revolt-http-client` — the non-blocking HTTP client this package
  builds every request on.
- {doc}`telemetry` — a span per search call over the
  `transportDecorator` seam, and the bindings that trace it without
  making a client worker-lifetime.
