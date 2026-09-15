# Queue

````{note}
Not part of core. Install it separately, with one backend package:

```{code-block} sh
composer require kinetis/queue kinetis/queue-redis
```
````

Push a job from application code, and a separate `kinetis queue:work`
process runs it later, possibly on another machine. `kinetis/queue`
holds the `Kinetis\Queue\QueueInterface` contract, the worker and the
CLI commands; each backend is its own package. {doc}`appendix-queue` has
the precise delivery, worker and backend contracts behind this guide.

## Choose a backend

| `QUEUE_CONNECTION` | Package | Choose it when | Setup |
|---|---|---|---|
| `redis` | `kinetis/queue-redis` | You run a standalone Redis server and want low-latency jobs | {doc}`queue-redis` |
| `sql` | `kinetis/queue-sql` | Jobs should live in the MySQL or Postgres database you already back up | {doc}`queue-sql` |
| `sqs` | `kinetis/queue-sqs` | You run on AWS, or another AWS account or service consumes the jobs | {doc}`queue-sqs` |
| `rabbitmq` | `kinetis/queue-rabbitmq` | You already route work through a RabbitMQ broker | {doc}`queue-rabbitmq` |

The Redis backend connects to one Redis node, not to a Redis Cluster —
see {doc}`queue-redis`.

```{code-block} text
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
```

`QUEUE_CONNECTION` has no default. Setting it is all the container
wiring there is: this package's bootstrap binds `QueueInterface` to the
selected backend, built on first use, so a controller or job
constructor-injects it with no `bootstrap.php` code. A `QueueInterface`
binding in your own `bootstrap.php` wins. Selecting a backend whose
package is not installed raises
`Kinetis\Queue\Exception\QueueUnavailableException`, naming the package.

`QUEUE_CONNECTION_NAME` selects a named connection of that backend,
`default` when unset — see {doc}`config`, which also lists every queue
key.

## Write a job

A job is a plain class implementing `Kinetis\Queue\Job`:

```{code-block} php
use Kinetis\Queue\Job;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SendWelcomeEmail implements Job
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}

    public function handle(MailerInterface $mailer): void
    {
        $mailer->send(
            new Email()
                ->from('noreply@example.com')
                ->to($this->email)
                ->subject('Welcome')
                ->text("Welcome, {$this->name}!"),
        );
    }
}
```

`MailerInterface` is bound by `kinetis/mailer` (`composer require
kinetis/mailer`) once `MAILER_DSN` is set — see {doc}`mailer`.
`handle()`'s parameters are resolved from the container when the job
runs, the way a controller method's are, and each must be class-typed.
The constructor holds only the data the job needs, because the job is
stored and rebuilt in a worker process.

### What a constructor argument can hold

Every constructor parameter needs a same-named property, and every value
must survive a JSON round trip:

- `null`, `bool`, `int`, a finite `float`, or a valid UTF-8 `string`;
- a list, or a map with string keys, of those values, nested up to 32
  levels;
- as a top-level argument only, a `BackedEnum` case or a
  `DateTimeImmutable`, when the parameter's type names exactly that enum
  class or `DateTimeImmutable`.

