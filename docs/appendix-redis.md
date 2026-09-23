# Appendix: Redis

The complete `kinetis/redis` and `kinetis/cache-redis` contract behind
{doc}`redis`: using the transport directly, what its failures mean and
carry, how the operation budget and cluster routing behave, and what the
cache sends. {doc}`appendix-packages` lists both packages' classes, and
the [`kinetis/redis` README](https://github.com/kinetis-dev/redis#readme)
covers standalone use outside Kinetis.

## Why the transport replaces amphp's

`amphp/redis`'s own `ReconnectingRedisLink` re-sends every queued command
after a connection loss. That turns one lost reply into a second `INCR`,
a second `LPUSH`, or a token consumed twice. `kinetis/redis` replaces that
transport, never re-sends a command it could not confirm, spends one
budget per operation, and adds cluster routing over it.

(redis-reference-client)=
## Using the transport directly

```{code-block} sh
composer require kinetis/redis
```

`kinetis/redis` depends on `amphp/redis`, `amphp/socket` and the Revolt
event loop, never on `kinetis/framework`, so any Revolt-based project can
use it.

```{code-block} php
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Endpoint;

$client = Client::create(
    Endpoint::parse('cache.internal:6379'),
    new ClientOptions(timeout: 5.0, password: $password, database: 0),
);

$client->execute('SET', 'user.1', $payload, 'EX', 60);
$value = $client->execute('GET', 'user.1');
```

`Client::create()` opens nothing. The socket, the handshake, `AUTH` and
`SELECT` all happen on the first command, so a configured but momentarily
unreachable server costs nothing until something is asked of it. Build
one client and share it: concurrent Fibers share one socket, with
commands written and replies matched in order, so N concurrent calls cost
one round trip rather than N. While no reply is outstanding the socket
holds no event-loop reference, which is what lets `EventLoop::run()` and
{doc}`concurrency`'s `concurrently()` return.

Nothing binds a transport client at boot. A Kinetis application builds
one in `bootstrap.php` and registers it, and a class then injects
`Client`:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Redis\Client;
use Kinetis\Redis\ClientOptions;
use Kinetis\Redis\Endpoint;

return static function (AppScope $app, Config $config): void {
    $app->instance(Client::class, Client::create(
        Endpoint::fromParts($config->required('REDIS_HOST'), $config->int('REDIS_PORT', 6379)),
        new ClientOptions(timeout: $config->float('REDIS_TIMEOUT', 5.0), password: $config->get('REDIS_PASSWORD')),
    ));
};
```

The client reads the same keys as the cache but holds a connection of its
own.

`Amp\Redis\RedisClient` composes over the same transport when its typed
command methods are wanted:

```{code-block} php
use Amp\Redis\RedisClient;

$redis = new RedisClient($client->link());
$redis->set('user.1', $payload);
```

An endpoint is `host:port`, or `[address]:port` for IPv6; an unbracketed
IPv6 address is rejected rather than guessed at.

(redis-reference-failures)=
## What a failure means

Every failure answers one question: could the command have executed?

`Kinetis\Redis\Exception\ConnectionFailed` — no. The connection was never
established, the budget expired or was already spent before the command
was dispatched, or the client was closed under a connection still being
set up. No byte reached Redis, so retrying is safe.

`Kinetis\Redis\Exception\OutcomeUnknown` — possibly. The command was
written and no reply came back: the connection dropped, the budget
expired, or the wait was cancelled. Nothing on the client can tell which,
so the command is never written again. A caller that wants to retry has
to make the command idempotent or reconcile the effect itself.

A Redis error reply is neither: it arrives as
`Amp\Redis\Protocol\QueryException`. A reply makes the outcome known —
the server read the command and answered it — but the answer may be a
rejection, so `WRONGTYPE` or `NOSCRIPT` means the command had no effect.
What the error means for the data is the command's own business.

The one command sent twice is one a cluster answered with `MOVED` or
`ASK`. That reply is proof the node did not execute it.

## Cluster routing

```{code-block} php
use Kinetis\Redis\ClusterClient;

$cluster = ClusterClient::create(
    [Endpoint::parse('10.0.0.1:6379'), Endpoint::parse('10.0.0.2:6379')],
    new ClientOptions(timeout: 5.0),
);

$cluster->executeKeyed('user.1', 'GET', 'user.1');
$cluster->script('user.1', "return redis.call('INCR', KEYS[1])", ['user.1']);
```

The routing key is always the caller's to name. This package never
guesses it from a command's parameters: `EVAL`'s first parameter is a
script, plenty of commands have no key at all, and a wrong guess sends
the command to the wrong node.

`Client` implements the same `RoutedExecutor` interface and ignores the
routing key, so one consumer works unchanged against a single node and a
cluster. `allowsCrossSlotKeys()` is true only on a single node, where
`MGET` and a multi-key `DEL` are one round trip; a cluster rejects such a
command with `CROSSSLOT`. `nodes()` returns every current master on a
cluster and the client itself on a single node, for commands that must
reach the whole keyspace. `ClusterClient::create()` refuses a non-zero
database rather than letting `SELECT` fail on every node.

## TLS and authentication

```{code-block} php
use Amp\Socket\ClientTlsContext;

$options = new ClientOptions(
    timeout: 5.0,
    password: $password,
    tls: new ClientTlsContext('')->withCaFile('/etc/ssl/certs/redis-ca.crt'),
);
```

One `ClientOptions` reaches every node — seeds, discovered masters and
redirect targets alike — since a cluster authenticates and encrypts
identically everywhere. The certificate warning in {doc}`redis`'s
"Redis Cluster" applies to every discovered node.

## What a failure carries

A failure names the endpoint, the command and what went wrong. No message
this package writes holds a routing key, a command argument, a script
body or a password, and every parameter that carries one is marked
`#[\SensitiveParameter]` — from `execute()`, `executeKeyed()` and
`script()` down to the socket write — so PHP records a redaction marker
in place of it among a trace's arguments. Arguments reach a trace more
often than a deployment expects: `zend.exception_ignore_args` compiles in
as `0`, and the official `php` images activate no `php.ini` at all, so an
image that has not copied `php.ini-production` keeps every call argument
in every trace it renders.

The boundary is the failure this package raises and everything reachable
from it: its message, its trace, the arguments in that trace, the objects
among them, and anything chained as its cause. `amphp/byte-stream` holds
the encoded command as its own write frame's unmarked argument, so a
write that fails there is reported as a fixed message — whether the
budget expired or the connection failed, and nothing more — and is never
chained onto the `OutcomeUnknown`. A cancellation reaches that same frame
by another route: it retains the exception it raised, and that
exception's trace is the stack the operation was suspended on. So a
parameter carrying a `Deadline`, or a cancellation taken from one, is
marked like a parameter carrying a key.

Marking a parameter keeps the payload out of what a trace holds; it does
not erase it from memory. `SensitiveParameterValue` is the marker PHP
records in a redacted argument's place, and the caller's own string lives
for as long as the caller holds it.

The password reaches the wire only as the `AUTH` frame's argument, under
the same rule as a caller's own data: no message, cause or trace argument
this package raises holds it, a URI holding it, or a server reply quoting
a rejected credential.

## The operation budget

`ClientOptions::$timeout` is the whole budget for one `execute()`,
`executeKeyed()` or `script()` call, in seconds, measured on the
monotonic clock. It covers connecting, the TLS handshake, `AUTH`,
`SELECT`, the write, the reply, cluster discovery, and every redirect
hop; a redirect does not buy more time. The write is inside it as
well as the wait for a reply: a peer that stops reading fills the
kernel's buffers and suspends the writing Fiber, and Amp's writable
stream has no cancellation of its own, so an expiry there closes the
socket — the one thing that ends that wait.

A budget already spent when a command reaches the transport is refused
before a byte is written, as `ConnectionFailed`: nothing was dispatched,
so retrying is safe. That holds for the second and later dispatches of
one operation too — a redirect hop, or the `EVAL` after a `NOSCRIPT`.

When the budget expires after the command was written, the connection is
closed and every command in flight on it fails once with
`OutcomeUnknown`. The next operation opens a new connection. Closing is
what makes a half-open connection to a vanished server heal: no
transport error ever arrives on one, so waiting for one would wedge the
link for the life of the worker. The cost is that one stalled command
fails its pipelined neighbours with it.

`close()` reaches a connection still being established: its setup is
aborted, and a socket whose `AUTH` completes after that is dropped
rather than handed to a caller, so no command is written on a client
that has already been closed.

`AUTH` and `SELECT` run inside the connector at connect time, which is
the one place a command may run again on every reconnect without being a
replay of a caller's command.

## Cluster topology

The slot map comes from `CLUSTER SLOTS` on the first operation and not
before, and each node's connection opens on its own first command. Under
a persistent worker the map and the connections live as long as the
client; under boot-and-die every request pays one `CLUSTER SLOTS` round
trip on first use. Only a reply covering slots 0-16383 exactly once is
accepted, so a partially-formed cluster is refused rather than routed
against.

A `ConnectionFailed` from a routed operation drops the cached map, and
the operation itself still fails: the next one reads `CLUSTER SLOTS`
again and routes at the owner the cluster names then, so an owner that
stops answering under a persistent worker costs one failed operation
rather than every operation until the process restarts. The exception
reports every pre-dispatch failure, an already-spent budget included,
and dropping the map on all of them costs one rediscovery while
recovering the case that matters. The failed command is not sent again —
that stays yours to decide, under the same rule as any other
`ConnectionFailed`. An `OutcomeUnknown` leaves the map in place: an
ambiguous outcome does not establish that the cached topology is stale,
and that command is never re-sent either.

The seeds are read in order, each given an equal share of what is left
of the operation's budget, so a seed that accepts the connection and
never answers cannot spend all of it and leave a healthy seed behind it
untried. Concurrent Fibers share one in-flight read rather than fanning
out one discovery each at the first seed; a Fiber waiting on another's
read waits no longer than its own budget and reports its own expiry as
`TopologyUnavailable`, leaving the reader to finish for whoever else is
waiting.

## Redirects

`MOVED` names a slot's new owner. That reply is proof of both the slot
and the node, so the slot is patched from it and the command is sent
straight to the target — a topology read in between would spend the
budget at a seed for an answer already in hand, and a seed still
reporting the old owner would contradict what the reply just proved. The
patch stands until the next discovery replaces the whole map.

`ASK` names where one key has already migrated while the slot's stable
owner has not changed, so it never becomes the slot's recorded owner. The
package writes `ASKING` and the redirected command as a single socket
write on the target's existing connection and reads the two replies in
order. One write is contiguous on the wire, so no other Fiber's command
can land between them and no second connection per redirect is needed. A
script under `ASK` is sent as `ASKING` plus `EVAL`, never `EVALSHA` —
`EVALSHA` would consume the `ASKING` and leave a `NOSCRIPT` retry to be
redirected all over again.

One operation follows at most six attempts in total. Reaching that bound
raises `Kinetis\Redis\Exception\RedirectLimitExceeded`, which means slots
are flapping between nodes rather than resharding.

## The cache over the transport

`RedisSimpleCache` holds a `Kinetis\Redis\RoutedExecutor` — `Client` for
a single node, `ClusterClient` under `REDIS_CLUSTER=true`. Values are
serialized with `Amp\Serialization\NativeSerializer`. The
`kinetis_cache:<namespace>:` prefix is what keeps `clear()` off keys this
cache did not write, and it carries no `{}` hash tag, so keys still
spread across cluster slots.

`clear()` scans each current master for its own namespace prefix and
unlinks what it finds, so keys written by anything else — including
another namespace of this same cache — survive it. It is neither atomic
nor a snapshot: a key written after its node's scan has passed survives,
and a key migrating between two nodes can be missed. It also costs one
pass over each node's whole keyspace, since `SCAN MATCH` filters
server-side after reading.

The executor's overhead is paid per event-loop wakeup rather than per
command, so it amortizes across whatever else is in flight at the same
time. Under a persistent worker that is the normal state: once around
eight concurrent requests hold an outstanding Redis command, per-operation
client CPU settles to roughly a quarter of what a single isolated command
costs, and no request blocks the worker thread while it waits. Under
PHP-FPM a process handles exactly one request at a time, so there is
nothing to amortize against and every cache operation pays the full
per-wakeup cost; batching is the lever that matters there.

(redis-reference-cache-ownership)=
### Who closes the connection

`RedisSimpleCache` implements core's
`Kinetis\SimpleCache\DisposableCacheInterface`. `fromConfig()` opens its
own executor and hands the cache that executor's `close()`, so
`dispose()` closes it. A cache constructed directly around an executor
the caller holds borrows it, and `dispose()` closes nothing unless the
constructor's `disposer` argument hands over that executor's
`close(...)` too. `dispose()` is idempotent and opens no connection, so
a worker that never used the cache disposes it cleanly.

`AppScope::boot()` registers `dispose()` on `AppScope::onDispose()` for
the default cache it builds, and for no other: a cache the application
binds itself, including one it wraps in a decorator, is the
application's to dispose.

(redis-reference-batching)=
### Batched reads and deletes

`getMultiple()` and `deleteMultiple()` are the largest performance lever
this cache has. On a single node `getMultiple()` issues one `MGET` and
`deleteMultiple()` one `DEL`: one round trip and one reply parsed instead
of N of each, which costs roughly a tenth of the client CPU per key that
the same keys fetched one `get()` at a time do. A cluster rejects a
multi-key command whose keys span slots, so there both send one command
per key, dispatched concurrently rather than one after another.
`setMultiple()` saves no round trips on any topology.
{doc}`appendix-packages` gives the commands each topology receives.

```{code-block} php
// One round trip on a single node, one concurrent command per key on a
// cluster.
$rows = $this->cache->getMultiple(['user.1', 'user.2', 'user.3']);

// N sequential round trips either way.
foreach ([1, 2, 3] as $id) {
    $rows[] = $this->cache->get("user.{$id}");
}
```

(redis-reference-named-cache)=
### A named cache connection

`RedisSimpleCache::fromConfig()` follows {doc}`config`'s
named-connection convention, and `AppScope::boot()` keeps a
`CacheInterface` the application registered itself. To back the cache
with the `REDIS_SESSIONS_*` keys instead of the default ones:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\SimpleCache\RedisSimpleCache;
use Psr\SimpleCache\CacheInterface;

return static function (AppScope $app, Config $config): void {
    $cache = RedisSimpleCache::fromConfig($config, 'sessions')
        ?? throw new RuntimeException('No REDIS_SESSIONS_* connection is configured.');
    $app->onDispose($cache->dispose(...));

    $app->instance(CacheInterface::class, $cache);
};
```

`fromConfig()` returns `null` when the connection sets none of
`REDIS_URL`, `REDIS_HOST` or `REDIS_CLUSTER=true`.

The `onDispose()` line closes that connection when the worker ends,
since a cache the application binds is its own to dispose
({ref}`redis-reference-cache-ownership`).

## Not in scope

No command facade, pub/sub, Sentinel, replica reads, `MULTI`, background
topology watching, cross-slot aggregation, or retry classification. Use
`Amp\Redis\RedisClient` over `link()` for typed commands, and decide
retries where the command's meaning is known.

## See also

- {doc}`redis` — configuring the cache, TLS and Redis Cluster.
- {doc}`queue-redis` — the queue over this transport.
- {doc}`concurrency` — `concurrently()`, and why an idle connection must
  not hold the loop open.
