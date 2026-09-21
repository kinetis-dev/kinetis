# Queue (RabbitMQ)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-rabbitmq
```
````

Adds RabbitMQ as a backend for {doc}`queue`. Switching to it changes
configuration, not application code.

```{code-block} text
QUEUE_CONNECTION=rabbitmq
QUEUE_RABBITMQ_URL=amqp://guest:guest@localhost:5672/
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Every AMQP call this backend makes, including a worker checking for its
next job, suspends the calling Fiber instead of blocking the process.

## Configuring

`QUEUE_RABBITMQ_URL` is required — a standard AMQP URI
(`amqp://user:password@host:port/vhost`). Several hosts, separated by
commas, connect to whichever answers first:

```{code-block} text
QUEUE_RABBITMQ_URL=amqp://guest:guest@rabbit-a:5672,rabbit-b:5672/
```

Username, password and vhost are percent-decoded, so a value carrying a
URI delimiter is written encoded — `%40` for `@`, `%2F` for `/`:

```{code-block} text
QUEUE_RABBITMQ_URL=amqp://guest:p%40ssword@rabbit:5672/%2Fstaging
```

That authenticates as `guest` with the password `p@ssword` against the
vhost `/staging`. An empty path, the trailing `/` in every example
above, is the default vhost `/`.

`QUEUE_RABBITMQ_QUEUE_PREFIX` (optional) is prepended to every queue
name, so staging and production can share one broker without both using
`default`. Named connections scope both keys:

```{code-block} text
QUEUE_CONNECTION_NAME=reports
QUEUE_REPORTS_RABBITMQ_URL=amqp://reports:secret@rabbitmq-reports:5672/reports
QUEUE_REPORTS_RABBITMQ_QUEUE_PREFIX=myapp-reports-
```

## Queues are declared for you

