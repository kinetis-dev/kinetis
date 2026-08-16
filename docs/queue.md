# Queue

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue
```
````

A backend-agnostic background job queue: push a job from application code,
a separate `kinetis queue:work` worker process pops and runs it. `Redis` and
`SQL` (MySQL/Postgres) backends are both included, behind one
`Kinetis\Queue\QueueInterface` contract — application code never sees which
one is running underneath. Amazon SQS ({doc}`queue-sqs`) and RabbitMQ
({doc}`queue-rabbitmq`) are two more, equally `QueueInterface`-conforming
backends, each in its own separate package.

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

`QUEUE_CONNECTION` has no default — set it explicitly to `redis`, `sql`,
`sqs`, or `rabbitmq`:

```{code-block} text
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1

# — or —

QUEUE_CONNECTION=sql
DB_CONNECTION=mysql   # or "pgsql"
DB_HOST=127.0.0.1
DB_NAME=app
DB_USER=app
DB_PASSWORD=secret

# — or —

QUEUE_CONNECTION=sqs
QUEUE_SQS_REGION=us-east-1

# — or —

QUEUE_CONNECTION=rabbitmq
QUEUE_RABBITMQ_URL=amqp://guest:guest@localhost:5672/
```

The Redis variables are the same ones {doc}`persistence`'s
`RedisSimpleCache` reads; the SQL variables are the same ones
{doc}`migrations` reads. `sqs` needs the separate `kinetis/queue-sqs`
installed — see {doc}`queue-sqs` for its own configuration. `rabbitmq`
needs `kinetis/queue-rabbitmq` — see {doc}`queue-rabbitmq`.

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

Different queues can live on different backends — a `RedisQueue` for
low-latency jobs, a `SqlQueue` for jobs that should ride along with an
existing database's backups and transactions, an `SqsQueue` (from
{doc}`queue-sqs`) for jobs a separate AWS account or service needs to
consume, or a `RabbitMqQueue` (from {doc}`queue-rabbitmq`) for jobs routed
through an existing broker. Register each concrete class directly rather
than binding `QueueInterface` to just one of them:

```{code-block} php
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

## The SQL backend needs a table

`kinetis/queue` ships two ready-to-copy {doc}`migrations` files — one per
dialect, since the auto-incrementing primary key syntax itself isn't
portable between MySQL and Postgres:

```{code-block} text
vendor/kinetis/queue/resources/migrations/create_kinetis_queue_jobs_table.mysql.php.stub
vendor/kinetis/queue/resources/migrations/create_kinetis_queue_jobs_table.pgsql.php.stub
```

Copy whichever matches your database into your own `migrations/`
directory with a timestamp prefix, then run `vendor/bin/kinetis migrate`.

`SqlQueue`'s `pop()` relies on `SELECT ... FOR UPDATE SKIP LOCKED` to
guarantee two workers never receive the same job — that's this backend's
actual version floor: **MySQL 8.0+ or MariaDB 10.6+**. An older server
doesn't support that clause at all, so `pop()` would fail outright rather
than degrade quietly.

## A crashed worker's job: `SqlQueue`'s visibility timeout

By default, a job that's been popped but whose worker crashes before
`ack()`/`release()` runs stays reserved **forever** — no other worker can
ever pick it up again, since nothing ever clears its `reserved_at`.

`SqlQueue`'s second constructor argument, `$visibilityTimeoutSeconds`,
closes it — the standard "visibility timeout" pattern SQS's own
`VisibilityTimeout` already uses:

```{code-block} php
use Kinetis\Queue\SqlQueue;

$queue = new SqlQueue($db, visibilityTimeoutSeconds: 300);
```

A row reserved longer than this becomes poppable again by any worker —
`attempts` is incremented at that point (crediting the crashed attempt,
the same as an explicit `release()` call would), so `maxAttempts` still
eventually gives up on a job whose worker keeps crashing rather than
retrying it forever. `null` (the default) preserves the original
forever-stranded behavior exactly, unchanged.

`kinetis queue:work` reads this from the optional
`QUEUE_VISIBILITY_TIMEOUT_SECONDS` environment variable (via
`Config::scopedKey()`, so it respects `QUEUE_CONNECTION_NAME` the same as
every other queue setting) — absent means `null`, the same as constructing
`SqlQueue` directly with no second argument:

```{code-block} text
QUEUE_CONNECTION=sql
QUEUE_VISIBILITY_TIMEOUT_SECONDS=300
```

Pick a value comfortably longer than your slowest real job takes to run —
too short reclaims a job that's still being legitimately processed,
producing exactly the duplicate-processing risk a visibility timeout is
meant to bound, not eliminate outright.

`RedisQueue` has no equivalent parameter — its own reliable-queue design
(a separate `processing` list, distinct from `pending`) already avoids
losing a job outright on a crash, but has no reaper for a job stuck in
`processing` either; that remains its own disclosed, still-open gap.

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
        "job": {"class": "App\\SendWelcomeEmail", "args": {"email": "a@b.com", "name": "Ana"}},
        "exception": "..."
    }
}
```

That log entry — job class, constructor arguments, and the exception — is
the only record kept of a job that's given up on; there's no dead-letter
table or queue to inspect afterward. A job that still has attempts
remaining logs the same way, without "permanently" in the message, and is
released rather than removed.

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

- {doc}`queue-sqs` — the Amazon SQS backend, in its own package.
- {doc}`queue-rabbitmq` — the RabbitMQ backend, in its own package.
- {doc}`persistence` — connecting to MySQL, Postgres, and Redis directly.
- {doc}`migrations` — running the SQL backend's own required migration.
- {doc}`events` — writing an event listener that can be deferred onto the
  queue with `ShouldQueue`.
