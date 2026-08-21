# Queue

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue
```
````

A backend-agnostic background job queue: push a job from application code,
a separate `kinetis queue:work` worker process pops and runs it. This
package carries only the `Kinetis\Queue\QueueInterface` contract, the
worker, and the CLI commands — application code never sees which backend
is running underneath, and every backend lives in its own separate
package: Redis ({doc}`queue-redis`), SQL/MySQL/Postgres ({doc}`queue-sql`),
Amazon SQS ({doc}`queue-sqs`), and RabbitMQ ({doc}`queue-rabbitmq`).

`push()` and `pop()` never block the worker while waiting on the backend,
so a push can run alongside other work through {doc}`concurrency`'s
`concurrently()` instead of stalling the request until it completes.

## Writing a job

A job is a plain class implementing `Kinetis\Queue\Job`, constructed the
same way as any other DTO in Kinetis:

```{code-block} php
use Kinetis\Queue\Job;

final readonly class SendWelcomeEmail implements Job
{
    public function __construct(
        public string $email,
        public string $name,
    ) {}

    public function handle(Mailer $mailer): void
    {
        $mailer->send($this->email, "Welcome, {$this->name}!");
    }
}
```

`handle()`'s own parameters are resolved through the container at run
time, the same way a controller method's parameters are — not the
constructor. A job's constructor only ever holds the data it needs to
survive being written to the queue and read back later by a worker
process, which may not be the same process (or even the same machine)
that pushed it.

## Pushing and processing

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

```{code-block} sh
vendor/bin/kinetis queue:work
```

The worker runs one job at a time: pop, resolve `handle()`'s dependencies
through a fresh container scope, invoke it, then pop the next one. It
keeps going until a shutdown signal arrives — see "Stopping a worker"
below.

## Named and prioritized queues

Every `push()` targets a named queue — `default` when none is given:

```{code-block} php
$this->queue->push(new SendWelcomeEmail($data->email, $data->name));
$this->queue->push(new GenerateReport($reportId), queue: 'reports');
$this->queue->push(new SendPasswordReset($data->email), queue: 'high');
```

A worker watches one or more queue names, in priority order:

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

Priority is expressed by list order, not a numeric per-job score: the
worker above drains everything on `high` before ever checking `default`.
A queue name absent from `--queue` is invisible to that worker entirely —
jobs pushed to `reports` sit untouched until some worker actually watches
`reports`. Omitting `--queue` watches `default` only.

## Choosing a backend

`QUEUE_CONNECTION` has no default — set it explicitly, and install the
matching package:

| `QUEUE_CONNECTION` | Package | Configuration |
|---|---|---|
| `redis` | `kinetis/queue-redis` | {doc}`queue-redis` |
| `sql` | `kinetis/queue-sql` | {doc}`queue-sql` |
| `sqs` | `kinetis/queue-sqs` | {doc}`queue-sqs` |
| `rabbitmq` | `kinetis/queue-rabbitmq` | {doc}`queue-rabbitmq` |

```{code-block} text
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
```

Picking a `QUEUE_CONNECTION` value without its package installed fails
clearly, naming the package to install — see
`Kinetis\Queue\Exception\QueueUnavailableException`.

Setting `QUEUE_CONNECTION` is also all the container wiring there is:
this package's bootstrap class (declared via `extra.kinetis`, see
{doc}`cli`) binds `QueueInterface` to the selected backend before
`AppScope::boot()` locks bindings, so the controller above
constructor-injects it with no `bootstrap.php` code. An application
registering its own `QueueInterface` binding in `bootstrap.php` wins
over the package's — see "Multiple backends" below for when you'd want
to.

`QUEUE_CONNECTION_NAME` picks which named connection of that backend this
worker uses (see {doc}`config`'s named-connection convention) — 'default'
when unset:

```{code-block} text
QUEUE_CONNECTION=redis
QUEUE_CONNECTION_NAME=cache2
REDIS_CACHE2_HOST=127.0.0.1
```

### How `release()` behaves across backends

Every backend delivers a job at least once and every backend's `push()`/
`ack()`/`fail()` are each a single atomic operation. `release()` — what
runs when a job fails and gets a retry — is where the backends actually
differ, because it means one entry leaves the in-flight set at the same
moment a replacement enters the retry set, and not every backend has a
primitive that can do both as one step:

| Backend | `release()` mechanism | Duplication window |
|---|---|---|
| `kinetis/queue-redis` | One Lua script, gated on the source entry actually being found and removed | None — a crash anywhere during release() either leaves the job exactly where it was or completes the swap; a stale/duplicate release() call is rejected rather than enqueuing a second copy |
| `kinetis/queue-sql` | One `UPDATE` statement (clears the reservation, increments the attempt count) | None |
| `kinetis/queue-sqs` | One `ChangeMessageVisibility` call | None from `release()` itself — but SQS's own at-least-once delivery model can redeliver independently of anything this package does |
| `kinetis/queue-rabbitmq` | Two separate AMQP operations — publish the replacement, then nack the original — since AMQP 0-9-1 has no cross-message transaction to make them one | Real: a crash between the two publishes a replacement *and* leaves the original to be redelivered once the connection drops, so the job can be delivered twice — see {doc}`queue-rabbitmq` |

A job handler should be idempotent under retry regardless of backend —
every backend can redeliver a job that was already fully processed if a
worker crashes after finishing the work but before calling `ack()` — but
`kinetis/queue-rabbitmq` is the one backend where `release()` itself,
not just a crash at an unrelated point in the cycle, can produce a
duplicate.

## Scaling out: multiple workers

`kinetis queue:work` is safe to run as any number of separate,
concurrent processes against the same backend — a plain horizontal-scaling
lever, not something that needs coordinating by hand. Every backend
guarantees a job is handed to exactly one worker, so two workers running
at once never both pick up the same job. Workers don't need to agree on
anything beyond which queue names they watch — start as many as you want,
on as many machines as you want, pointed at the same backend.

## Stopping a worker: deploys and restarts

`SIGTERM` and `SIGINT` stop the loop after the job in flight finishes.
The worker acks (or releases) that job, returns, and exits 0 — nothing is
left half-done, and nothing stays reserved for the backend to reclaim
later.

That is exactly the signal Docker, systemd, and Kubernetes already send
before they resort to `SIGKILL`, so a rolling deploy, `docker compose
restart`, or `systemctl restart` is graceful with no extra command and no
coordination between workers. Give the supervisor a grace period at least
as long as your slowest job: Docker's default is 10 seconds
(`--stop-timeout`, or `stop_grace_period` in Compose), Kubernetes'
`terminationGracePeriodSeconds` is 30.

```{warning}
Graceful shutdown needs `ext-pcntl`, which is a CLI-only extension and is
**not** loaded in the official PHP Docker images — add
`docker-php-ext-install pcntl` to your image. Without it there is no way
to observe `SIGTERM` at all, and a deploy interrupts whatever job is
running. `queue:work` prints a warning at startup when it is missing,
rather than leaving you to discover it during a deploy.
```

## Inspecting and clearing a queue

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

The count covers jobs waiting to be popped, including ones still inside
their `push()` delay. Jobs a worker currently holds are excluded — those
are being worked, not waiting. Amazon SQS reports these numbers as
estimates rather than exact figures (see {doc}`queue-sqs`), which is fine
for the question this answers: whether a queue is draining or backing up.

`queue:clear` discards waiting jobs, and requires `--force` because there
is no dead-letter copy to restore from:

```{code-block} sh
vendor/bin/kinetis queue:clear --queue=default --force
```

Jobs a worker has already reserved are untouched — they belong to that
worker until it finishes with them.

## Multiple backends

Different queues can live on different backends — a `RedisQueue` (from
{doc}`queue-redis`) for low-latency jobs, a `SqlQueue` (from
{doc}`queue-sql`) for jobs that should ride along with an existing
database's backups and transactions, an `SqsQueue` (from
{doc}`queue-sqs`) for jobs a separate AWS account or service needs to
consume, or a `RabbitMqQueue` (from {doc}`queue-rabbitmq`) for jobs routed
through an existing broker. Register each concrete class directly rather
than binding `QueueInterface` to just one of them:

```{code-block} php
use Kinetis\QueueRedis\RedisQueue;
use Kinetis\QueueSql\SqlQueue;

