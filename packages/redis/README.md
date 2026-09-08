<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/redis</strong>
  <br>
  <strong>A non-replaying, deadline-bounded Redis transport with Cluster routing</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/redis"><img src="https://img.shields.io/packagist/v/kinetis/redis?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/redis"><img src="https://img.shields.io/packagist/dt/kinetis/redis" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/redis"><img src="https://img.shields.io/packagist/php-v/kinetis/redis" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/redis"><img src="https://img.shields.io/packagist/l/kinetis/redis" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Usable by any Revolt-based project: it depends on `amphp/redis`,
`amphp/socket` and the event loop, never on
[`kinetis/framework`](https://github.com/kinetis-dev/framework).

`amphp/redis`'s own `ReconnectingRedisLink` re-sends every queued command
after a connection loss, so a command whose outcome is unknown can be
applied twice — a double `INCR`, a queue job pushed twice. This package
replaces that transport and adds Redis Cluster routing on top of it.

- **A command is never re-sent after a connection failure.** A failure
  before the write is `ConnectionFailed` and is safe to retry. A failure
  after it is `OutcomeUnknown`, and this package does not decide for you
  what to do about it.
- **One budget per operation** covers connect, TLS, `AUTH`, `SELECT`, the
  write, the reply, cluster discovery, and every redirect hop.
- **Pipelined and idle-clean.** Concurrent fibers share one socket, FIFO;
  an idle connection holds no event-loop reference, so `EventLoop::run()`
  returns.
- **Cluster routing** with `CLUSTER SLOTS` discovery, MOVED/ASK, and a
  six-attempt bound. Routing keys are supplied by the caller, never
  guessed from a command's parameters. A pre-dispatch `ConnectionFailed`
  drops the slot map, so an owner it names that stops answering is
  routed around after one rediscovery.

```php
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\ClusterClient;
use Kinetis\Redis\Endpoint;

$client = Client::create(Endpoint::parse('127.0.0.1:6379'), new ClientOptions(timeout: 5.0));
$client->execute('SET', 'user.1', 'payload', 'EX', 60);

$cluster = ClusterClient::create(
    [Endpoint::parse('10.0.0.1:6379'), Endpoint::parse('10.0.0.2:6379')],
    new ClientOptions(timeout: 5.0),
);
$cluster->executeKeyed('user.1', 'GET', 'user.1');
```

`amphp/redis`'s typed command facade composes over the same transport:

```php
use Amp\Redis\RedisClient;

$redis = new RedisClient($client->link());
$redis->set('user.1', 'payload');
```

## Not in scope

No command facade, pub/sub, Sentinel, replica reads, `MULTI`, background
topology watching, cross-slot aggregation, or retry classification. Use
`Amp\Redis\RedisClient` over `link()` for typed commands.

## Installation

```sh
composer require kinetis/redis
```

Requires PHP 8.4+. Full documentation:
[kinetis.dev/docs/redis.html](https://kinetis.dev/docs/redis.html).

## License

MIT — see [LICENSE](LICENSE).
