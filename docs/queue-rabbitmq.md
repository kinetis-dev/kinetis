# Queue (RabbitMQ)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-rabbitmq
```
````

Adds RabbitMQ as a backend for {doc}`queue`. Application code that
already pushes and pops jobs through `QueueInterface` needs no changes
at all to switch — only your configuration changes.

```{code-block} text
QUEUE_CONNECTION=rabbitmq
QUEUE_RABBITMQ_URL=amqp://guest:guest@localhost:5672/
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Every AMQP call this backend makes, including a worker checking for the
next job, runs without blocking the rest of your application.

## Configuring

`QUEUE_RABBITMQ_URL` is required — a standard AMQP URI
(`amqp://user:password@host:port/vhost`). Multiple hosts, separated by
commas, connect to whichever one answers first:

```{code-block} text
QUEUE_RABBITMQ_URL=amqp://guest:guest@rabbit-a:5672,rabbit-b:5672/
```

## Queues are declared for you

Unlike the SQS backend, a queue name you push to (`'default'`, `'high'`,
and so on) doesn't need to exist ahead of time — this backend declares it
(durable) the first time anything touches it. Delayed jobs use a few more
queues and exchanges alongside it, described below and declared the same
way; every one of their names contains a `.`, which a queue name of your
own can never contain, so nothing you name can collide with them.

## Clearing a queue

`RabbitMqQueue` declares `Kinetis\Queue\ClearableQueueInterface` (see
{doc}`queue`'s "Clearing is a separate capability"). Clearing purges the
queue itself and every delay tier, reporting the total the broker says
it removed. `queue.purge` leaves messages already delivered to a
consumer and not yet acked in place — the broker's own rule, which
happens to be exactly the contract's.

A delivery tag is scoped to the channel that produced it, and reusing
one is a channel-level protocol error rather than an answer this package
can read back, so it raises no
`Kinetis\Queue\Exception\StaleJobHandleException` of its own. An
unacked delivery is requeued the moment the connection drops, so a
worker that dies mid-job has its work redelivered rather than stranded —
and a handler has to be idempotent to survive that. See {doc}`queue`'s
"When a settlement is lost".

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Works the same as on the other backends, and needs nothing installed in
your broker — no delayed-message plugin, no scheduler process. RabbitMQ
holds the message itself and routes it to the real queue once it's due.

A delay is a floor: the job is available no sooner than the delay you
asked for, and the broker hands it over when it gets to it. What a delay
is never subject to is another delay. Push an hour-long one and then a
three-second one, and the three-second job comes due in three seconds
with the hour-long one still waiting.

```{code-block} php
$this->queue->push(new HourlyRollup(), delaySeconds: 3600);
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3);
// The reminder is poppable 3 seconds later, not in an hour.
```

That independence is what the queues behind it are for. AMQP 0-9-1 has
no per-message delay, and RabbitMQ expires a queue's messages from the
head — so one holding queue with a per-message expiry would hold the
three-second job for an hour, stuck behind the one in front of it.
Instead, each queue you push to gets a ladder of holding queues,
`{queue}.delay.1s`, `.delay.2s`, `.delay.4s` and so on, each with a
matching `{queue}.delay.{seconds}s.in` topic exchange. A delay is spent
as the binary sum of those tiers — 3600 seconds is 2048 + 1024 + 512 + 16
— and every message in a tier owes that tier's own wait, so nothing in it
can be held up by a message owing longer. Dead-lettering moves a message
from one tier to the next and finally into the real queue, with nothing
polling in between.

What that means for your broker: a queue you push delayed jobs to grows a
tier queue and a topic exchange per power of two, up to the highest one a
delay you actually push needs — an hour-long delay reaches
`.delay.2048s`, twelve tiers. `size()` and `clear()` declare all 22, since
a job parked by another process can be in any of them, so a queue those
have run against shows the full ladder in the management UI. They are
ordinary durable queues, empty except while a job is waiting, and they
need no configuration of their own.

A delayed job is counted by `size()` and removed by `clear()` the same as
any other waiting job, including from a process that never pushed it —
`kinetis queue:stats` and `kinetis queue:clear` see the whole ladder.
Both read the ladder queue by queue rather than at one instant, so a job
moving between tiers as they run can be counted twice, missed, or outrun
the purge. It's a monitoring snapshot, which is what {doc}`queue`
describes these numbers as for.

```{code-block} sh
vendor/bin/kinetis queue:stats --queue=default
```

```{code-block} text
QUEUE    WAITING
default  7
```

The one limit is how long a single delay can be: **4,194,303 seconds,
about 48 days**. The AMQP client this package binds to (`thesis/amqp`)
encodes a tier's `x-message-ttl` as a signed 32-bit number of
milliseconds, which caps the longest tier the ladder can declare, and
the whole ladder spent at once is that ceiling. It comes from the
client's encoding, not from a delay limit RabbitMQ itself sets. A longer
delay is rejected by `push()` with an `InvalidArgumentException` naming
the ceiling, rather than being quietly shortened or published to a queue
that can't hold it that long. (SQS's own cap is 900 seconds — see
{doc}`queue-sqs`.)

