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
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

## Configuring

This package introduces no configuration keys of its own — every
`REDIS_*` setting `RedisSimpleCache` ({doc}`persistence`) already reads
is the exact one this backend reads too, scoped by
`QUEUE_CONNECTION_NAME` the same way as everywhere else in Kinetis.

## A crashed worker's job is never lost

A naive Redis list `pop()` removes the item at pop time — if a worker
crashed mid-job, it would just be gone, with no way to detect or retry
it. This backend uses the "reliable queue" pattern instead: `pop()`
atomically moves a job's payload from a queue's `pending` list to a
separate `processing` list, rather than deleting it outright. `ack()`
removes it from `processing`; `release()` moves it back onto `pending`.
A job whose worker crashes before any of `ack()`/`release()`/`fail()`
runs is stranded, not lost — it stays exactly where a future reaper
would find it, though this backend doesn't ship one yet: closing that
gap is a disclosed, still-open limitation.

The two transitions that could otherwise lose a job outright —
`release()` (`processing` → `pending`) and delayed-job promotion
(`delayed` → `pending`) — each run as a single Lua script, which Redis
always executes as one indivisible unit. A process crash can never land
between the two halves of either move. `release()`'s move is also
conditional, not just indivisible: calling it a second time with the
same `QueuedJob` — a duplicate call, or a retry after a connection
failure whose server-side outcome wasn't known — throws
`Kinetis\Queue\Exception\StaleJobHandleException` instead of enqueueing
a second replacement. `QueueWorker` keeps running and reports the lost
delivery — see {doc}`queue`'s "When a settlement is lost".

`ack()` and `fail()` are fenced the same way, from the same signal:
`LREM` reports how many entries it removed, so a zero means the
processing list held nothing for that handle and the settlement raises
rather than reporting a removal that never happened.

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
concurrent push could have moved underneath it. The processing list is
untouched.

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
- {doc}`config` — the named-connection convention used above.
