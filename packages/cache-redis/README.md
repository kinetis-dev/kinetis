<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/cache-redis</strong>
  <br>
  <strong>Redis-backed PSR-16 SimpleCache for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/cache-redis"><img src="https://img.shields.io/packagist/v/kinetis/cache-redis?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/cache-redis"><img src="https://img.shields.io/packagist/dt/kinetis/cache-redis" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/cache-redis"><img src="https://img.shields.io/packagist/php-v/kinetis/cache-redis" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/cache-redis"><img src="https://img.shields.io/packagist/l/kinetis/cache-redis" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

`Psr\SimpleCache\CacheInterface`, backed by `Amp\Redis\RedisClient` —
non-blocking, Revolt-native, single-node or Cluster, with TLS support.
This is the concrete Redis implementation `Kinetis\SimpleCache` (in
`kinetis/framework` itself) leaves as an optional add-on: `NullSimpleCache`
ships in core so the interface always has *something* bound, and this
package supplies the real thing once you actually want it.

```php
use Kinetis\SimpleCache\RedisSimpleCache;

$cache = RedisSimpleCache::fromConfig($config); // REDIS_URL or REDIS_HOST
$cache->set('key', 'value', ttl: 60);
```

`Kinetis\Container\AppScope::boot()` picks this up automatically once
installed — `REDIS_CLUSTER=true` + `REDIS_CLUSTER_SEEDS` binds
`ClusteredRedisSimpleCache`, `REDIS_URL`/`REDIS_HOST` alone binds
`RedisSimpleCache`, neither binds `NullSimpleCache` — with zero
application code required either way. Configuring Redis (`REDIS_HOST`/
`REDIS_CLUSTER`) without this package installed is a clear, immediate
error naming the package to install, not a silent fallback to
`NullSimpleCache`.

## Configuration

Read from the environment (or `.env`) via `Kinetis\Config`. Every key
is scoped. With none of `REDIS_URL`/`REDIS_HOST`/`REDIS_CLUSTER` set,
Redis is simply off and `CacheInterface` binds to `NullSimpleCache`.

| Key | Default | Purpose |
|---|---|---|
| `REDIS_URL` | — | Full `redis://` URI; wins over the discrete keys. |
| `REDIS_HOST` | — | Server host. |
| `REDIS_PORT` | `6379` | Port. |
| `REDIS_PASSWORD` | — | Password. |
| `REDIS_DATABASE` | `0` | Database index (single-node only; Cluster has no `SELECT`). |
| `REDIS_TIMEOUT` | `5` | Connect timeout, seconds. |
| `REDIS_TLS` | `false` | Connect over TLS. |
| `REDIS_TLS_VERIFY_PEER` | `true` | Verify the server certificate. |
| `REDIS_TLS_CA_FILE` | — | CA certificate for verification. |
| `REDIS_CLUSTER` | `false` | Use Redis Cluster mode. |
| `REDIS_CLUSTER_SEEDS` | — | Comma-separated seed nodes for Cluster bootstrap. |

Scoped keys follow the named-connection convention — the connection
name inserts after the first segment: `REDIS_HOST` + `cache2` → `REDIS_CACHE2_HOST`.
Full reference across every package:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/cache-redis
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[kinetis.dev/docs/caching.html](https://kinetis.dev/docs/caching.html)
and [kinetis.dev/docs/persistence.html](https://kinetis.dev/docs/persistence.html)
(the Redis Cluster/TLS section).

## License

MIT — see [LICENSE](LICENSE).