Pass identifiers rather than entities, services or open resources, and
load what the job needs inside `handle()`. Anything else raises
`Kinetis\Queue\Exception\UnserializableJobException` at `push()`,
naming the argument but never its value, rather than failing later in a
worker. The [argument contract](appendix-queue.md#job-arguments) has the
exact rules.

### Keeping sensitive arguments out of the log

When a job is given up on, the worker logs its arguments. Mark any
parameter holding a token, an address or customer data
`Kinetis\Queue\Attributes\Sensitive`, and it is logged as `[redacted]`:

```{code-block} php
use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class SendPasswordReset implements Job
{
    public function __construct(
        public int $userId,
        #[Sensitive]
        public string $email,
        #[Sensitive]
        public string $resetToken,
    ) {}

    public function handle(MailerInterface $mailer): void
    {
        $mailer->send(
            new Email()
                ->from('noreply@example.com')
                ->to($this->email)
                ->subject('Reset your password')
                ->text("Reset your password: https://example.com/reset?token={$this->resetToken}"),
        );
    }
}
```

The handler uses the same `MailerInterface` as above and puts the token
only into the email it sends, never into a log line or exception message
of its own.

```{code-block} json
"args": {"userId": 4812, "email": "[redacted]", "resetToken": "[redacted]"}
```

Leaving `userId` unmarked keeps the record actionable: you can find the
account and issue a new reset. A marked array or object is redacted
whole.

`#[Sensitive]` governs logging, not storage. The backend stores every
argument in full, because the worker needs it, so protect the backend
like the database. When a secret must not reach queue storage at all,
pass an identifier and load the secret inside `handle()`.

## Push a job

```{code-block} php
use Kinetis\Queue\QueueInterface;

final readonly class RegistrationController
{
    public function __construct(private QueueInterface $queue) {}

    #[Post('/register')]
    public function store(#[Body] RegisterRequest $data): array
    {
        // ...
        $this->queue->push(new SendWelcomeEmail($data->email, $data->name));

        return ['status' => 'registered'];
    }
}
```

`push()` targets the `default` queue with no delay unless told
otherwise, and accepts a per-job attempt cap:

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
$this->queue->push(new GenerateReport($reportId), queue: 'reports');
$this->queue->push(new SendWelcomeEmail($data->email, $data->name), maxAttempts: 3);
```

`push()` returns once the backend has stored the job. It suspends the
calling Fiber instead of blocking the worker while it waits, so it can
run beside other I/O through {doc}`concurrency`'s `concurrently()`.

### When `push()` throws

- `InvalidQueueArgumentException`, `UnserializableJobException`, or a
  backend's delay-limit `InvalidArgumentException` is raised before the
  backend is contacted. Nothing was queued; fix the call.
- Any other exception — a lost connection, an expired timeout, an
  unconfirmed RabbitMQ publish — can follow a write the backend already
  applied. Treat the job as possibly queued. Do not report that it was
  not sent, and push it again only when its handler tolerates running
  twice ([A job can run more than once](#a-job-can-run-more-than-once)).

[What `push()` guarantees](appendix-queue.md#what-push-guarantees)
defines each backend's confirmed write.

## Run a worker

```{code-block} sh
vendor/bin/kinetis queue:work
```

The worker pops one job, runs `handle()` in a fresh request scope,
settles the job with the backend, disposes the scope, and pops the next.
Services a job resolves through its scope are discarded with it; only
services registered on the application scope persist between jobs. A job
whose `handle()` takes a `TransactionGuard` (see {doc}`persistence`) and
leaves a transaction open has it rolled back when its scope is disposed,
before the next job runs.

Run as many `queue:work` processes as you need, on as many machines,
against the same backend. They coordinate nothing beyond the queue names
they watch. `QUEUE_POLL_TIMEOUT` (default `5`, a positive number of
seconds) is how long an idle worker waits for a job before it checks for
a stop signal again.

## Retries and failures

A job whose `handle()` throws is logged, and the worker moves on to the
next job. `maxAttempts` is the total number of attempts: once a job's
attempt count reaches it, that failure is final and the job is removed.
A job pushed without `maxAttempts` uses the worker's `QUEUE_MAX_ATTEMPTS`,
which defaults to `0` — **with no cap set anywhere, a job that fails once
is given up on.**

```{code-block} text
QUEUE_MAX_ATTEMPTS=5
```

Retries are immediate. A released job is available to the next `pop()`
straight away and there is no backoff, so a job failing against a
dependency that is down spends its attempts as fast as workers take it.
No setting retries a job forever. `maxAttempts` is checked only after
`handle()` throws, so it cannot stop a job whose process dies each time
it runs.

A failure with attempts remaining is logged without the job's arguments
and released, since the backend still holds its payload. A final failure
is logged with them:

```{code-block} json
{
    "level": "error",
    "message": "Job \"App\\SendWelcomeEmail\" failed permanently after 3 attempt(s): Connection refused",
    "context": {
        "job": {
            "class": "App\\SendWelcomeEmail",
            "queue": "default",
            "attempts": 3,
            "args": {"email": "a@b.com", "name": "Ana"}
        },
        "exception": "..."
    }
}
```

That log entry is the only persistent record of a job given up on; there
is no dead-letter queue. `QueueWorker` dispatches
`Kinetis\Queue\Events\JobFailedPermanently` at the same moment with the
same data, so a listener can alert someone or write your own dead-letter
row. `JobSucceeded` and `JobReleased` report the other outcomes — see
{doc}`events`. A throwing listener, telemetry backend or logger never
changes a job's outcome
([observers](appendix-queue.md#observers-never-decide-or-rewrite-the-outcome)).

A stored message that cannot be decoded — hand-edited, or written by
another producer — is deleted and logged as a warning instead of being
retried ([malformed messages](appendix-queue.md#malformed-stored-messages)).

### Delayed jobs

`delaySeconds` is a floor: the job cannot be popped before the delay
elapses, and can run later. Redis and SQL make a due job available on
the next `pop()` a worker makes, so a delayed job runs late while every
worker is busy. SQS caps a delay at 900 seconds and RabbitMQ at
4,194,303 seconds (about 48 days); `push()` rejects a longer delay
before anything is sent.

## Prioritize with named queues

A worker watches one or more queue names, in priority order:

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Priority is list order, not a per-job score: this worker takes every job
on `high` before it takes one from `default`. A queue name absent from
`--queue` is invisible to that worker — jobs pushed to `reports` wait
until some worker watches `reports`. Omitting `--queue` watches
`default` only.

A job pushed to `high` while the worker is running a `default` job waits
for that job to finish. A queue name is letters, digits, hyphens and
underscores, up to 80 characters, and is rejected before any backend
I/O otherwise. The [`pop()` contract](appendix-queue.md#the-pop-prioritytimeout-contract)
defines sweep order and timeouts on each backend.

## Deploys and restarts

`SIGTERM` and `SIGINT` stop the worker after the job in flight: it
settles that job, exits `0`, and leaves nothing reserved. Docker,
systemd and Kubernetes send `SIGTERM` before `SIGKILL`, so a rolling
deploy, `docker compose restart` or `systemctl restart` needs no extra
command. Decide three things:

- **The grace period.** A worker signalled while it waits for a job can
  still receive one and run it before stopping, so set the supervisor's
  grace period above your slowest job plus `QUEUE_POLL_TIMEOUT`. Docker's default is
  10 seconds (`--stop-timeout`, or `stop_grace_period` in Compose), and
  Kubernetes' `terminationGracePeriodSeconds` defaults to 30. A worker
  killed mid-job leaves its job reserved until the backend redelivers it
  (see [Visibility timeout](#visibility-timeout)), and the job runs
  again.
- **`ext-pcntl`.** Graceful shutdown needs it, and the official PHP
  Docker images do not load it: add `docker-php-ext-install pcntl` to
  your image. Without it `queue:work` prints a warning at startup, and a
  deploy interrupts whatever job is running.
- **Job changes across the deploy.** Jobs pushed by the old code run on
  the new code. Add constructor parameters as optional, and keep a
  removed parameter or job class until the jobs pushed with it have
  drained. Otherwise those jobs fail with
  `Kinetis\Queue\Exception\JobReconstructionException`, which counts as a
  failed attempt.

## A job can run more than once

Delivery is at least once: while workers are running and the backend
holds a job, the job is delivered until a worker settles it, and it can
be delivered more than once. That is not a promise the job eventually
runs — nothing is delivered while the backend is unavailable, and a job
the backend deletes, such as an SQS message outliving its retention
period, is gone.

Redis, SQL and RabbitMQ reserve a job atomically, so two workers never
receive the same delivery at the same instant. SQS hides a received
message on a best-effort basis and can hand it to a second worker while
the first is still running it. On every backend the same job also runs
again when:

- a worker dies after the job's work and before the backend records the
  result;
- a job outlives its visibility timeout and a second worker starts it;
- a RabbitMQ retry stops between publishing the replacement and
  discarding the original;
- you push again after a `push()` whose outcome was unknown.

Write every handler so that running it twice, or twice at once, has no
duplicate effect:

- Pass an identifier for the work — an order ID, or a key generated when
  you push — and record it under a unique constraint in the same database
  transaction as the job's effect. A later run finds it and skips the
  work; a concurrent run fails on the constraint instead of writing
  twice.
- Prefer writes that converge, such as setting a status or upserting a
  row, over writes that accumulate, such as incrementing a counter or
  appending a row.
- Send that same key to an external API that accepts an idempotency key,
  such as a payment provider.

### Visibility timeout

Each backend holds a popped job for a bounded time and hands it to
another worker if the first never settles it:

| Backend | A worker that dies mid-job | Controlled by |
|---|---|---|
| `kinetis/queue-redis` | Redelivered once its lease passes the timeout, by any worker's next `pop()` | `QUEUE_VISIBILITY_TIMEOUT_SECONDS`, default `300` |
| `kinetis/queue-sql` | Reclaimed once its reservation passes the timeout | `QUEUE_VISIBILITY_TIMEOUT_SECONDS`, default `300` |
| `kinetis/queue-sqs` | Redelivered when the message's visibility timeout expires | The queue's visibility timeout, configured in AWS |
| `kinetis/queue-rabbitmq` | Redelivered as soon as the worker's connection drops | Nothing to configure |

Set the timeout above your slowest job. It is not extended while a job
runs: too short, and a slow job runs alongside its redelivered copy; too
long, and a crashed worker's job waits that long to come back.

### When a settlement is lost

Redis and SQL tell a worker when the delivery it is settling is already
over — its timeout passed and another worker took the job. The worker
writes nothing, dispatches `Kinetis\Queue\Events\JobSettlementLost`
instead of the success or failure event, logs a warning, and continues.
Read it as "another worker may be running, or may already have run, this
job". An idempotent handler needs nothing more; frequent occurrences mean
the visibility timeout is shorter than the job. SQS and RabbitMQ settle
without that check, so a lost delivery there is not reported as one.

Any other exception from settling a job — a dropped connection, a
backend refusing writes — stops the worker, and its supervisor should
restart it. Whether that settlement was applied is unknown, so the job
may run again. The [failure vocabulary](appendix-queue.md#failure-vocabulary)
lists each exception and event and what it proves.

## Inspect and clear a queue

`queue:stats` reports how many jobs are waiting:

```{code-block} sh
vendor/bin/kinetis queue:stats --queue=high,default
```

```{code-block} text
QUEUE    WAITING
high     12
default  3
----------------
total    15
```

The count includes jobs still inside their delay and excludes jobs a
worker holds. SQS reports estimates, and RabbitMQ reads its queues one at
a time rather than at one instant; both answer whether a queue is
draining or backing up.

### Clearing is a separate capability

Clearing lives on `Kinetis\Queue\ClearableQueueInterface`, which extends
`QueueInterface` and which a backend declares only when it can discard
exactly the jobs waiting on a queue:

| Backend | Clearing |
|---|---|
| `kinetis/queue-redis` | Yes |
| `kinetis/queue-sql` | Yes |
| `kinetis/queue-rabbitmq` | Yes |
| `Kinetis\Queue\SyncQueue` | Yes — always `0`, since nothing is stored |
| `kinetis/queue-sqs` | No — see {doc}`queue-sqs` for emptying an SQS queue |

`queue:clear` discards waiting jobs, delayed ones included, and requires
`--force` because there is no copy to restore from:

```{code-block} sh
vendor/bin/kinetis queue:clear --queue=default --force
```

A job a worker holds is never removed. Against a backend without the
capability the command names that backend and exits 1, and a malformed or
repeated name anywhere in `--queue` leaves every listed queue untouched.
To clear a queue from application code, see
[Clearing from application code](appendix-queue.md#clearing-from-application-code).

## Run jobs inline in development: `SyncQueue`

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\SyncQueue;
use Kinetis\Runtime\AppEnvironment;

return static function (AppScope $app, Config $config): void {
    if (AppEnvironment::detect($config->get('APP_ENV')) === AppEnvironment::Development) {
        $app->instance(QueueInterface::class, new SyncQueue($app));
    }
};
```

`SyncQueue::push()` runs the job immediately, so no worker process is
needed while developing. It behaves like a real worker where it matters:

- The job goes through the same serialization round trip, and the
  rebuilt instance runs, so an argument that cannot be queued fails here
  at `push()`.
- Each `push()` gets its own fresh request scope, so a job that reaches
  request-scoped state by accident fails in development too.
- Arguments are validated as a durable backend validates them.

Unlike a worker, it lets a failing job's exception propagate to the
caller of `push()`, so you see the real error. It ignores `queue`,
`delaySeconds` and `maxAttempts`, and it is registered in bootstrap code
rather than selected through `QUEUE_CONNECTION`, since no worker has
anything to pop from it.

## See also

- {doc}`appendix-queue` — delivery, worker and backend contracts.
- {doc}`queue-redis`, {doc}`queue-sql`, {doc}`queue-sqs` and
  {doc}`queue-rabbitmq` — backend setup.
- {doc}`persistence` — connecting to MySQL and Postgres.
- {doc}`redis` — the Redis transport under `kinetis/queue-redis`.
- {doc}`migrations` — running the SQL backend's migration.
- {doc}`events` — lifecycle events, and listeners marked `ShouldQueue`.
  [Queued event listeners](appendix-queue.md#queued-event-listeners)
  covers how those run on a worker, and
  [Multiple backends](appendix-queue.md#multiple-backends) covers running
  different queues on different backends.
- {doc}`telemetry` — traces that follow a job from push to worker.