$app->instance(RedisQueue::class, new RedisQueue($redisClient));
$app->instance(SqlQueue::class, new SqlQueue($db));
```

Application code that pushes to a specific backend constructor-injects
that concrete class instead of the shared interface:

```{code-block} php
final readonly class RegistrationController
{
    public function __construct(private RedisQueue $fastQueue) {}
}
```

Each backend gets its own `kinetis queue:work` process, run with the
`QUEUE_CONNECTION`/backend-specific environment variables and `--queue`
flag matching the queue names pushed to it — one process per
backend-and-queue-set combination, same as scaling a single backend out.

## Running synchronously in development: `SyncQueue`

```{code-block} php
use Kinetis\Queue\SyncQueue;

if ($appEnv->isDevelopment()) {
    $app->instance(QueueInterface::class, new SyncQueue($app));
}
```

`push()` runs the job's `handle()` immediately, inline, rather than
storing it anywhere — no separate `kinetis queue:work` process needed
while developing locally. Every `push()` call still gets its own fresh
scope, exactly like a real worker gives each job — job code that happens
to depend on request-scoped state reachable only by accident would fail
the same way here as it would against a real backend, rather than
silently working in development and breaking once actually queued. Unlike
a real worker, a failing job's exception isn't caught — it propagates
straight to whatever called `push()`, so the point of running jobs
synchronously (seeing the real error immediately) still holds.

Not selectable via `QUEUE_CONNECTION` — there's nothing for a worker
process to do against a backend that never stores anything, so
`SyncQueue` is constructed directly in application bootstrap code
instead. It accepts a `queue` argument on `push()` for
signature compatibility with the other backends, but ignores it — there's
only ever one "queue" (immediate execution), so a queue name has nothing
to select between.

## Delayed jobs

```{code-block} php
$this->queue->push(new SendReminderEmail($userId), delaySeconds: 3600);
```

A delayed job isn't visible to `pop()` until its delay has elapsed. The
Redis and SQL backends check this on their own polling cycle rather than
firing at the exact moment the delay ends, so a delayed job can run
slightly later than its exact target time — typically by a few seconds,
not less. SQS's own delay is native (`SendMessage`'s own `DelaySeconds`,
no polling-based promotion at all) but capped at 900 seconds (15 minutes)
— see {doc}`queue-sqs`. RabbitMQ's delay is also broker-driven, with no
such cap — see {doc}`queue-rabbitmq`.

## A failing job

A job whose `handle()` throws is logged through whatever
`Psr\Log\LoggerInterface` is registered — it doesn't stop the worker,
which moves on to the next job either way. What happens to the job itself
depends on `maxAttempts`:

```{code-block} php
$this->queue->push(new SendWelcomeEmail($data->email, $data->name), maxAttempts: 3);
```

`maxAttempts` is set per job at `push()` time, and takes priority whenever
it's set. Once a job's attempt count reaches it, that attempt's failure is
final: the job is removed instead of being retried again.

A job pushed without `maxAttempts` falls back to `kinetis queue:work`'s
own default instead, `QUEUE_MAX_ATTEMPTS` in `.env` — **0 when unset,
meaning no retries at all: a job that fails once is given up on
immediately.**

```{code-block} text
QUEUE_MAX_ATTEMPTS=5
```

Raise it to allow retries. There is no configuration, anywhere, that
makes a job retry forever — `QueueWorker`'s own default is likewise `0`,
not unlimited, so a job with no `maxAttempts` of its own is only ever
retried if something has explicitly set a cap above `1`.

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

That log entry — job class, constructor arguments, and the exception — is
the only *persistent* record kept of a job that's given up on; there's no
dead-letter table or queue to inspect afterward. `QueueWorker` also
dispatches `Kinetis\Queue\Events\JobFailedPermanently` (the same data the
log entry carries) at the same moment, so a listener can react live —
alerting someone, writing your own dead-letter row, anything the log
alone can't do for you. See {doc}`events` for the full catalog, including
the matching `JobSucceeded`/`JobReleased` events for the other two
outcomes.

A job that still has attempts remaining logs the same way but without
`args`, and without "permanently" in the message, and is released rather
than removed (dispatching `Events\JobReleased` instead). Its payload is
still held by the backend at that point, so copying the arguments into
the log would add nothing you couldn't already recover.

### Keeping sensitive arguments out of the log

A job routinely carries a token, an email address, or customer data that
has no business in a log aggregator. Mark those constructor parameters
`Kinetis\Queue\Attributes\Sensitive` and they are written as
`[redacted]` in the entry above, while everything else is logged as-is:

```{code-block} php
use Kinetis\Queue\Attributes\Sensitive;
use Kinetis\Queue\Job;