## Retries and giving up

Everything {doc}`queue` documents about `maxAttempts`, `QUEUE_MAX_ATTEMPTS`,
and the log entry written when a job is finally given up on works
identically here — nothing about retry behavior changes by switching to
this backend.

Instrumentation propagation metadata (see {doc}`telemetry`) travels as
a JSON-encoded `metadata` header — stored at `push()` (the delay
ladder's dead-letter path included), carried forward by `release()`'s
republish, and read back at `pop()` — so a worker's consumer span
joins the producer's trace.

## Every publish waits for the broker

A publish call returning means the frames reached the socket, not that
RabbitMQ accepted or durably recorded anything. So this backend publishes
on a channel in confirm mode and waits for the broker's own
acknowledgement before treating a message as queued — `push()` and
`release()` both throw
`Kinetis\QueueRabbitMq\Exception\PublishNotConfirmedException` if that
acknowledgement doesn't come, or if the message turns out to be
unroutable (publishing is `mandatory`, so a queue deleted out from under
a worker is an error rather than a silent drop).

For `release()` that ordering is what keeps a retry from disappearing:
the replacement is published, the acknowledgement is awaited, and only
then is the original delivery discarded. A failure anywhere before that
leaves the original unacked, so the broker redelivers it — the job is
still there, on the attempt it was popped on.

## A released job can be delivered twice

`release()` publishes the replacement message, waits for the broker to
acknowledge it, and only then nacks the original — two separate AMQP
operations, since AMQP 0-9-1 has no cross-message transaction to make
them one. That ordering means a crash between the two never loses the
job (the original stays unacked and the broker redelivers it once the
connection drops), but it also means that same crash can leave both the
redelivered original and the freshly published replacement in the queue
at once. RedisQueue's and SqlQueue's own `release()` are each a single
atomic operation and don't have this window — see {doc}`queue`'s backend
comparison for how the four backends differ here. A job handler run
through this backend needs to tolerate being invoked more than once for
the same logical job.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
QUEUE_REPORTS_RABBITMQ_URL=amqp://reports:secret@rabbitmq-reports:5672/reports
QUEUE_REPORTS_RABBITMQ_QUEUE_PREFIX=myapp-reports-
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`QUEUE_CONNECTION_NAME` picks which named block of `QUEUE_RABBITMQ_*`
settings a worker reads, and `'default'` (or simply not setting it) reads
the plain keys shown earlier in this page. `QUEUE_RABBITMQ_QUEUE_PREFIX`
(optional, either connection) is prepended to every queue name — useful
when staging and production share one broker and need to stay on separate
queues without both trying to use a plain name like `default`.

## If the package isn't installed

Setting `QUEUE_CONNECTION=rabbitmq` without having run
`composer require kinetis/queue-rabbitmq` produces a clear error telling
you which package to install, rather than a confusing crash.

```{note}
On PHP 8.5, `thesis/amqp`'s own transitive dependency on `thesis/endian`
(pinned to its `0.1.x` line — a constraint set by `thesis/amqp` itself,
not by this package) emits repeated `chr(): Providing a value not
in-between 0 and 255 is deprecated` notices from its byte-packing code.
Confirmed harmless — the deprecated `chr()` behavior still masks the
value to a single byte exactly as before, just with a notice — and not
fixable from this package: no stable `thesis/amqp` release yet requires
a `thesis/endian` version that corrects it, and this project does not
pull in an unreleased dev branch to chase a deprecation notice. Track
`thesis/amqp`'s own releases; this note goes away once one does.
```

## See also

- {doc}`queue` — writing jobs, pushing and popping, and everything about
  retries that applies to every backend equally.
- {doc}`config` — the named-connection convention used above.
