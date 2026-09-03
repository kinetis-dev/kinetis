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

### What a constructor argument can hold

`push()` runs every constructor argument through `Kinetis\Queue\JobSerializer`,
which enforces one portable "wire value" contract regardless of backend —
the same contract JSON itself can represent, since every durable backend
stores the payload as JSON:

- `null`, `bool`, `int`, a finite `float` (not `NAN`/`INF`), and a
  valid-UTF-8 `string`.
- A dense, zero-based `list` or a string-keyed map, either nested to any
  depth — a sparse or mixed-key array is rejected, since it has no
  lossless JSON representation.
- A `BackedEnum` case and a `DateTimeImmutable` instance (the exact
  class, not a subclass) — both round-trip to an equal value, not the
  same object.

Anything else — a resource, a `Closure`, an arbitrary object, invalid
UTF-8 or raw binary data — is rejected at `push()` time with
`Kinetis\Queue\Exception\UnserializableJobException`, naming the
constructor argument and, for a nested value, its exact location (e.g.
`items[3].name`) — never the value itself, since it may be sensitive.
This is deliberately a `push()`-time failure, not something discovered
later as a worker-side crash or a silently different value once actually
deployed: `SyncQueue` (below) enforces the identical contract, so a job
that can't survive the round trip fails the same way in local development
too.

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

That fresh scope gets the same transaction-safety net an HTTP request or
CLI command does: if a job constructor-injects `TransactionGuard` (see
{doc}`persistence`), begins a transaction, and returns or throws without
closing it, the scope's own dispose hook rolls it back before the next
job runs — so a leftover open transaction never leaks into whatever this
same pooled/native connection serves next. `SyncQueue`'s inline `push()`
gets the identical guarantee.

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

### The `pop()` priority/timeout contract

Every backend implements `pop($timeoutSeconds, $queues)` identically:

- `$timeoutSeconds: 0` blocks with no deadline at all, until something's
  available. A positive value blocks for up to that many seconds before
  returning `null`. A negative value is rejected outright rather than
  silently treated as either.
- Every named queue gets an immediate, non-blocking check, in priority
  order, before a backend is ever allowed to block waiting on one — a job
  already waiting anywhere is always found before that, regardless of
  which position it's in. Only once nothing is found anywhere does a
  backend with a native blocking primitive (Redis, SQS) wait a short,
  bounded slice of real time per queue, capped by both a small per-queue
  limit and whatever's left of the overall deadline, before sweeping
  again — a real deadline is never overshot by more than that one bounded
  slice. A backend with none (RabbitMQ) paces retries the same way, via a
  bounded pause between sweeps instead. `SqlQueue` gets this property for
  free from a different shape entirely: its own single, priority-ordered
  SQL query already checks every queue in one atomic operation, which
  never had a per-queue loop to begin with.
- A queue name must match `/^[A-Za-z0-9_-]{1,80}$/` — letters, digits,
  hyphens, and underscores only, up to 80 characters (the same rule
  Amazon SQS enforces on a standard queue's own name, adopted here as the
  conservative grammar every backend can portably support), and the same
  name may not appear twice in one `$queues` list — both are rejected
  before any backend I/O, via `Kinetis\Queue\Exception\InvalidQueueNameException`.
  This check runs everywhere a queue name is ever accepted, not just
  `pop()`: `push()`, `size()`, `clear()`, and `QueuedJob`'s own
  constructor (the one point `ack()`/`release()`/`fail()` ultimately
  route through) all validate the same way, so a malformed or forged
  name is rejected the same way regardless of which method receives it
  first. An empty `$queues` list is the one deliberate exception: it
  returns `null` immediately, since "nothing to check" is a legitimate
  case, not malformed input. A negative `$timeoutSeconds` is rejected the
  same way, via `Kinetis\Queue\Exception\InvalidPopTimeoutException`.

```{note}
Once a backend's own probe finds a job, it's returned immediately, with
no attempt to re-check higher-priority queues first. Every backend
reserves a job atomically the instant its own probe succeeds (Redis's
move to a processing list, SQS's receive-triggered invisibility,
RabbitMQ's `basic.get`, `SqlQueue`'s own row-level lock) — there is no
"peek without reserving" primitive to recheck from on any of them, so a
job arriving on a higher-priority queue while a lower one's own probe was
still blocked is picked up on the very next full sweep instead, not
necessarily immediately.
```

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

