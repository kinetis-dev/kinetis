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
path, with [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s Revolt-native HTTP transport
injected as its PSR-18 client instead of the default blocking one. The
returned object is the real, un-wrapped client — nothing Kinetis-specific
sits on top of it.

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
  is set. Unset means the package binds nothing. The binding is lazy, so
  an application that never searches never builds a transport.

Nothing else. Named connections stay explicit application wiring.

## Configuration

```
SEARCH_OPENSEARCH_HOST=http://localhost:9200
```

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_OPENSEARCH_HOST` | *(required)* | Base URI of the node. |
| `SEARCH_OPENSEARCH_USERNAME` | — | Basic-auth user. |
| `SEARCH_OPENSEARCH_PASSWORD` | — | Basic-auth password. |
| `SEARCH_OPENSEARCH_VERIFY_PEER` | `true` | Verify the server certificate — `false` accepts a self-signed one on a security-enabled cluster. |

Every key is scoped — `SEARCH_OPENSEARCH_HOST` + `logs` →
`SEARCH_LOGS_OPENSEARCH_HOST`. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

`SEARCH_OPENSEARCH_HOST` is a single base URI, not a list — this
construction path has no multi-node selector/failover; put a load
balancer in front of a multi-node cluster instead.

`fromConfig()`'s optional `$transportDecorator` parameter wraps the
fully-configured PSR-18 client right before `TransportFactory` gets
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
