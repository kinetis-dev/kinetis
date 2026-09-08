<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/search</strong>
  <br>
  <strong>The shared search transport, and one client for either engine</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/search"><img src="https://img.shields.io/packagist/v/kinetis/search?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/search"><img src="https://img.shields.io/packagist/dt/kinetis/search" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/search"><img src="https://img.shields.io/packagist/php-v/kinetis/search" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/search"><img src="https://img.shields.io/packagist/l/kinetis/search" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

What [`kinetis/search-opensearch`](https://github.com/kinetis-dev/search-opensearch)
and [`kinetis/search-elasticsearch`](https://github.com/kinetis-dev/search-elasticsearch)
have in common. Install one of those; this package comes with it.

- **The transport.** One origin, one deadline, one response-size bound,
  no redirect, no retry, `identity` encoding, Basic auth and TLS
  verification — over
  [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client)'s
  Revolt-native HTTP client, so a search suspends the calling Fiber
  instead of blocking the worker. Read from `SEARCH_OPENSEARCH_*` or
  `SEARCH_ELASTICSEARCH_*`, so a key means the same thing whichever
  engine an application runs.
- **`SearchClient`.** Five calls both engines answer the same way, for an
  application that would rather not name one. Each engine package binds
  an implementation beside its own real, unwrapped client, which is still
  there for everything else.

```php
use Kinetis\Search\BulkOperation;
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

    /** @param non-empty-array<array<string, mixed>> $articles */
    public function importAll(array $articles): array
    {
        return $this->search->bulk(array_map(
            static fn (array $a): BulkOperation => BulkOperation::index('articles', $a['id'], $a),
            $articles,
        ));
    }
}
```

`get()` answers `null` and `delete()` answers `false` when a document
isn't there; a search body is the engine's own query DSL, passed through
untouched. Anything outside these five calls is reached through the
engine client itself.

## Configuration

Every key is spelled with the engine's own prefix. Full reference:
[kinetis.dev/docs/search.html](https://kinetis.dev/docs/search.html).

| Key | Default | Purpose |
|---|---|---|
| `SEARCH_..._HOST` | *(required)* | One `http(s)://host[:port]` origin. |
| `SEARCH_..._PLAINTEXT` | `false` | Accept an `http` origin. |
| `SEARCH_..._TIMEOUT` | `30` | Seconds per request — idle and total. Must be positive. |
| `SEARCH_..._MAX_RESPONSE_BYTES` | `8388608` | Largest response body accepted. Must be positive. |
| `SEARCH_..._USERNAME` | — | Basic-auth user. |
| `SEARCH_..._PASSWORD` | — | Basic-auth password. |
| `SEARCH_..._VERIFY_PEER` | `true` | Verify the server certificate. |

## Failures

`SearchConfigurationException` for configuration a client cannot be built
from, raised while it is built and naming the key without quoting its
value. `SearchNetworkException` (PSR-18's `NetworkExceptionInterface`,
carrying the request) for a request that never produced a complete
response. `SearchRequestException` for an error status a `SearchClient`
call met, carrying the status and the engine's own exception underneath.

## Installation

```sh
composer require kinetis/search-opensearch     # OpenSearch
composer require kinetis/search-elasticsearch  # Elasticsearch
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework),
and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/search.html](https://kinetis.dev/docs/search.html).

## License

MIT — see [LICENSE](LICENSE).