A queue name you push to does not need to exist ahead of time: the
backend declares it durable the first time anything touches it. Delayed
jobs use further queues and exchanges, declared the same way, whose names
cannot collide with yours (see the
[delay ladder](appendix-queue.md#rabbitmq)).

## Every publish waits for the broker

`push()` and `release()` publish on a channel in confirm mode and return
only after RabbitMQ acknowledges the message. Publishing is `mandatory`,
so a queue deleted under a worker is an error rather than a silent drop.
Without an acknowledgement both throw
`Kinetis\QueueRabbitMq\Exception\PublishNotConfirmedException`; after a
`push()`, treat the job as possibly queued (see {doc}`queue`'s "When
`push()` throws").

Waiting is not the same as bounded. `QueueInterface::push()` takes no
deadline and no cancellation, and the confirmation this backend awaits
offers neither, so once the message is out the wait lasts as long as the
broker takes to answer or the connection takes to fail. The two bounds
the AMQP URI does carry are connection properties:

```{code-block} text
QUEUE_RABBITMQ_URL=amqp://guest:guest@rabbit:5672/?connection_timeout=10&heartbeat=60
```

`connection_timeout` (seconds, default `10`) bounds establishing the
connection. `heartbeat` (seconds, default `60`) is the interval at which
the peers exchange heartbeats, which is how a connection that has
stopped answering gets noticed at all. Neither is a ceiling on a
confirmation, and this backend offers no in-process one: no argument on
`push()` or on the confirmation carries a deadline or a cancellation, so
nothing in the application can end that wait once it has begun.

What can end it is something outside the process killing the process — a
container stop timeout, a SAPI request limit. That bounds the process,
not the publish: the message may already be at the broker, and the
publish outcome is then the unknown one {doc}`queue`'s "When `push()`
throws" describes. Treat the job as possibly queued rather than as not
sent.

## A released job can be delivered twice

`release()` publishes the replacement, waits for the broker to
acknowledge it, and only then discards the original — two AMQP
operations, since AMQP 0-9-1 has no transaction spanning messages. A
failure before the acknowledgement leaves the original unacked, so the
broker redelivers it once the connection drops and the job is not lost.
A worker that stops between the two leaves both the replacement and the
redelivered original queued, and the job runs twice.

A worker that dies mid-job has its delivery redelivered as soon as its
connection drops, with the attempt count unchanged, so `maxAttempts`
does not count those runs. Handlers on this backend must tolerate
running more than once. [RabbitMQ mechanisms](appendix-queue.md#rabbitmq)
has the full settlement sequence.

## Attempts the broker does not count

`QueuedJob::$attempts` travels in the message the application published,
so a redelivery of that same message arrives carrying the same number. A
worker killed mid-job, or a connection that dropped before the job
settled, costs the job a real processing attempt that `maxAttempts`
never sees. What `maxAttempts` bounds is how many times the application
itself released a job — a backstop against a retry loop, not a count of
failures.

A domain rule that has to act on processing failures records them
itself, durably, keyed by the logical work rather than by the delivery —
the same identifier the handler already needs to be idempotent. Counting
them exactly is not available to a process that can be killed mid-job,
so the choice is which error to take:

- **Record a claim before the work.** Commit a row for this work id in
  its own transaction, before the effect's transaction runs, and read
  that count. It survives a hard process death and a broker redelivery,
  which is what a durable cap needs, and it overcounts: a delivery that
  dies before doing anything useful has still spent an attempt.
- **Record a failure after the work.** Write the row from the handler's
  own `catch`. It never overcounts, and it cannot see a worker that was
  killed — a job that dies that way every time is never capped by it.

A counter written inside the effect's own transaction is neither: it
rolls back with the effect it was meant to count.

## Connection lifetime

`RabbitMqQueueFactory::fromConfig()` builds the queue's
`Thesis\Amqp\Client` and hands the queue that client's `disconnect()`,
which closes this instance's channel and the connection. With
`QUEUE_CONNECTION=rabbitmq` that runs when the worker ends, with no
wiring of yours; build the backend yourself and registering
`$app->onDispose($queue->dispose(...))` is yours too. A `RabbitMqQueue`
constructed around a client you built disconnects nothing — see
{doc}`appendix-queue`'s "Connection ownership".

## Clearing a queue

`RabbitMqQueue` declares `ClearableQueueInterface` (see {doc}`queue`'s
"Clearing is a separate capability"). Clearing purges the queue and all
of its delay queues and reports the total the broker removed. Messages
delivered to a worker and not yet acked stay in place.

`queue:stats` and `queue:clear` see delayed jobs from any process, but
they read the queue and its delay queues one at a time, not at one
instant. A job moving between delay queues meanwhile can be counted
twice, missed, or survive the purge.

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Delays need nothing installed in the broker — no delayed-message plugin
and no scheduler process. A delay is a floor: the job is available no
sooner than the delay, and the broker hands it over when it reaches it.
One delay never waits behind another:

```{code-block} php
$this->queue->push(new HourlyRollup(), delaySeconds: 3600);
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3);
// The reminder is poppable 3 seconds later, not in an hour.
```

A single delay can be at most **4,194,303 seconds, about 48 days**, a
limit of the AMQP client's encoding rather than of RabbitMQ. `push()`
rejects a longer delay with an `InvalidArgumentException` naming the
ceiling. [RabbitMQ mechanisms](appendix-queue.md#rabbitmq) explains the
delay ladder and its ceiling.

## Retries and giving up

Retries follow {doc}`queue`: `maxAttempts`, `QUEUE_MAX_ATTEMPTS` and
`QUEUE_RETRY_BASE_DELAY_SECONDS`. A delayed retry publishes its
replacement into the same delay ladder a delayed push uses, subject to
the same ceiling, and is still confirmed before the original delivery is
discarded.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — publish confirmation, the delay ladder and
  delivery contracts.
- {doc}`config` — named connections and every `QUEUE_RABBITMQ_*` key.