`QUEUE_POLL_TIMEOUT` (default `5`) has to be a finite, positive number of
seconds for the same reason: `pcntl_async_signals()` sets a flag when
`SIGTERM`/`SIGINT` arrives, but nothing interrupts an in-flight call —
the run loop only ever gets a chance to check that flag once its current
poll returns. `QueueInterface::pop()` itself treats `0` as "block with no
deadline at all, until something's available," which is a genuinely
useful one-shot wait elsewhere, but handed to the worker's own loop it
means a poll on an idle queue never returns at all: `queue:work` rejects
`0` (and any negative value) at startup, before printing anything or
touching the queue backend, rather than accepting a configuration that
would leave the worker unkillable except by `SIGKILL`.

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

`push()` also runs the job through the exact same `JobSerializer::serialize()`
then `deserializeJob()` round trip a durable backend's `push()`/worker pair
does, and invokes the *reconstructed* instance, never the object the
caller passed in. This is what makes "runs immediately, useful for local
development" mean the same thing as "runs on a real worker later": a job
whose constructor holds something that can't survive that round trip —
see "What a constructor argument can hold" above — fails here too,
at `push()` time, instead of silently working locally and only failing
once actually deployed against a durable backend.

Not selectable via `QUEUE_CONNECTION` — there's nothing for a worker
process to do against a backend that never stores anything, so
`SyncQueue` is constructed directly in application bootstrap code
instead. It accepts a `queue` argument on `push()` for
signature compatibility with the other backends, but ignores it — there's
only ever one "queue" (immediate execution), so a queue name has nothing
to select between.

### The `push()` argument contract

Every backend validates `push($job, $delaySeconds, $queue, $maxAttempts)`
identically, via `Kinetis\Queue\QueueContract::assertValidPushArguments()`
— before telemetry, before serializing `$job`, before creating a request
scope, before any backend I/O:

- `$delaySeconds: 0` pushes immediately; a positive value delays by that
  many seconds. A negative value is rejected outright, via
  `Kinetis\Queue\Exception\InvalidDelaySecondsException`, rather than
  reaching any backend at all.
- `$queue` is validated the same way `pop()`'s own queue names are — see
  above.
- `$maxAttempts: null` defers to the processing worker's own default; `0`
  or a positive value is the effective cap itself. A negative value is
  rejected, via `Kinetis\Queue\Exception\InvalidMaxAttemptsException`,
  rather than silently reaching `QueueWorker`, where a job's very first
  real attempt would otherwise be misclassified as already exhausted.

`SyncQueue` validates the identical way even though `$delaySeconds`/
`$maxAttempts` have no effect there — a caller's mistake must not
silently behave differently in local development than it would against a
durable backend. SQS layers its own additional, narrower constraint on
top of the shared floor check: a real 900-second upper bound, matching
`SendMessage`'s own hard limit — see {doc}`queue-sqs`.

## A malformed message never crashes the worker

`push()`'s own argument validation (above) and `QueuedJob`'s own decode-
time counter checks close off most ways a *pushed* value can be
malformed — but a durable backend's own stored data can still be
corrupted after the fact: a hand-edited Redis payload, a database row
populated some other way, an AMQP header set by a non-Kinetis publisher.
Every durable backend reserves a message from its own storage — moved to
Redis's processing list, given a SQL `reserved_at`, made invisible by
SQS, held as an unacked AMQP delivery — *before* it can be decoded into a
`QueuedJob`, so a decode failure at that point (invalid JSON, a missing
or wrong-shaped `class`/`args`/`metadata` field, an out-of-range counter)
would otherwise leave a real reservation with nothing to release it: the
message strands forever on a backend with no reservation-reclaim
mechanism (Redis), or replays forever on one that has (SQL, SQS,
RabbitMQ), since the identical malformed data crashes every retry the
same way.

Rather than let that exception escape `pop()` and crash the worker loop,
every backend settles the malformed message permanently — using its own
existing removal primitive (an exact-payload `LREM` off Redis's
processing list, a SQL row `DELETE`, SQS's `DeleteMessage`, RabbitMQ's
`nack(requeue: false)`) — before `pop()` throws
`Kinetis\Queue\Exception\MalformedJobSettledException` instead of letting
the original decode failure escape. `QueueWorker` catches this
specifically, logs it, and moves straight on to the next job — no
`RequestScope` is created and no job telemetry/lifecycle events fire,
since there was never a real job to run any of that for.

