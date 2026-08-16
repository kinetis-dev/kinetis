# Queue (RabbitMQ)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-rabbitmq
```
````

Adds RabbitMQ as another backend for {doc}`queue`, alongside Redis, SQL,
and SQS. Application code that already pushes and pops jobs through
`QueueInterface` needs no changes at all to switch — only your
configuration changes.

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
(durable) the first time anything touches it. Don't name a queue ending
in `.delay`; that suffix is reserved for the internal queue delayed jobs
route through (see below).

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Works the same as on the other backends. The delay is broker-driven —
RabbitMQ itself holds the message until it expires, then delivers it —
with no fixed cap the way SQS's 900-second limit has.

## Retries and giving up

Everything {doc}`queue` documents about `maxAttempts`, `QUEUE_MAX_ATTEMPTS`,
and the log entry written when a job is finally given up on works
identically here — nothing about retry behavior changes by switching to
this backend.

Instrumentation propagation metadata (see {doc}`telemetry`) travels as
a JSON-encoded `metadata` header — stored at `push()` (the delay
queue's dead-letter path included), carried forward by `release()`'s
republish, and read back at `pop()` — so a worker's consumer span
joins the producer's trace.

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

## Opening this connection disables `concurrently()` in that process

Once anything calls `push()` or `pop()` for the first time,
`Kinetis\Async\concurrently()` can't be called again anywhere in that
same process, for any reason, for as long as the connection stays open —
which is indefinitely, since nothing closes it automatically. RabbitMQ
keeps a connection open and listening at all times (so it can receive
deliveries and heartbeats the moment they arrive, not only in response to
something you asked for), and `concurrently()` waits for every pending
operation in the process to settle before it returns — which never
happens while that connection stays open.

This never affects the `kinetis queue:work` loop itself. It does
affect two other things:

- **A job's own `handle()`** reaching for `concurrently()` for its own
  unrelated work, once any code in that process has opened a connection
  to this backend.
- **A persistent HTTP worker (FrankenPHP), not just a queue worker.** If a
  controller calls `push()` to enqueue a job, that opens the connection in
  the *request-handling* worker process too — and a persistent worker
  keeps running that same process across many unrelated requests
  afterward. Every later request that process happens to serve loses the
  ability to call `concurrently()` from that point on, even one that never
  touches this queue at all, until the worker restarts.

## If the package isn't installed

Setting `QUEUE_CONNECTION=rabbitmq` without having run
`composer require kinetis/queue-rabbitmq` produces a clear error telling
you which package to install, rather than a confusing crash.

## See also

- {doc}`queue` — writing jobs, pushing and popping, and everything about
  retries that applies to every backend equally.
- {doc}`config` — the named-connection convention used above.
