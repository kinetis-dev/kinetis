# Queue (SQS)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-sqs
```
````

Adds Amazon SQS as a backend for {doc}`queue`. Application code that
already pushes and pops jobs through `QueueInterface` needs no changes
at all to switch — only your configuration changes.

```{code-block} text
QUEUE_CONNECTION=sqs
QUEUE_SQS_REGION=us-east-1
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Every SQS call this backend makes, including a worker waiting for the
next job, suspends rather than blocking the process. A worker given
several queue names does not watch them simultaneously: it sweeps them
in priority order, giving each an immediate non-blocking check first,
and only then long-polls one queue at a time for a bounded slice — at
most five seconds per queue, and never past the deadline it was given.
So a job on a lower-priority queue is never missed while a higher one is
quiet, and a job arriving on a higher-priority queue mid-slice is picked
up on the next sweep rather than instantly. {doc}`queue` has the full
`pop()` contract.

## Configuring

`QUEUE_SQS_REGION` is required — there's no sane default to guess.
Credentials come from the AWS SDK's usual sources
(`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`, or an IAM role) — nothing
Kinetis-specific to set up.

Two optional settings:

```{code-block} text
QUEUE_SQS_ENDPOINT=http://localhost:4566
QUEUE_SQS_QUEUE_PREFIX=myapp-
```

`QUEUE_SQS_ENDPOINT` points at a local SQS-compatible service (LocalStack,
for example) instead of real AWS — handy for development and testing.
`QUEUE_SQS_QUEUE_PREFIX` is prepended to every queue name — useful when
staging and production share one AWS account and need to stay on separate
queues without both trying to use a plain name like `default`.

## Create your queues ahead of time

A queue name you push to (`'default'`, `'high'`, and so on) must be a real
SQS queue of that same name (with `QUEUE_SQS_QUEUE_PREFIX` prepended, if
you set one) that already exists — **this package never creates a queue
for you.** Create each one yourself first, however you normally manage AWS
infrastructure (Terraform, CloudFormation, the AWS CLI, or the console),
before pushing or popping against it.

Only standard SQS queues are supported — FIFO queues (queue names ending
in `.fifo`) are not.

## Emptying a queue is an infrastructure operation

`SqsQueue` implements `QueueInterface` and not
`Kinetis\Queue\ClearableQueueInterface`, so it has no `clear()` and
`kinetis queue:clear` names the backend and stops. Nothing SQS offers
meets that contract:

- `PurgeQueue` deletes the messages a worker currently holds in flight
  along with the waiting ones, and keeps deleting messages sent during
  the up-to-60-second window it takes to finish — so it destroys both
  work that was reserved and work pushed after the call. It reports no
  count, and is rate-limited to once per 60 seconds per queue. This
  backend never calls it.
- `size()` could not stand in for that count either: it reports
  `ApproximateNumberOfMessages` plus the delayed count, which excludes
  in-flight work and is an estimate besides.
- Assembling the operation out of `ReceiveMessage`/`DeleteMessage` is
  not possible: a delayed message stays invisible until its delay
  elapses, so nothing can receive it in order to delete it.

Empty an SQS queue the same way you created it — `aws sqs purge-queue`,
or recreating it — accepting `PurgeQueue`'s real semantics explicitly
rather than through a method whose name promises narrower ones. See
{doc}`queue`'s "Clearing is a separate capability" for the cross-backend
picture.

## What a receipt handle can and cannot prove

`QueuedJob::$handle` is the message's `ReceiptHandle`, which SQS scopes
to the receive that produced it. A settlement against a handle whose
visibility window has passed — or whose message has since been
redelivered — is answered by SQS itself, and this backend raises no
`Kinetis\Queue\Exception\StaleJobHandleException` of its own: it has
no way to tell that answer apart from any other API error, so whatever
SQS returns propagates as itself. A worker therefore learns about a lost
delivery here only as a failure, if at all — see {doc}`queue`'s "When a
settlement is lost", and keep handlers idempotent, since SQS can
redeliver a message independently of anything this package does.

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

Works the same as on the other backends, with one difference: SQS won't
delay a message by more than 900 seconds (15 minutes). Ask for longer than
that and `push()` throws immediately, before anything is sent.

## Retries and giving up

Everything {doc}`queue` documents about `maxAttempts`, `QUEUE_MAX_ATTEMPTS`,
and the log entry written when a job is finally given up on works
identically here — nothing about retry behavior changes by switching to
this backend.

Instrumentation propagation metadata (see {doc}`telemetry`) travels as
one JSON-encoded `metadata` message attribute — stored at `push()`,
read back at `pop()` — so a worker's consumer span joins the
producer's trace. `release()` is a visibility change, so the message
and its metadata survive it unchanged.

## Named connections

```{code-block} text
QUEUE_CONNECTION_NAME=reports
QUEUE_REPORTS_SQS_REGION=eu-west-1
QUEUE_REPORTS_SQS_QUEUE_PREFIX=myapp-reports-
```

Same convention as everywhere else in Kinetis (see {doc}`config`):
`QUEUE_CONNECTION_NAME` picks which named block of `QUEUE_SQS_*` settings
a worker reads, and `'default'` (or simply not setting it) reads the plain
keys shown earlier in this page.

## If the package isn't installed

Setting `QUEUE_CONNECTION=sqs` without having run
`composer require kinetis/queue-sqs` produces a clear error telling you
which package to install, rather than a confusing crash.

## See also

- {doc}`queue` — writing jobs, pushing and popping, and everything about
  retries that applies to every backend equally.
- {doc}`config` — the named-connection convention used above.