An *ordinary* transport or infrastructure failure — a dropped connection,
a backend genuinely unreachable — is a different exception type entirely
and is never caught by this containment; it propagates and stops the
worker exactly as it always has. The distinction matters: only a failure
while turning already-reserved data into a `QueuedJob` is ever treated as
"this specific message is malformed."

Settling means permanently deleting the message, so only a genuine data-
validation failure ever triggers it — `Kinetis\Queue\Exception\MalformedQueuedJobDataException`
specifically, not any exception a decode step happens to throw. An
unexpected failure that isn't that type (a bug in a decoder, for
instance) is never treated as malformed data and is never settled: it
propagates and crashes the worker the same way an infrastructure failure
does, leaving the reserved message exactly where the backend's own
native recovery mechanism (a visibility timeout, a connection-drop
requeue) can still reach it once the underlying bug is fixed, rather
than destroying a message that may have been perfectly valid all along.

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

### Observers never decide or rewrite the outcome

Only `handle()` itself decides whether a job succeeded, gets retried, or
is given up on — nothing that merely *describes* or *observes* that
decision gets a vote in it, regardless of whether it runs before or
after the transition. Starting telemetry and, on a failure, the log
line above are both contained so they can never block
`ack()`/`release()`/`fail()` from actually running; if either throws,
it's reported through your logger and the transition still happens
exactly as decided. The redaction behind that log line is contained the
same way but through its own dedicated fallback rather than the logger —
a reflection failure there falls back to every argument redacted, with
no separate report of its own, since the real job failure is already
what the log line exists to carry. Completion telemetry and the
`JobSucceeded`/`JobReleased`/`JobFailedPermanently` dispatch run strictly
*after* that transition — these are the ones that could otherwise be
mistaken for a second, contradictory decision, and they're held to the
identical reporting rule: a listener or telemetry backend that throws is
reported through your logger and never allowed to trigger another
transition or stop the worker. Every logger report here — before or
after the transition — goes through the same best-effort mechanism, so
even a broken logger can't affect
anything here — and `processNext()` moves on to the next job regardless.

### A disposal failure never rewrites the outcome or stops the worker

Each job runs in its own `RequestScope`, disposed after the transition
above (and its observers) have usually already run — see {doc}`container`'s
own general explanation of why a `finally`-based dispose is unsafe here.
"Usually," not "always": the same `finally` block is also reached if
`ack()`/`release()`/`fail()` itself throws — a broken backend connection,
for one — before the transition actually completes, and disposal is
attempted the same way in that case too, without ever pretending a
transition happened that didn't. Either way, a disposal failure is never
allowed to trigger a second transition (nothing in the disposal path ever
touches the queue backend) or escape `processNext()`/stop `run()`'s
loop — the next job still runs regardless — and it's logged through
`AppScope`'s own logger (the job's own scope is already disposed by then,
so it can't safely resolve one) with the failing job's own class, queue,
and attempt count, so the log line identifies which job it belongs to.
What a disposal failure never does is replace whichever failure was
already the real, in-flight outcome for that job — if `ack()`/`release()`/
`fail()` itself threw, that exception is what escapes `processNext()`,
same as it always would have; a disposal failure on top of it is
reported separately, never instead.

`SyncQueue::push()` has no worker loop to protect, so its rule is about
which exception the caller actually sees: if the job's own `handle()`
throws and disposal *also* fails afterward, `push()` rethrows the job's
exact exception — never the disposal failure, which is logged separately
instead. If only disposal fails (the job itself succeeded), that failure
genuinely is the only thing that went wrong, so it propagates normally to
the caller.

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

The event's own constructor arguments go through the identical
`JobSerializer` wire-value contract a job's own arguments do — see "What
a constructor argument can hold" above — since a deferred listener's
event has to survive the exact same round trip to a worker process a job
does.

`QueuedListenerInvoker` never constructs the listener itself — it
receives only its class-string and pushes an `InvokeListenerJob`
carrying that name, the method, and the event's own serialized data.
The listener's own constructor, and anything it depends on, runs
exactly once — on the worker that later pops and executes the job, not
in the process that dispatched the event.

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
