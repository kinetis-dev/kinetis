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

## Installation

```sh
composer require kinetis/cache-redis
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/caching.html](https://docs.kinetis.dev/caching.html)
and [docs.kinetis.dev/persistence.html](https://docs.kinetis.dev/persistence.html)
(the Redis Cluster/TLS section).

## License

MIT — see [LICENSE](../../LICENSE).
