# Queue (SQS)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-sqs
```
````

Adds Amazon SQS as a third backend for {doc}`queue`, alongside Redis and
SQL. Application code that already pushes and pops jobs through
`QueueInterface` needs no changes at all to switch — only your
configuration changes.

```{code-block} text
QUEUE_CONNECTION=sqs
QUEUE_SQS_REGION=us-east-1
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Every SQS call this backend makes, including a worker waiting for the
next job, runs without blocking the rest of your application — a worker
watching several queues stays responsive to all of them at once rather
than getting stuck waiting on one.

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
