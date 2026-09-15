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

Retries follow {doc}`queue`: `maxAttempts`, `QUEUE_MAX_ATTEMPTS`, and
immediate release.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — publish confirmation, the delay ladder and
  delivery contracts.
- {doc}`config` — named connections and every `QUEUE_RABBITMQ_*` key.
