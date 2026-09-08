# Search (OpenSearch)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/search-opensearch
```
````

Builds a real `OpenSearch\Client` (from `opensearch-project/opensearch-php`)
for searching and indexing documents. Every request it makes runs without
blocking the rest of your application.

With `SEARCH_OPENSEARCH_HOST` set, installing the package binds
`OpenSearch\Client`, so a controller, command, or queued job
constructor-injects it with nothing to register. The client is built while
the package registers and opens no connection, so a host that isn't one
usable origin, or an unusable deadline or response bound, fails at boot
rather than inside the first search. Your own `bootstrap.php` runs after
that and can bind a different client. Build one directly with
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
own documentation describes.

## Configuring

```{code-block} text
SEARCH_OPENSEARCH_HOST=https://localhost:9200
```

`SEARCH_OPENSEARCH_HOST` is required and is exactly one
`http(s)://host[:port]` origin. Userinfo, a path, a query string and a
fragment are all refused, and the accepted parts are rebuilt into a
lowercase origin — a bracketed IPv6 address keeps its brackets. The
official client's endpoints are root-relative, so a base path under the
origin would be dropped rather than honoured; there is no base-path
support to configure.

It points at a single node. There's no client-side failover across
multiple nodes — put a load balancer in front of a multi-node cluster
instead, and point this at the balancer.

```{code-block} text
SEARCH_OPENSEARCH_PLAINTEXT=true
```

An `http` origin carries credentials and documents in the clear, so it
needs this opt-in. `http://opensearch:9200` between containers on one
Compose network is legitimate; nothing in the host can tell it from a
public address, so the decision is yours to state.

```{code-block} text
SEARCH_OPENSEARCH_TIMEOUT=30
SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES=8388608
```

`SEARCH_OPENSEARCH_TIMEOUT` is seconds, and is both the idle timeout
between bytes and the total duration of one request, so a response fed a
byte at a time cannot outlive it. It bounds one HTTP call, not a sequence
of them. `SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES` is the largest response
body accepted; a larger one is abandoned rather than buffered into the
worker. Both must be positive. Requests follow no redirect and accept
only the `identity` content coding, so the response bound counts the same
bytes the node sent.

```{code-block} text
SEARCH_OPENSEARCH_USERNAME=admin
SEARCH_OPENSEARCH_PASSWORD=admin
```

Basic auth, for a cluster with the security plugin enabled. Leaving
`SEARCH_OPENSEARCH_USERNAME` unset sends no authentication at all.

```{code-block} text
SEARCH_OPENSEARCH_VERIFY_PEER=false
```

`SEARCH_OPENSEARCH_VERIFY_PEER` defaults to `true`. Set it to `false` to
accept a cluster's self-signed certificate — the default OpenSearch
Docker image ships with the security plugin enabled and a self-signed
demo certificate out of the box, so a local or internal cluster
frequently needs this.

## Failures

Two exceptions belong to this package.

`Kinetis\SearchOpenSearch\Exception\OpenSearchConfigurationException` is
raised while the client is built — from the package bootstrap, or from
your own `fromConfig()` call — for a host that is not one usable origin,
an `http` origin without the opt-in, or a non-positive deadline or
response bound. Its message names the configuration key and never quotes
the value.

`Kinetis\SearchOpenSearch\Exception\OpenSearchNetworkException`
implements PSR-18's `NetworkExceptionInterface` and carries the request
that caused it. It is raised for a request that never produced a complete
response: the connection, the deadline, or the response bound ended it.
The status, headers and body are read before the transport hands the
response back, so a failure part-way through a body arrives here rather
than out of a PSR-7 stream later on.

Every status OpenSearch itself answers with stays the official client's
to map — `NotFoundHttpException`, `ConflictHttpException`,
`UnauthorizedHttpException` and the rest are raised by
`opensearch-php`, unchanged.

## Named connections

```{code-block} php
$logs = OpenSearchClientFactory::fromConfig($config, 'logs');
```

```{code-block} text
SEARCH_LOGS_OPENSEARCH_HOST=https://logs-cluster:9200
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `SEARCH_OPENSEARCH_HOST` above, and any other
name inserts itself right after the first segment of the key instead.

## Amazon OpenSearch Service (IAM authentication)

`OpenSearchClientFactory::fromConfig()` only ever builds the Basic-auth
path above. For IAM/SigV4 authentication instead — Amazon OpenSearch
Service's own common case — construct the client directly using
`kinetis/aws-sigv4`; see {doc}`aws-sigv4` for the full example.

## Wrapping the transport

`fromConfig()`'s optional `$transportDecorator` parameter wraps the
fully-configured PSR-18 adapter — origin policy, deadline, response
bound, Content-Type, Basic auth and TLS options already applied — right
before it reaches OpenSearch's own `TransportFactory`:

```{code-block} php
use Psr\Http\Client\ClientInterface;

$client = OpenSearchClientFactory::fromConfig(
    $config,
    transportDecorator: static fn (ClientInterface $inner): ClientInterface
        => new MyLoggingClient($inner),
);
```

This is the seam {doc}`telemetry`'s `TracingOpenSearchTransport` uses —
see that page for a span per OpenSearch call. The adapter it wraps
returns a complete response, so a decorator's own call covers the whole
exchange.

## If the package isn't installed

Nothing in core references this package — there's no automatic dispatch
to fail loudly if it's missing, the way {doc}`storage-s3` has for
`FILESYSTEM_DRIVER=s3`. Add `kinetis/search-opensearch` directly wherever
your application needs it.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client this package
  builds every request on.
- {doc}`aws-sigv4` — IAM/SigV4 authentication against Amazon OpenSearch
  Service, as an alternative to Basic auth.
- {doc}`config` — the named-connection convention used above.
- {doc}`telemetry` — OpenTelemetry spans over the `transportDecorator`
  seam above.
