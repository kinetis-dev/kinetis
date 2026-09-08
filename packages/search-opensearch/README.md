<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/search-opensearch</strong>
  <br>
  <strong>Non-blocking OpenSearch client construction for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/search-opensearch"><img src="https://img.shields.io/packagist/v/kinetis/search-opensearch?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/search-opensearch"><img src="https://img.shields.io/packagist/dt/kinetis/search-opensearch" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/search-opensearch"><img src="https://img.shields.io/packagist/php-v/kinetis/search-opensearch" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/search-opensearch"><img src="https://img.shields.io/packagist/l/kinetis/search-opensearch" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Builds a real `OpenSearch\Client` (from `opensearch-project/opensearch-php`)
through OpenSearch's own `TransportFactory`/`HttpTransport` construction
path, over a package-owned PSR-18 adapter on
[`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s Revolt-native HTTP transport instead of the
default blocking one. The returned object is the real, un-wrapped
client — nothing Kinetis-specific sits on top of it.

Each call is one wire attempt against one origin, bounded by one deadline
and one response size, following no redirect. The status, headers and body
are complete before the adapter returns, so a transport failure mid-body
is an `OpenSearchNetworkException` rather than something the official
client meets while reading a stream. Every status OpenSearch answers with
stays the official client's to map.

```php
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;

$client = OpenSearchClientFactory::fromConfig($config);

$client->index(['index' => 'articles', 'id' => '1', 'body' => ['title' => 'Kinetis']]);
$results = $client->search(['index' => 'articles', 'body' => ['query' => ['match' => ['title' => 'Kinetis']]]]);
```

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `OpenSearch\Client`, built by
  `OpenSearchClientFactory::fromConfig()` when `SEARCH_OPENSEARCH_HOST`
  is set. Unset means the package binds nothing. The client is built
  during registration and opens no connection, so unusable configuration
  fails at boot rather than on the first search; an application's own
  `bootstrap.php` runs afterwards and can replace the binding.

Nothing else. Named connections stay explicit application wiring.

## Configuration

```
SEARCH_OPENSEARCH_HOST=https://localhost:9200
```

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_OPENSEARCH_HOST` | *(required)* | One `http(s)://host[:port]` origin. |
| `SEARCH_OPENSEARCH_PLAINTEXT` | `false` | Accept an `http` origin. |
| `SEARCH_OPENSEARCH_TIMEOUT` | `30` | Seconds per request — idle and total. Must be positive. |
| `SEARCH_OPENSEARCH_MAX_RESPONSE_BYTES` | `8388608` | Largest response body accepted. Must be positive. |
| `SEARCH_OPENSEARCH_USERNAME` | — | Basic-auth user. |
| `SEARCH_OPENSEARCH_PASSWORD` | — | Basic-auth password. |
| `SEARCH_OPENSEARCH_VERIFY_PEER` | `true` | Verify the server certificate — `false` accepts a self-signed one on a security-enabled cluster. |

Every key is scoped — `SEARCH_OPENSEARCH_HOST` + `logs` →
`SEARCH_LOGS_OPENSEARCH_HOST`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

`SEARCH_OPENSEARCH_HOST` is one origin and one node. Userinfo, a path, a
query and a fragment are all refused: the official client's endpoints are
root-relative, so a base path would be dropped rather than honoured, and
credentials belong in the username and password keys. There is no
multi-node selector or failover — put a load balancer in front of a
multi-node cluster and point this at it.

An unusable host, an `http` origin without the opt-in, and a
non-positive timeout or response bound each raise an
`OpenSearchConfigurationException` naming the key. A request that never
produces a complete response raises an `OpenSearchNetworkException`
carrying it. Those two are the package's whole failure surface.

`fromConfig()`'s optional `$transportDecorator` parameter wraps the
fully-configured PSR-18 adapter right before `TransportFactory` gets
it — the seam [`kinetis/telemetry`](https://github.com/kinetis-dev/telemetry)'s `TracingOpenSearchTransport` plugs
into, without duplicating this method's own config-reading logic.

## Installation

```sh
composer require kinetis/search-opensearch
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework), and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/search-opensearch.html](https://kinetis.dev/docs/search-opensearch.html).

## License

MIT — see [LICENSE](LICENSE).
