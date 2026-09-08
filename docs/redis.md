# Redis Transport

````{note}
Not part of core, and not tied to it. Install it separately:

```{code-block} sh
composer require kinetis/redis
```

`kinetis/redis` depends on `amphp/redis`, `amphp/socket` and the Revolt
event loop, never on `kinetis/framework`, so any Revolt-based project can
use it. {doc}`persistence` covers the PSR-16 cache built on it, and
{doc}`queue-redis` the queue.
````

A Redis connection that never re-sends a command it could not confirm,
spends one budget per operation, and routes by slot on a Redis Cluster.

`amphp/redis`'s own `ReconnectingRedisLink` re-sends every queued command
after a connection loss. That turns one lost reply into a second `INCR`,
a second `LPUSH`, or a token consumed twice. This package replaces that
transport and adds cluster routing over it.

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

### What a failure carries

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

## One budget per operation

`ClientOptions::$timeout` is the whole budget for one `execute()`,
`executeKeyed()` or `script()` call, in seconds, measured on the
monotonic clock. It covers connecting, the TLS handshake, `AUTH`,
`SELECT`, the write, the reply, cluster discovery, and every redirect
hop. A redirect does not buy more time.

The write is inside the budget as well as the wait for a reply. A peer
that stops reading fills the kernel's buffers and suspends the writing
fiber, and Amp's writable stream has no cancellation of its own, so an
expiry there closes the socket — the one thing that ends that wait.

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

## Connecting

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
unreachable server costs nothing until something is asked of it.

Concurrent fibers share one socket. Commands are written in the order
they were issued and replies are matched in that same order, so N
concurrent calls cost one round trip rather than N. While no reply is
outstanding the socket holds no event-loop reference, which is what lets
`EventLoop::run()` and {doc}`concurrency`'s `concurrently()` return.

`Amp\Redis\RedisClient` composes over the same transport when its typed
command methods are wanted:

```{code-block} php
use Amp\Redis\RedisClient;

$redis = new RedisClient($client->link());
$redis->set('user.1', $payload);
```

An endpoint is `host:port`, or `[address]:port` for IPv6 — an
unbracketed IPv6 address is ambiguous about which colon separates the
port, so it is rejected rather than guessed at.

## Redis Cluster

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
cluster. Two methods tell them apart. `allowsCrossSlotKeys()` is true
only on a single node, where `MGET` and a multi-key `DEL` are one round
trip; a cluster rejects such a command with `CROSSSLOT`. `nodes()`
returns every current master on a cluster and the client itself on a
single node, for commands that must reach the whole keyspace.

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
untried. Concurrent fibers share one in-flight read rather than fanning
one discovery each at the first seed; a fiber waiting on another's read
waits no longer than its own budget and reports its own expiry as
`TopologyUnavailable`, leaving the reader to finish for whoever else is
waiting.

Redis Cluster serves database 0 only, so `ClusterClient::create()`
refuses a non-zero database rather than letting `SELECT` fail on every
node.

### Redirects

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
order. One write is contiguous on the wire, so no other fiber's command
can land between them and no second connection per redirect is needed. A
script under `ASK` is sent as `ASKING` plus `EVAL`, never `EVALSHA` —
`EVALSHA` would consume the `ASKING` and leave a `NOSCRIPT` retry to be
redirected all over again.

One operation follows at most six attempts in total. Reaching that bound
raises `Kinetis\Redis\Exception\RedirectLimitExceeded`, which means slots
are flapping between nodes rather than resharding.

## TLS and authentication

```{code-block} php
use Amp\Socket\ClientTlsContext;

$options = new ClientOptions(
    timeout: 5.0,
    password: $password,
    tls: new ClientTlsContext('')->withCaFile('/etc/ssl/certs/redis-ca.crt'),
);
```

One `ClientOptions` reaches every node: seeds, discovered masters, and
redirect targets alike, since a cluster authenticates and encrypts
identically everywhere. `AUTH` and `SELECT` run inside the connector at
connect time, which is the one place a command may run again on every
reconnect without being a replay of a caller's command.

```{warning}
`MOVED`/`ASK` targets and `CLUSTER SLOTS` entries are IP addresses unless
the cluster is configured with `cluster-announce-hostname` and
`cluster-preferred-endpoint-type hostname`. With peer verification on, a
certificate carrying only hostname SANs verifies against the seed and
fails against every discovered node. Give the certificates IP SANs or
announce hostnames on the server; verification is never relaxed for a
discovered node.
```

The password reaches the wire only as the `AUTH` frame's argument, under
the same rule as a caller's own data: no message, cause or trace argument
this package raises holds it, a URI holding it, or a server reply quoting
a rejected credential.

## Not in scope

No command facade, pub/sub, Sentinel, replica reads, `MULTI`, background
topology watching, cross-slot aggregation, or retry classification. Use
`Amp\Redis\RedisClient` over `link()` for typed commands, and decide
retries where the command's meaning is known.

## See also

- {doc}`persistence` — the PSR-16 cache over this transport, and its
  `REDIS_*` configuration.
- {doc}`queue-redis` — the queue over this transport.
- {doc}`concurrency` — `concurrently()`, and why an idle connection must
  not hold the loop open.
