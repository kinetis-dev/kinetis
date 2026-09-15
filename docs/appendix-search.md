# Appendix: Search contracts

The configuration, client and transport contracts behind {doc}`search-engines`.
Start with the guide to install an engine, index a document and search it.

Installing both is possible — two named connections against two
clusters, or a migration in progress — but then both bootstraps bind
`SearchClient`, and the last one registered wins. Bind it yourself in
`bootstrap.php` to say which engine owns it.

(search-reference-config)=

## Configuring

Every key below is spelled with the engine's own prefix —
`SEARCH_OPENSEARCH_` or `SEARCH_ELASTICSEARCH_` — and means the same
thing on both:

```{code-block} text
SEARCH_ELASTICSEARCH_HOST=https://localhost:9200
```

| Key | Default | Purpose |
|---|---|---|
| `..._HOST` | *(unset: no client)* | One `http(s)://host[:port]` origin. |
| `..._PLAINTEXT` | `false` | Accept an `http` origin. |
| `..._TIMEOUT` | `30` | Seconds per request — idle and total. Must be positive. |
| `..._MAX_RESPONSE_BYTES` | `8388608` | Largest response body accepted. Must be positive. |
| `..._USERNAME` | — | Basic-auth user. |
| `..._PASSWORD` | — | Basic-auth password. |
| `..._VERIFY_PEER` | `true` | Verify the server certificate. |

`..._HOST` is required and is exactly one `http(s)://host[:port]` origin.
Userinfo, a path, a query string and a fragment are all refused, and the
accepted parts are rebuilt into a lowercase origin — a bracketed IPv6
address keeps its brackets. One root origin is the shared configuration
contract, so there is no base path to configure; expose a cluster served
under a path at its own origin.

It points at a single node. There's no client-side failover across
several nodes — put a load balancer in front of a multi-node cluster
instead, and point this at the balancer.

An `http` origin carries credentials and documents in the clear, so it
needs the `..._PLAINTEXT` opt-in. `http://opensearch:9200` between
containers on one Compose network is legitimate; nothing in the host can
tell it from a public address, so the decision is yours to state.

`..._TIMEOUT` is seconds, and is both the idle timeout between bytes and
the total duration of one request, so a response fed a byte at a time
cannot outlive it. It bounds one HTTP call, not a sequence of them.
`..._MAX_RESPONSE_BYTES` is the largest response body accepted; a larger
one is abandoned rather than buffered into the worker. Both must be
positive. Requests follow no redirect and ask for the `identity` content
coding, so the response bound counts the same bytes the node sent.

`..._VERIFY_PEER` defaults to `true`. Set it to `false` to accept a
cluster's self-signed certificate — both engines' Docker images ship
security enabled with a generated certificate, so a local or internal
cluster frequently needs this.

Each engine adds what only it has: Elasticsearch's API-key keys are on
{doc}`search-elasticsearch`.

(search-reference-named)=

### Named connections

```{code-block} php
$logs = ElasticsearchClientFactory::fromConfig($config, 'logs');
```

```{code-block} text
SEARCH_LOGS_ELASTICSEARCH_HOST=https://logs-cluster:9200
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain key above, and any other name inserts itself
right after the first segment of the key instead. A package bootstrap
binds the default connection only; a named one is explicit application
wiring.

(search-reference-client)=

## One client for either engine

`Kinetis\Search\SearchClient` is five calls both engines answer the same
way:

```{code-block} php
use Kinetis\Search\BulkOperation;
use Kinetis\Search\SearchClient;

