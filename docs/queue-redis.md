# Queue (Redis)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-redis
```
````

Adds Redis as a backend for {doc}`queue`. Application code that already
pushes and pops jobs through `QueueInterface` needs no changes at all to
switch — only your configuration changes.

```{code-block} text
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
QUEUE_VISIBILITY_TIMEOUT_SECONDS=300
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

## Configuring

`QUEUE_VISIBILITY_TIMEOUT_SECONDS` is the one key this package
introduces: how many seconds a reservation is leased before any worker
may reclaim it. It defaults to 300 and must be a positive integer.

Every other setting this backend reads is a `REDIS_*` one
`RedisSimpleCache` ({doc}`persistence`) already reads, `REDIS_TLS*`
included, scoped by `QUEUE_CONNECTION_NAME` the same way as everywhere
else in Kinetis. `REDIS_CLUSTER` is not among them: this backend is
single-node. The queue opens its own connection over {doc}`redis`'s
transport rather than sharing the cache's, so its operation budget is
`REDIS_TIMEOUT` and its connection lifetime is its own.

## A crashed worker's job comes back

A naive Redis list `pop()` removes the item at pop time — if a worker
crashed mid-job, it would just be gone, with no way to detect or retry
it. This backend reserves under a finite lease instead. Each queue has
three keys: a `pending` list, a `delayed` sorted set scored by ready-at
time, and a `leased` sorted set scored by lease expiry. `pop()` adds the
exact envelope it is about to hand out to `leased` with an expiry of
`QUEUE_VISIBILITY_TIMEOUT_SECONDS` from now, and only then removes it
from `pending` — that order is what makes a failure on the leased key
leave the sole pending copy intact.

Expiries are compared against Redis's own `TIME`, so every worker shares
one lease clock regardless of its own. Any worker's `pop()` reclaims
expired leases for the queues it is asked for, so a job whose worker died
before `ack()`/`release()`/`fail()` is redelivered with `attempts`
incremented. There is no reaper process.

Every state transition that could otherwise lose or duplicate a job —
reservation, `release()`, reclaim, and delayed-job promotion — runs as a
single Lua script, which Redis executes as one indivisible unit, so a
process crash can never land between the halves of a move. Reclaim and
`release()` are conditional as well as indivisible: each checks that the
exact old member is still leased before writing its replacement, so two
sweepers racing, or a sweep racing a settlement, produce one winner
rather than a duplicate.

The leased member is the exact envelope string handed back as
`QueuedJob::$handle`, and a reclaim rewrites it with the incremented
attempt count. That makes the handle a fence: `ack()`, `release()` and
`fail()` act only on that exact member, so a settlement for a delivery
that has already been settled or reclaimed — a duplicate call, or a retry
after a connection failure whose server-side outcome wasn't known —
throws `Kinetis\Queue\Exception\StaleJobHandleException` and writes
nothing. `QueueWorker` keeps running and reports the lost delivery — see
{doc}`queue`'s "When a settlement is lost".

Expired leases are swept in bounded batches
(`RedisQueue::LEASE_RECLAIM_BATCH_SIZE`, currently 100) for the same
reason promotion is. An abandoned lease whose envelope no longer decodes
is settled as poison data through
`Kinetis\Queue\QueueContract::settleIfMalformed()`, so it is removed
rather than reclaimed forever.

A lease is never renewed. A job still running when its lease expires can
execute alongside its replacement, so set the timeout above the slowest
job you expect and keep handlers idempotent. `maxAttempts` bounds a
handler that throws; it cannot bound a succession of processes that each
die during execution.

Delayed-job promotion also bounds how much it moves in one call
(`RedisQueue::DELAYED_PROMOTION_BATCH_SIZE`, currently 100) — a large
ready backlog is promoted in batches across successive polls rather than
inside one Lua script, since Redis executes one command at a time and an
unbounded promotion would stall every other client sharing that Redis
for its full duration.

## Clearing a queue

`RedisQueue` declares `Kinetis\Queue\ClearableQueueInterface` (see
{doc}`queue`'s "Clearing is a separate capability"). Clearing counts and
removes the queue's pending and delayed entries in one Lua script, so
the number it reports is what it removed rather than a count a
concurrent push could have moved underneath it. Live leases are
untouched — they are work a running worker still owns. `size()` counts
pending, delayed and expired leases, and not live ones.

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Checked on this backend's own polling cycle rather than firing at the
exact moment the delay ends, so a delayed job can run slightly later
than its exact target time — typically by a few seconds, not less.

## Retries and giving up

Everything {doc}`queue` documents about `maxAttempts`, `QUEUE_MAX_ATTEMPTS`,
and the log entry written when a job is finally given up on works
identically here — nothing about retry behavior changes by switching to
this backend.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
REDIS_REPORTS_HOST=127.0.0.1
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`QUEUE_CONNECTION_NAME` picks which named block of `REDIS_*` settings a
worker reads, and `'default'` (or simply not setting it) reads the plain
keys shown earlier in this page.

## If the package isn't installed

Setting `QUEUE_CONNECTION=redis` without having run
`composer require kinetis/queue-redis` produces a clear error telling you
which package to install, rather than a confusing crash.

## See also

- {doc}`queue` — writing jobs, pushing and popping, and everything about
  retries that applies to every backend equally.
- {doc}`persistence` — the `REDIS_*` configuration convention this
  backend reuses.
- {doc}`redis` — the transport underneath: what a failed command's
  outcome means, and why one is never re-sent.
- {doc}`config` — the named-connection convention used above.