final readonly class SendPasswordReset implements Job
{
    public function __construct(
        public int $userId,
        #[Sensitive]
        public string $email,
        #[Sensitive]
        public string $resetToken,
    ) {}

    public function handle(Mailer $mailer, UrlSigner $signer): void
    {
        $mailer->send($this->email, $signer->resetLink($this->resetToken));
    }
}
```

```{code-block} json
"args": {"userId": 4812, "email": "[redacted]", "resetToken": "[redacted]"}
```

Leaving `userId` unmarked is the point: the record stays actionable — you
can find the account and trigger a new reset — without the address or the
token reaching the log. Marking a parameter holding an array or an object
redacts that value whole; there is no per-element redaction within one.

This governs what is logged, not what is stored. The real values still
travel to the queue backend, because the worker needs them to run the job
— so a backend holding sensitive payloads wants the same access control
as the database.

## Deferring an event listener onto the queue

`Kinetis\Queue\QueuedListenerInvoker` implements core's
`Kinetis\Events\ListenerInvokerInterface` — a listener marked
`Kinetis\Events\ShouldQueue` runs as a real queued job instead of inline:

```{code-block} php
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Queue\QueuedListenerInvoker;

$app->instance(ListenerInvokerInterface::class, new QueuedListenerInvoker($queue));
```

See {doc}`events` for writing the listener itself.

## See also

- {doc}`queue-redis` — the Redis backend, in its own package.
- {doc}`queue-sql` — the MySQL/Postgres backend, in its own package.
- {doc}`queue-sqs` — the Amazon SQS backend, in its own package.
- {doc}`queue-rabbitmq` — the RabbitMQ backend, in its own package.
- {doc}`persistence` — connecting to MySQL, Postgres, and Redis directly.
- {doc}`migrations` — running the SQL backend's own required migration.
- {doc}`events` — writing an event listener that can be deferred onto the
  queue with `ShouldQueue`.