final readonly class Articles
{
    public function __construct(private SearchClient $search)
    {
    }

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

- `index(string $index, ?string $id, array $document, bool $refresh = false): array`
  writes a document, letting the cluster assign an id when `$id` is null,
  and answers the write envelope (`_id`, `_version`, `result`).
- `get(string $index, string $id): ?array` answers the document envelope
  — the document itself is `$envelope['_source']`, and `_version`,
  `_seq_no` and `_primary_term` are there for an optimistic-concurrency
  write — or `null` when the index or the id holds nothing.
- `delete(string $index, string $id, bool $refresh = false): bool` answers
  `true` when it deleted, `false` when there was nothing to delete.
- `search(string $index, array $body): array` takes the engine's own
  search body and answers the search envelope, so
  `$result['hits']['total']['value']` and `$result['hits']['hits']` mean
  the same thing on either engine.
- `bulk(array $operations, bool $refresh = false): array` takes a
  non-empty array of {ref}`BulkOperation <bulk-operations>` values and
  answers `took`, `errors` and one `items` entry per operation.

`$refresh` makes a write visible to search before the call returns. It
costs a refresh per call and belongs in a test or a read-your-write path,
not in bulk ingestion.

This is a call and result contract, not a query language. A search body
travels through untouched: the two engines agree on the common ground —
`match`, `term`, `range`, `bool`, `aggs`, `from`, `size`, `sort` — and
diverge past it, and normalizing that divergence is not something this
interface pretends to do.

Anything outside these five calls — index management, mappings, aliases,
ES|QL, point-in-time readers, an engine's own bulk helper — is reached
through the engine client, which each package binds unwrapped beside this
one. Injecting `OpenSearch\Client` or `Elastic\Elasticsearch\Client`
gives you the whole library, exactly as its own documentation describes.

(bulk-operations)=

### Bulk operations

```{code-block} php
$result = $search->bulk([
    BulkOperation::index('articles', '1', ['title' => 'Kinetis']),
    BulkOperation::create('articles', '2', ['title' => 'New']),
    BulkOperation::update('articles', '3', ['category' => 'framework']),
    BulkOperation::delete('articles', '4'),
]);

if ($result['errors']) {
    // $result['items'] carries one entry per operation, each with its
    // own status.
}
```

`index` replaces whatever the id held, `create` fails if the id already
exists, `update` merges a partial document into it, and `delete` removes
it. `index` and `create` take a null id to let the cluster assign one.

A bulk request answers `200` with its failures inside it, so a caller
that ignores `errors` silently drops writes. Deleting a document that
isn't there is not one of those failures — the item reports status `404`
and `errors` stays `false`.

The batch carries at least one operation and arrives whole rather than as
a generator: both engines refuse an empty `_bulk` body, and the request
is built as one string either way, so nothing here streams. A caller with
nothing to write skips the call.

(search-reference-failures)=

## Failures

Four exceptions belong to `kinetis/search`.

`Kinetis\Search\Exception\SearchConfigurationException` is raised while
the client is built — from a package bootstrap, or from your own
`fromConfig()` call — for a host that is not one usable origin, an `http`
origin without the opt-in, a non-positive deadline or response bound, or
two credentials configured for one request. Its message names the
configuration key and never quotes the value.

`Kinetis\Search\Exception\SearchNetworkException` implements PSR-18's
`NetworkExceptionInterface` and carries the request that caused it. It is
raised for a request that never produced a complete response: the
connection, the deadline, or the response bound ended it. The status,
headers and body are read before the transport hands the response back,
so a failure part-way through a body arrives here rather than out of a
PSR-7 stream later on.

`Kinetis\Search\Exception\SearchResponseTooLargeException` is the abort
the response bound raises when a body passes `..._MAX_RESPONSE_BYTES`,
naming the key that ended the transfer. It is a cause rather than
something to catch: the HTTP client treats the throw as an aborted
transfer, so what the call meets is the `SearchNetworkException` above,
with this one further down the chain.

`Kinetis\Search\Exception\SearchRequestException` is what
`SearchClient` reports for an error status the cluster answered with — a
rejected query, a version conflict, a closed index. It carries the
`status` and keeps the engine's own exception as its previous, where the
cluster's error text is. An application using the engine client directly
meets that engine exception instead, unmapped: every status OpenSearch
and Elasticsearch answer with stays their own client's to map.

## Wrapping the transport

Each engine factory's optional `$transportDecorator` parameter wraps the
fully-configured PSR-18 adapter — origin policy, deadline, response
bound, Content-Type, credentials and TLS options already applied — right
before the engine's own transport is built around it:

```{code-block} php
use Psr\Http\Client\ClientInterface;

$client = ElasticsearchClientFactory::fromConfig(
    $config,
    transportDecorator: static fn (ClientInterface $inner): ClientInterface
        => new MyLoggingClient($inner),
);
```

This is the seam {doc}`telemetry`'s `TracingSearchTransport` uses — see
that page for a span per search call. The adapter it wraps returns a
complete response, so a decorator's own call covers the whole exchange.

`fromConfig()` hands back one client with a transport of its own. On
Elasticsearch that client must not be bound for the worker, for the
reason {ref}`why-the-client-is-short-lived` gives; {doc}`telemetry`
decorates the transport and binds a client per resolution over it
instead.


## Elasticsearch client internals

### What this package pins on the official client

Three of `ClientBuilder`'s own settings are not used, and
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

### Why the client is short-lived

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

### The product check

Elasticsearch's client verifies an `X-Elastic-Product: Elasticsearch`
response header on every successful response and raises
`Elastic\Elasticsearch\Exception\ProductCheckException` without it. That
check is the library's own and reaches it intact, so pointing this
package at an OpenSearch cluster fails loudly rather than half-working.
Use {doc}`search-opensearch` for OpenSearch.

## OpenSearch client internals

### How the client is built

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

## See also

- {doc}`search-engines` — install, configure, index and query.
- {doc}`search-opensearch` and {doc}`search-elasticsearch` — engine setup.
- {doc}`revolt-http-client` — the non-blocking HTTP client behind each engine request.
- {doc}`config` — all search configuration keys.
