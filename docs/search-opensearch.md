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
constructor-injects it with nothing to register. Build one directly
with `OpenSearchClientFactory::fromConfig($config)` for a second, named
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
SEARCH_OPENSEARCH_HOST=http://localhost:9200
```

`SEARCH_OPENSEARCH_HOST` is required and points at a single node. There's
no client-side failover across multiple nodes — put a load balancer in
front of a multi-node cluster instead, and point this at the balancer.

Two optional settings:

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

## Named connections

```{code-block} php
$logs = OpenSearchClientFactory::fromConfig($config, 'logs');
```

```{code-block} text
SEARCH_LOGS_OPENSEARCH_HOST=http://logs-cluster:9200
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`'default'` reads the plain `SEARCH_OPENSEARCH_HOST` above, and any other
name inserts itself right after the first segment of the key instead.

## Amazon OpenSearch Service (IAM authentication)

`OpenSearchClientFactory::fromConfig()` only ever builds the Basic-auth
path above. For IAM/SigV4 authentication instead — Amazon OpenSearch
Service's own common case — construct the client directly using
`kinetis/aws-sigv4`; see {doc}`aws-sigv4` for the full example.

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
