# Appendix: Queue Contracts

The delivery, worker and backend contracts behind {doc}`queue`. That
guide covers writing, pushing and running jobs, and each backend guide
covers its setup; this page is the reference they link to.

## Delivery semantics

### At-least-once delivery

Delivery is at-least-once for accepted jobs while workers are running: a
job the backend holds is delivered until a worker settles it, and can be
delivered more than once. It is not a promise of eventual execution.
Nothing is delivered while the backend is unavailable, and a job the
backend deletes — an SQS message outliving its retention period, a
purged queue — is gone.

Redis, SQL and RabbitMQ reserve the job a `pop()` finds in one atomic
step, so two workers popping at the same instant never receive the same
delivery. SQS's visibility timeout is best-effort: AWS does not
guarantee that a standard queue will not deliver a message more than
once within that timeout, so two workers can run the same SQS job
concurrently. On every backend a reservation is not exclusivity for all
time, and the same job body reaches a worker again when:

- a worker stops after the job's effect and before its settlement
  reaches the backend — a crash, a `SIGKILL` after the grace period, or
  a settlement whose transport failed;
- a reservation expires while its job is still running (a Redis lease,
  an SQL reservation, an SQS visibility timeout), so a second worker
  runs the job alongside the first — which the worker's own renewal
  prevents only while it keeps running and the handler keeps yielding
  (see [Reservation renewal](#reservation-renewal));
- SQS delivers a message again on its own, even within its visibility
  timeout;
- a RabbitMQ `release()` stops between publishing the replacement and
  discarding the original (see [RabbitMQ](#rabbitmq));
- application code pushes again after a `push()` whose outcome is
  unknown.

`maxAttempts` bounds how often a handler that throws is retried. The
worker consults it only after `handle()` throws, so it cannot bound a
succession of processes that each die while running the job.

### What `push()` guarantees

Every backend validates `push($job, $delaySeconds, $queue, $maxAttempts)`
through `Kinetis\Queue\QueueContract::assertValidPushArguments()` before
telemetry, serialization, request-scope creation or backend I/O:

- `$delaySeconds` is `0` (immediate) or a positive number of seconds.
- `$queue` follows the [queue-name grammar](#queue-names).
- `$maxAttempts` is `null`, deferring to the processing worker's
  default, or `0` or more, which is the cap itself.

A violation raises `Kinetis\Queue\Exception\InvalidQueueArgumentException`.
A negative `$maxAttempts` is rejected there rather than reaching
`QueueWorker`, where it would classify a first attempt as exhausted.
`SyncQueue` validates identically although delay and attempts have no
effect there. SQS adds a 900-second delay cap and RabbitMQ a
4,194,303-second one, each raised as `InvalidArgumentException` before
anything is sent. The job is serialized under the
[argument contract](#job-arguments) before the backend is contacted, so
an `UnserializableJobException` also means nothing was written.

A `push()` that returns has a stored job behind it:

| Backend | `push()` returns after |
|---|---|
| Redis | the `LPUSH`, or for a delayed job the `ZADD`, replies |
| SQL | the `INSERT` completes |
| SQS | the `SendMessage` result resolves |
| RabbitMQ | the broker acknowledges a `mandatory` publish on a confirm-mode channel |

An exception raised after the backend was contacted does not prove the
job was not stored: a lost reply, an expired transport budget or an
unconfirmed publish can each follow a write the backend applied. On
Redis, `Kinetis\Redis\Exception\ConnectionFailed` is the failure that
proves the command was never dispatched, and
`Kinetis\Redis\Exception\OutcomeUnknown` the one that leaves it open
(see {doc}`redis`). Pushing again after an unknown outcome can store a
second copy.

### Queue names

A queue name matches `/^[A-Za-z0-9_-]{1,80}$/`: letters, digits, hyphens
and underscores, up to 80 characters. That is Amazon SQS's
standard-queue rule, the narrowest of the four backends, adopted so a
name is portable across all of them. `push()`, `pop()`, `size()`,
`clear()` and `QueuedJob`'s constructor validate a name before any
backend I/O. `pop()` and `queue:clear` also reject a name repeated in
one list, and validate the whole list before acting on any of it, so a
malformed name in the middle of `--queue` leaves every queue in it
untouched. Each violation raises `InvalidQueueArgumentException`. An
empty `$queues` list is valid: `pop()` returns `null` immediately.

### The `pop()` priority/timeout contract

Every backend implements `pop($timeoutSeconds, $queues)` identically:

- `$timeoutSeconds: 0` waits with no deadline until a job is available.
  A positive value looks for up to that many seconds and then returns
  `null`. A negative value is rejected.
- A sweep checks each queue in list order with an immediate,
  non-blocking probe, so a job already waiting on any watched queue is
  found before a backend waits at all. `SqlQueue` performs the sweep as
  one priority-ordered query.
- When a sweep finds nothing, SQS long-polls the highest-priority queue
  for up to five seconds, and Redis, SQL and RabbitMQ pause for up to one
  second, before the next sweep.
- A job a probe finds is returned immediately. Every probe reserves what
  it finds and no backend can peek without reserving, so a job arriving
  on a higher-priority queue during a wait is picked up by the next
  sweep.
- `$timeoutSeconds` bounds how long a backend keeps looking, not when
  `pop()` returns. Redis, SQL and RabbitMQ cut their pause to what is
  left of the deadline. SQS waits in whole seconds, the smallest unit
  `WaitTimeSeconds` accepts, so its wait can outlast the deadline by up
  to a second, and it checks the deadline again as soon as that wait
  returns empty. An operation already in flight — a reserve, a receive,
  a settlement — runs to completion or to its transport's own timeout.

Every wait suspends the calling Fiber rather than blocking the event
loop.

### Delivery receipts and stale settlements

`QueuedJob::$handle` is a delivery receipt: it identifies one delivery of
a job, not the logical job. The same job reaching a worker again — after
a `release()`, or after a reservation expired — carries a different
handle, and `ack()`, `release()` and `fail()` settle only the delivery
their handle names.

A backend that can tell a live reservation from a finished delivery
answers a settlement for a finished one with
`Kinetis\Queue\Exception\StaleJobHandleException` and writes nothing.
The exception's `$operation` property is the attempted
`Kinetis\Queue\JobSettlement`: `Ack`, `Release` or `Fail`.

| Backend | Fenced settlements |
|---|---|
| Redis | `ack()`, `release()` and `fail()` — each acts only while the exact envelope the handle names is still leased |
| SQL | `ack()`, `release()` and `fail()` — each matches the row id and the `reserved_token` its reservation wrote, and reads back the affected-row count |
| SQS | None. SQS answers an expired or reused receipt handle itself, and that answer propagates unchanged |
| RabbitMQ | None. A delivery tag is scoped to its channel, and reusing one is a channel-level protocol error |

On an unfenced backend a lost delivery is not reported as one. The job
still runs twice, and only the handler's idempotency stands between that
and a duplicate effect.

### Job arguments

`push()` runs every constructor argument through
`Kinetis\Queue\JobSerializer`, which reads each constructor parameter's
value from a same-named property and enforces one wire contract for
every backend — what JSON can represent, since every durable backend
stores the payload as JSON:

- `null`, `bool`, `int`, a finite `float` (not `NAN` or `INF`), and a
  valid UTF-8 `string`.
- A dense, zero-based list or a string-keyed map of those, nested up to
  32 levels. A sparse or mixed-key array has no lossless JSON
  representation and is rejected, and so is anything nested past the
  bound, which turns a self-referential array into a `push()`-time
  rejection rather than an exhausted worker.
- A `BackedEnum` case and a `DateTimeImmutable` instance (the exact
  class, not a subclass), as a top-level argument only. Each is written
  in scalar form — the enum's backing value, or an RFC 3339 timestamp
  with microseconds — and restored from the constructor parameter's
  declared type, so it comes back as an equal value rather than the same
  object. That type must be a single named type: the enum's own class, or
  `DateTimeImmutable`. A union, an intersection, `mixed`, an untyped
  parameter, an interface such as `DateTimeInterface` and any supertype
  are rejected, as are the same values nested inside an array, because
  nothing in them says what a bare string or int should become.

Anything else — a resource, a `Closure`, another object, invalid UTF-8,
raw binary data — raises
`Kinetis\Queue\Exception\UnserializableJobException` at `push()`, and so
does a constructor parameter with no same-named property. The message
names the constructor argument and, for a nested value, its location: a
list index, or a map entry's ordinal position (`items[3].{0}`) rather
than its key, since a key is application data. The value never appears,
and for a `#[Sensitive]` argument neither does its location. `SyncQueue`
enforces the same contract.

On the worker, `JobSerializer::deserializeJob()` checks the stored class
and arguments against the current code. A class that no longer exists or
no longer implements `Job`, a missing required argument, an argument
matching no parameter, a value its parameter type cannot restore, or a
throwing constructor raises `Kinetis\Queue\Exception\JobReconstructionException`
— schema drift between the pushing and popping code, handled as the
job's own failure.

## Reservation and recovery

### Reservation, crash recovery and attempts

| Backend | Reservation | A worker that dies mid-job | Attempt count on that redelivery |
|---|---|---|---|
| Redis | A lease in a sorted set, expiring by Redis `TIME` | Reclaimed by any worker's next `pop()` on that queue once the lease passes `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | Incremented |
| SQL | `reserved_at` and `reserved_token`, written under `FOR UPDATE SKIP LOCKED` | Reclaimed by the next `pop()` once the reservation passes `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | Incremented |
| SQS | The message is hidden, on a best-effort basis, for the queue's visibility timeout | Redelivered by SQS once that timeout expires | SQS's `ApproximateReceiveCount`, incremented per receive and documented by AWS as approximate |
| RabbitMQ | An unacked `basic.get` delivery | Requeued by the broker as soon as the connection drops | Unchanged: only `release()` writes the count |

Each row describes a worker that stopped renewing. A reservation a live
worker holds is extended while its job runs — see
[Reservation renewal](#reservation-renewal).

For Redis, the old envelope remains in `leased` after its worker dies and
until a later `pop()` reclaims it. A non-empty leased set therefore proves
neither that a live worker owns the job nor that redelivery happened. Reclaim
replaces that envelope with one carrying the same `id` and `pushedAt`, an
incremented `attempts`, and a different handle string.

`QueuedJob::$attempts` is the attempt number the current delivery
represents, starting at 1. Redis, SQL and RabbitMQ store the number of
completed attempts and add one on `pop()`.

### Reservation renewal

A backend that can extend a live reservation declares
`Kinetis\Queue\RenewableQueueInterface`, which adds
`visibilityTimeoutSeconds()` and `renew(QueuedJob $job)` to
`QueueInterface`. `QueueWorker` resolves the capability once, in its
constructor, and drives renewal itself: application jobs never see their
receipt and get no API of their own.

| Backend | Renewal | Fence |
|---|---|---|
| Redis | One Lua script reading Redis `TIME` and resetting the leased member's expiry with `ZADD ... XX` | The exact leased envelope; `XX` never adds a member back |
| SQL | One `UPDATE` restamping `reserved_at` with the worker's `time()` | `WHERE id = ? AND reserved_token = ?` |
| SQS | One `ChangeMessageVisibility` restoring the full `QUEUE_VISIBILITY_TIMEOUT_SECONDS` | The `ReceiptHandle`, which SQS scopes to the receive |
| RabbitMQ | None. The channel holds the unacknowledged delivery for as long as the connection lives | — |
| `SyncQueue` | None. The job runs inline with no reservation | — |

**`renew()` claims nothing about whether the delivery was still
current.** MySQL and Redis both report zero changed rows or members for
a write that stores the value already there, which a renewal at
one-second resolution routinely does, so zero cannot mean stale. There
is no `StaleJobHandleException` here and no `JobSettlement` case:
renewal settles nothing and consumes no attempt. Transport and backend
errors propagate as they do from any other operation, and `QueueWorker`
contains them.

Repeating a renewal is supported, and one failure says nothing about
whether a later attempt will fail — but it is not idempotent: every
successful call moves the reservation window forward from that call.
`renew()` may return synchronously when it needs no I/O; I/O must
suspend its Fiber rather than block the event-loop thread, bounded by
the backend or client's own operation timeout, since the worker joins a
call still in flight and cannot abandon one.

While a job runs, the worker owns one Revolt repeat watcher at half the
backend's window. It is unreferenced, so it never keeps the event loop
alive on its own — a referenced one would hide the empty-loop condition
`Kinetis\Async\ConcurrentBatch` reads as a task deadlock. At most one
renewal is in flight: a tick arriving while the previous call has not
answered is dropped rather than opening a second request against the
same receipt.

Before the delivery is settled the worker cancels the watcher and waits
out any renewal still in flight, bounded by that adapter's own operation
timeout. The wait is not optional: SQS renews and releases with the same
`ChangeMessageVisibility` call, so a renewal landing after a delayed
`release()` would replace the retry backoff. A failure of that wait is
not a renewal failure and is not contained: the renewal is still
suspended and can resume, so the error propagates and no `ack()`,
`release()` or `fail()` is attempted at all. The delivery is left to the
backend's own timeout, which is the outcome the worker can still
account for.

A failed renewal call is counted, not acted on. Later ticks keep trying,
because one refused write does not mean the rest of the lease is
unextendable. Once the settlement has been attempted — and its
lifecycle event dispatched, when it succeeded — the worker logs one
`error` carrying the failure count and the last exception, still logged
when the settlement itself threw and never in place of that exception.
No event, no exception, no retry policy and no worker restart follow
from it.

A handler that never yields to the event loop cannot be renewed: nothing
in the worker can interrupt running PHP.

### `release()` across backends

`push()`, `ack()` and `fail()` each write the job with one backend
operation. `release()`
has to take a delivery out of the reserved state and make a replacement
available, and the backends differ in whether that is one step:

| Backend | `release()` mechanism | Duplication window |
|---|---|---|
| Redis | One Lua script, conditional on the exact leased envelope still being leased | None. A stop anywhere leaves the job where it was or completes the swap, and a stale or repeated `release()` is rejected rather than queuing a second copy |
| SQL | One `UPDATE` clearing the reservation, incrementing `attempts` and setting `available_at`, matched on the delivery's reservation token | None |
| SQS | One `ChangeMessageVisibility` carrying the requested delay as the new `VisibilityTimeout` | None from `release()`; SQS's own redelivery is independent of it |
| RabbitMQ | Publish the replacement, wait for the broker's confirmation, then `nack` the original | A stop between the confirmation and the `nack` delivers the job twice. A publish the broker never confirms settles nothing, so no job is lost |

`release(QueuedJob $job, int $delaySeconds = 0)` holds the job for at
least `$delaySeconds` before it is poppable again, using the same
mechanism that backend gives a delayed `push()`:

| Backend | Delayed `release()` | Own ceiling |
|---|---|---|
| Redis | The replacement is written into the `delayed` sorted set with a due score instead of onto `pending`, chosen inside the same fenced script | None |
| SQL | The one `UPDATE` also sets `available_at` to `now + $delaySeconds` | None |
| SQS | The delay *is* the new `VisibilityTimeout` | 43200 seconds — `ChangeMessageVisibility`'s request field, wider than `DelaySeconds`; SQS refuses one beyond the message's own remaining 12 hours |
| RabbitMQ | The replacement is published into the [delay ladder](#rabbitmq) rather than onto the real queue, confirmed before the original is nacked | 4,194,303 seconds — the ladder's |

Every backend validates `$delaySeconds` through
`QueueContract::assertValidReleaseDelay()` before telemetry,
serialization or any I/O: negative is rejected. There is no universal
ceiling — how long a backend can hold a job is the backend's own
property — so SQS and RabbitMQ each raise against their own limit on top
of that check. `SyncQueue` runs the same check and stores nothing.

A crashed delivery reclaimed after its lease or reservation expired is
not a handled job failure and gets no delay: the work never ran to a
conclusion, so the next worker takes it immediately.

### Delayed jobs

| Backend | Delay mechanism |
|---|---|
| Redis | Scored in a `delayed` sorted set with the pushing process's clock; promoted by any worker's `pop()` sweep, compared against that worker's clock |
| SQL | `available_at`, written with the pushing process's clock and compared against the popping worker's clock in each `pop()` query |
| SQS | `SendMessage`'s native `DelaySeconds`, at most 900 seconds (a delayed `release()` uses `ChangeMessageVisibility` instead, with its own wider cap) |
| RabbitMQ | A broker-side [delay ladder](#rabbitmq), at most 4,194,303 seconds |

A delay is a floor on every backend: a job is not poppable before it
elapses and can become poppable later. On Redis and SQL a due job waits
for the next `pop()` a worker makes.

### Waiting counts and clearing

`size()` reports waiting work: jobs inside their `push()` delay count,
and jobs a worker holds do not. Redis and SQL count a reservation past
its timeout as waiting, since the next `pop()` can reclaim it. SQS
reports estimates, and RabbitMQ reads each queue separately rather than
at one instant. The result is a monitoring signal, not a value to branch
on.

`Kinetis\Queue\ClearableQueueInterface::clear()` discards every
unreserved job on a queue, delayed jobs included, and returns how many
that call removed. It never removes a reservation, including one past
its timeout: a clear has no handover to make, and the worker holding it
may still be running the job. The return value is not a `size()` taken alongside
it — the queue accepts pushes throughout, so the two are separate
observations of a moving number. There is no dead-letter copy.

`queue:clear` checks the capability first and, against a backend
without it, names that backend and the missing interface and exits 1
before touching any queue. It then validates the whole `--queue` list,
refuses to run without `--force`, and clears each queue in turn.

## Worker lifecycle

### One job, one scope

`QueueWorker::processNext()` handles at most one delivery:

1. `pop()` the next job. A `MalformedJobSettledException` from `pop()`
   is logged as a warning and counts as one processed item; no scope,
   telemetry or lifecycle event is created for it.
2. Create a `RequestScope` through `AppScope::createRequestScope()`, so
   every registered request-scope initializer runs on it (see
   {doc}`container`).
3. Start job telemetry.
4. Start the reservation heartbeat when the backend declares
   `Kinetis\Queue\RenewableQueueInterface` (see
   [Reservation renewal](#reservation-renewal)).
5. Rebuild the job with `JobSerializer::deserializeJob()` and invoke
   `handle()`, resolving each parameter from the scope. An untyped or
   scalar `handle()` parameter raises
   `Kinetis\Queue\Exception\UnresolvableJobParameterException`. Any
   throwable from this step is the job's failure.
6. Stop the heartbeat: cancel the watcher and wait out any renewal still
   in flight, so nothing of it survives into the settlement. A wait that
   fails propagates from here, and step 7 never runs.
7. Settle the delivery, then report the outcome — a renewal failure
   included, after the settlement attempt and never instead of it.
8. Dispose the scope and run `gc_collect_cycles()`.

`run()` repeats `processNext()` until stopped. `processNext()` is public
so a test or a process-N-then-exit script can drive single iterations.

### One transition per delivery

Only step 5 decides the outcome. A job that returns is acked. A job that
throws is failed when `QueuedJob::$attempts` has reached the effective
cap — the job's own `maxAttempts`, else the worker's default — and
released otherwise. The default is `QueueWorker`'s `$defaultMaxAttempts`
(`QUEUE_MAX_ATTEMPTS` under `queue:work`), `0` when unset, and must not
be negative. With a cap of `0` or `1` a failing job is never retried.

A release carries a delay the worker computes from the attempt that just
failed:

```{code-block} text
delay(attempt) = min(900, $retryBaseDelaySeconds * 2 ** min(attempt - 1, 10))
```

`$retryBaseDelaySeconds` is `QueueWorker`'s fourth constructor argument,
`QUEUE_RETRY_BASE_DELAY_SECONDS` under `queue:work`, `5` when unset, and
admitted in the range `0`–`900`; `0` selects immediate retries. The
900-second ceiling is worker policy, not a backend limit, and is a code
constant rather than a second setting. The exponent is capped so the
doubling cannot overflow on a high attempt count. The schedule carries
no jitter: a delayed release is already spread across whenever each
worker's own attempt failed.

The worker computes the delay only on the retrying path — `fail()` takes
none — and never sleeps or retains the job's request scope while the
delay runs. `Events\JobReleased` carries no delay field; the failure
log line and its `job` context report it instead:

```{code-block} text
Job "App\SendWelcomeEmail" failed (attempt 2), retrying in 10s: Connection refused
```

When the backend accepts the transition, the worker closes the job's
span and dispatches `Kinetis\Queue\Events\JobSucceeded`, `JobReleased`
or `JobFailedPermanently`. A final failure is logged before the
transition with the job's arguments, redacted per `#[Sensitive]`; a
failure with attempts remaining is logged without them, since the
backend still holds the payload.

When the backend answers with `StaleJobHandleException`, nothing was
written, so none of those three events is dispatched and the loop
continues:

- `Kinetis\Queue\Events\JobSettlementLost` is dispatched with the job
  class, queue, attempt number, the attempted operation, the stale
  exception, and — on the release and fail paths — the job's own
  exception.
- A warning-level log line reports the same thing.
- The job's span still closes. A lost `ack()` closes carrying the stale
  exception; a lost `release()` or `fail()` keeps the job's own
  exception, which is what the span was opened to describe.

Any other exception from `ack()`, `release()` or `fail()` propagates out
of `processNext()` and `run()` and stops the worker. The worker cannot
tell whether that settlement was applied.

### Shutdown

With `ext-pcntl` loaded, `run()` enables asynchronous signals and turns
`SIGTERM` and `SIGINT` into `stop()`. The handler only sets a flag: it
interrupts neither a running job nor a `pop()` in flight, and the loop
reads the flag once the current `processNext()` returns. A signal that
arrives while the worker waits in `pop()` still lets that `pop()` return
a job, which runs and settles before the worker stops.

That is why `run()` needs a positive poll timeout.
`QueueWorker::assertValidPollTimeout()` rejects `0`, which `pop()` reads
as "wait with no deadline" and which would keep an idle worker from ever
reading the flag. `queue:work` applies it to `QUEUE_POLL_TIMEOUT`,
`assertValidDefaultMaxAttempts()` to `QUEUE_MAX_ATTEMPTS`, and
`assertValidRetryBaseDelay()` to `QUEUE_RETRY_BASE_DELAY_SECONDS`,
before printing anything. `processNext()` accepts `0`, since one call is not a
loop.

Without `ext-pcntl`, `QueueWorker::supportsGracefulShutdown()` is false,
`queue:work` warns on startup, and the process cannot observe a stop
signal. Its supervisor's kill ends whatever job is running, and the
backend's recovery redelivers it.

`SIGTERM` and `SIGINT` are the only two signals registered. A supervisor
that sends another — a container whose image declares a different
`STOPSIGNAL`, such as the `SIGQUIT` the official PHP FPM images carry —
ends the process with the same outcome as a missing `ext-pcntl`, and
without the startup warning. Graceful shutdown needs both halves:
`ext-pcntl` loaded, and one of those two signals actually delivered.
{doc}`queue`'s "Deploys and restarts" is where a deployment settles
that.

### Observers never decide or rewrite the outcome

Everything that describes a job's outcome runs best-effort, before or
after the transition. Starting telemetry and the failure log line run
before it; completion telemetry and the `JobSucceeded`, `JobReleased`,
`JobFailedPermanently` and `JobSettlementLost` dispatch run after it. A
throwing telemetry backend or listener is reported through the scope's
logger, and a throwing logger is discarded, so none of them can block
the transition, trigger a second one, or stop the worker. When
reflecting a job's `#[Sensitive]` parameters throws while building the
final failure log line, every argument is logged as `[redacted]` instead,
with no separate report. `processNext()` moves on to the next job
regardless.

### A disposal failure never rewrites the outcome or stops the worker

The job's scope is disposed after the transition and its observers, and
also while an exception from `ack()`, `release()` or `fail()` is
propagating — {doc}`container` explains why a plain `finally` dispose is
unsafe. A disposal failure never touches the backend, so it cannot
trigger a second transition, and it never escapes `processNext()` or
stops `run()`. It is logged through `AppScope`'s logger, since the job's
scope is already disposed, with the job's class, queue and attempt
number. When a settlement exception is already propagating, that
exception is what escapes `processNext()`; the disposal failure is
reported beside it, never instead.

`SyncQueue::push()` has no loop to protect. When the job throws and
disposal also fails, `push()` rethrows the job's exception and logs the
disposal failure. When only disposal fails, that failure propagates to
the caller.

### Malformed stored messages

A backend's stored data can be corrupted after `push()` wrote it: a
hand-edited Redis payload, a database row written some other way, an
AMQP header set by another publisher. Every durable backend reserves a
message before decoding it, so a decode failure would leave a
reservation nothing releases, and the message would replay forever once
the reservation was reclaimed.

Each backend routes its decode step through
`QueueContract::settleIfMalformed()`. When decoding raises
`Kinetis\Queue\Exception\MalformedQueuedJobDataException` — invalid
JSON, a missing or wrongly shaped `class`, `args` or `metadata`, an
out-of-range counter, an invalid stored queue name — the backend removes
the message with its own primitive:

| Backend | Removal |
|---|---|
| Redis | `ZREM` of the exact leased envelope |
| SQL | The reservation-fenced `DELETE` |
| SQS | `DeleteMessage` |
| RabbitMQ | `nack` without requeue |

`pop()` then raises `Kinetis\Queue\Exception\MalformedJobSettledException`,
which `QueueWorker` logs before continuing.

Only `MalformedQueuedJobDataException` triggers removal. Any other
exception during decoding — a defect in a decoder, for instance —
propagates and stops the worker, leaving the message for the backend's
own recovery once the defect is fixed. A transport failure is never
treated as malformed data either, and a removal that itself fails
propagates as the transport failure it is.

## Failure vocabulary

| Signal | Raised when | What the backend holds | What happens |
|---|---|---|---|
| `InvalidQueueArgumentException` | A queue name, queue list, delay, timeout or attempt count is invalid | Nothing was sent | Propagates to the caller |
| `UnserializableJobException` | A constructor argument has no wire form, or a parameter has no same-named property | Nothing was sent | Propagates from `push()` |
| `QueueUnavailableException` | `QUEUE_CONNECTION` names a backend whose package is not installed | — | Propagates when the queue is resolved |
| `QueueNotClearableException` | `ClearableQueueInterface` is resolved against a backend that cannot clear | — | Propagates when resolved |
| `JobReconstructionException` | Stored class or arguments no longer match the code | The reserved delivery | Job failure: released or failed under the cap |
| `UnresolvableJobParameterException` | A `handle()` parameter is untyped or scalar | The reserved delivery | Job failure |
| `MalformedJobSettledException` | A reserved message failed to decode and was removed | Message deleted | Worker logs a warning and continues |
| `StaleJobHandleException` | A fenced settlement named a finished delivery | Nothing written | Worker dispatches `JobSettlementLost` and continues |
| `PublishNotConfirmedException` (RabbitMQ) | The broker did not acknowledge a `push()` or `release()` publish | Original delivery untouched; whether the published message was queued is unknown | Propagates; stops a worker |
| `Kinetis\Redis\Exception\ConnectionFailed` | A Redis command was never dispatched | Unchanged | Propagates; stops a worker |
| `Kinetis\Redis\Exception\OutcomeUnknown` | A Redis command was written and no reply arrived | Unknown | Propagates; stops a worker |
| Any other exception from a settlement | A transport or backend failure | Unknown | Propagates; stops the worker |

| Event | Dispatched when |
|---|---|
| `JobSucceeded` | `handle()` returned and `ack()` was accepted |
| `JobReleased` | `handle()` threw with attempts remaining and `release()` was accepted |
| `JobFailedPermanently` | `handle()` threw at the cap and `fail()` was accepted; carries the redacted arguments |
| `JobSettlementLost` | The backend rejected the settlement as stale |

At most one of the four is dispatched per delivery. {doc}`events` lists
their fields.

## Application wiring

### Clearing from application code

Application code that clears a queue names
`Kinetis\Queue\ClearableQueueInterface` in its constructor. With
`QUEUE_CONNECTION` set, this package's bootstrap binds it by resolving
the application's `QueueInterface` — including a queue the application's
own `bootstrap.php` bound — and returning that queue when it can clear,
or raising `Kinetis\Queue\Exception\QueueNotClearableException`, naming
the backend, when it cannot. Code that only pushes keeps taking
`QueueInterface`.

```{code-block} php
use Kinetis\Queue\ClearableQueueInterface;

final readonly class ImportsMaintenance
{
    public function __construct(
        private ClearableQueueInterface $queue,
    ) {}

    public function discardPendingImports(): int
    {
        return $this->queue->clear('imports');
    }
}
```

A custom backend that can clear declares `ClearableQueueInterface`,
which extends `QueueInterface`, so one `implements` clause covers both.

### Queued event listeners

With `QUEUE_CONNECTION` set, this package's bootstrap binds
`Kinetis\Events\ListenerInvokerInterface` to
`Kinetis\Queue\QueuedListenerInvoker`, so a listener marked
`Kinetis\Events\ShouldQueue` runs as a queued job. Without
`QUEUE_CONNECTION`, core's synchronous invoker stands and the listener
runs inline. The invoker resolves `QueueInterface` when a queued
listener is first dispatched, so it pushes onto whichever queue the
application ends up with. Binding either interface in the application's
own `bootstrap.php` overrides this:

```{code-block} php
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Events\SynchronousListenerInvoker;

// Configured queue, but these listeners run inline anyway.
$app->instance(ListenerInvokerInterface::class, new SynchronousListenerInvoker());
```

`QueuedListenerInvoker` never constructs the listener. It pushes an
`InvokeListenerJob` carrying the listener class, the method and the
event's serialized constructor arguments, so:

- the event's constructor arguments must meet the
  [argument contract](#job-arguments);
- the listener is constructed on the worker that pops the job, not in
  the process that dispatched the event;
- each attempt constructs and invokes the listener again, so it needs
  the same idempotency as any handler;
- it cannot stop propagation, which the dispatching process decides on
  its own event object while the worker holds a rebuilt copy. A listener
  that must stop propagation runs inline.

### Connection ownership

A queue backend lives for the whole worker, so the connection behind it
is application-scoped: opened once, and closed once when that worker
ends. `Kinetis\Queue\DisposableQueueInterface` is where that lives —
`dispose()`, extending `QueueInterface`, declared by `SqlQueue`,
`RedisQueue` and `RabbitMqQueue`. `Kinetis\QueueSqs\SqsQueue` does not
declare it: its transport is an HTTP client with no queue-owned
connection to close.

Ownership travels with construction, not with the type:

- **A backend's factory** opens the client or link it hands the queue,
  so it hands over the operation that closes it too.
  `SqlQueueFactory::fromConfig()` gives the queue its link's `close()`,
  `RedisQueueFactory::fromConfig()` the `Kinetis\Redis\Client`'s
  (`Amp\Redis\RedisClient` is a command facade with no close of its
  own), and `RabbitMqQueueFactory::fromConfig()` the
  `Thesis\Amqp\Client`'s `disconnect()`.
- **A constructor called directly** receives a client or link the caller
  already owns and closes none of it. `dispose()` is then a no-op, and
  closing that transport stays with whoever opened it — which is what
  keeps a link shared with the rest of the application usable after the
  queue is finished with it. A caller that wants the queue to own what
  it passed supplies the closing operation as the constructor's last
  argument.

`dispose()` is idempotent and safe before the queue's first I/O: a
worker that never popped anything still disposes cleanly, and a second
call does nothing.

With `QUEUE_CONNECTION` set, this package's bootstrap registers
`dispose()` on the application scope for the backend it builds, at the
moment something first injects the queue. It registers nothing for a
queue an application's own `bootstrap.php` bound, since that connection
belongs to whoever opened it. An application that builds a backend
itself registers the disposal itself:

```{code-block} php
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueSql\SqlQueue;
use Kinetis\QueueSql\SqlQueueFactory;

$queue = SqlQueueFactory::fromConfig($config);

$app->instance(SqlQueue::class, $queue);
$app->instance(QueueInterface::class, $queue);
$app->onDispose($queue->dispose(...));
```

### Multiple backends

Different queues can live on different backends — a `RedisQueue` for
low-latency jobs beside a `SqlQueue` for jobs that ride along with a
database's backups. Register each concrete class instead of binding
`QueueInterface` to one of them:

```{code-block} php
use Kinetis\QueueRedis\RedisQueue;
use Kinetis\QueueRedis\RedisQueueFactory;
use Kinetis\QueueSql\SqlQueue;
use Kinetis\QueueSql\SqlQueueFactory;

$fast = RedisQueueFactory::fromConfig($config, 'fast');
$ledger = SqlQueueFactory::fromConfig($config, 'ledger');

$app->instance(RedisQueue::class, $fast);
$app->instance(SqlQueue::class, $ledger);
$app->onDispose($fast->dispose(...));
$app->onDispose($ledger->dispose(...));
```

Each connection was opened here, so each is closed here — see
[Connection ownership](#connection-ownership).

Each factory reads its own keys under the connection name it is given —
`REDIS_FAST_*` and `QUEUE_FAST_VISIBILITY_TIMEOUT_SECONDS` for the first,
`DB_LEDGER_*` and `QUEUE_LEDGER_VISIBILITY_TIMEOUT_SECONDS` for the
second. Constructing a backend directly means supplying its transport:
`SqlQueue` takes a `Kinetis\Persistence\Contract\SqlLink`, and
`RedisQueue` takes an `Amp\Redis\RedisClient` built over {doc}`redis`'s
client as `new RedisClient($client->link())`. A transport supplied that
way stays the caller's to close.

Code that pushes to one backend injects its concrete class:

```{code-block} php
final readonly class RegistrationController
{
    public function __construct(private RedisQueue $fastQueue) {}
}
```

Run a `kinetis queue:work` process per backend, with that backend's
`QUEUE_CONNECTION`, `QUEUE_CONNECTION_NAME` and `--queue` matching the
queues pushed to it.

## Backend mechanisms

### Redis

Each queue is three keys: `kinetis_queue:{queue}:pending`, a list;
`kinetis_queue:{queue}:delayed`, a sorted set scored by ready-at time;
and `kinetis_queue:{queue}:leased`, a sorted set scored by lease expiry.
A plain list pop would remove a job at pop time and lose it with a
crashed worker; a finite lease keeps it recoverable.

Each member is the job's JSON envelope, with every one of `id`,
`pushedAt`, `class`, `args`, `attempts`, `maxAttempts` and `metadata`
required. `id` is 32 random hexadecimal characters, so two identical
jobs remain distinct members, and a retry carries `id` and `pushedAt`
forward. The leased envelope is the string handed back as
`QueuedJob::$handle`.

Every transition that could lose or duplicate a job runs as one Lua
script, which Redis executes as an indivisible unit:

- **Reserve** reads the pending tail, adds that exact envelope to
  `leased` with an expiry of Redis `TIME` plus
  `QUEUE_VISIBILITY_TIMEOUT_SECONDS`, and only then removes it from
  `pending`, so a failing `leased` key cannot destroy the only copy.
- **Release and reclaim** check that the exact old envelope is still
  leased, write its replacement — with the attempt count advanced — onto
  `pending`, or into `delayed` with a due score when `release()` carried
  a delay, and then remove the old envelope. The choice of destination
  is inside the same script as the check, so a delayed retry is as
  indivisible as an immediate one. Two sweepers racing, or a sweep
  racing a settlement, produce one winner; the loser writes nothing.
- **Promotion** moves due envelopes from `delayed` to `pending`.
- **Renewal** resets the exact leased envelope's expiry to Redis `TIME`
  plus `QUEUE_VISIBILITY_TIMEOUT_SECONDS` with `ZADD ... XX`, so a
  member the leased set no longer holds is never added back. The changed
  count is not read: `ZADD` answers 0 for a score it did not change,
  which a renewal inside the same second produces.

`ack()` and `fail()` remove the exact envelope with `ZREM` and read back
the count, and `release()` reads the script's result. Because a reclaim
writes a new envelope, a settlement from the worker that held the old
one finds nothing and raises `StaleJobHandleException`.

Each `pop()` sweep, per queue in priority order, promotes due delayed
jobs, reclaims expired leases, and then reserves. Promotion and reclaim
each handle at most 100 envelopes per sweep
(`DELAYED_PROMOTION_BATCH_SIZE`, `LEASE_RECLAIM_BATCH_SIZE`): Redis runs
one command at a time, and an unbounded script would stall every client
sharing the server. The remainder stays due for the next sweep. There is
no blocking Redis command and no reaper process; the sweep, paced with
`Amp\delay()`, is the recovery path. An expired envelope that no longer
decodes is removed as malformed on the reclaim path too, rather than
swept on every `pop()`.

Lease expiry uses Redis `TIME`, so every worker shares one lease clock.
Delayed jobs are scored with the pushing process's clock and promoted
against the popping worker's clock.

`size()` counts `pending`, `delayed` and expired leases in one script.
`clear()` counts and deletes `pending` and `delayed` in one script, so a
concurrent push cannot change the number between counting and deleting;
`leased` is untouched.

The connection is `Amp\Redis\RedisClient` over the link of a single-node
`Kinetis\Redis\Client`, which never re-sends a command whose reply was
lost and follows no cluster redirect. A reserve whose reply is lost can
leave a job leased to no worker until its lease expires.

### SQL

The `kinetis_queue_jobs` table ships as one migration stub per dialect,
because auto-increment syntax differs. Beyond the job data it carries
`metadata`, the instrumentation propagation channel (see
{doc}`telemetry`); `reserved_at`, the reservation timestamp; and
`reserved_token`, the random token naming the reservation that wrote it.
The MySQL stub declares `queue` and `reserved_token` `ascii_bin`, since
both are matched for exact equality and MySQL's default collation
compares case-insensitively.

Each `pop()` attempt runs one transaction:

1. A `SELECT ... FOR UPDATE SKIP LOCKED` matches rows on the requested
   queues that are available and either unreserved or reserved longer
   ago than the visibility timeout, ordered by queue priority through a
   portable `CASE` expression and then by `id`, and locks one row that no
   other worker holds.
2. An `UPDATE` writes `reserved_at` and a fresh random `reserved_token`
   under that lock, and increments `attempts` when the row was an expired
   reservation.

Between empty attempts `pop()` suspends for up to one second through
`Kinetis\Async\Timer::delay()`, cut to what is left of the deadline.

`ack()` and `fail()` delete the row; `release()` clears the reservation,
increments `attempts` and sets `available_at` to `now + $delaySeconds`,
the same column and format a delayed `push()` writes. Each matches
`WHERE id = ? AND reserved_token = ?`. An affected-row count other than
one raises `StaleJobHandleException`, so a late settlement can neither
delete, unreserve nor credit an attempt against a reservation another
worker now holds. That bounds a late settlement; it does not stop the
job running twice.

Renewal is one `UPDATE` setting `reserved_at = ?` under the same
`WHERE id = ? AND reserved_token = ?` predicate, touching neither
`attempts` nor `available_at`. Its affected-row count is not read:
MySQL reports 0 for an `UPDATE` that writes the value already stored,
which a renewal inside the same second does.

The malformed-row removal uses the same fenced `DELETE`. When a reclaim
lands between reserving an undecodable row and deleting it, the row
belongs to the new holder and `pop()` raises `StaleJobHandleException`
rather than deleting it. `QueueWorker` contains only
`MalformedJobSettledException` from `pop()`, so that worker stops.

`reserved_at` and `available_at` are written and compared with the
worker process's `time()`, not the database clock, so skew between
hosts shifts when a reservation looks expired and when a delay ends.

`size()` counts rows whose `reserved_at` is null or older than the
visibility timeout, delayed rows included. `clear()` deletes only rows
whose `reserved_at` is null.

### SQS

- `push()` sends `SendMessage` with `DelaySeconds`, a JSON body holding
  `class` and `args`, a numeric `maxAttempts` message attribute when one
  is set, and instrumentation metadata as one JSON-encoded `metadata`
  attribute, since SQS caps a message at ten attributes.
- `pop()` probes every queue with `WaitTimeSeconds: 0`, then long-polls
  the highest-priority queue for five seconds, or for what is left of
  the deadline rounded up to a whole second. Every `ReceiveMessage`
  carries `VisibilityTimeout: QUEUE_VISIBILITY_TIMEOUT_SECONDS`
  (default 300, admitted range 1 to 43200, validated before any client
  is built), which overrides the queue's own attribute for the messages
  this application takes.
- `QueuedJob::$attempts` is the message's `ApproximateReceiveCount`; a
  message without it is malformed.
- `ack()` and `fail()` call `DeleteMessage`. `release()` calls
  `ChangeMessageVisibility` with its `$delaySeconds` as the new
  `VisibilityTimeout`, so the message and its attributes survive
  unchanged; on a call SQS accepts, the new timeout counts from the call
  — `0` making it visible immediately. Two limits apply. The request
  field accepts 0 to 43200 seconds, which this backend raises against
  before any transport. SQS separately refuses a timeout longer than the
  time left in that received message's own 12-hour maximum and does not
  recalculate down to it; how much is left is service state, so an
  in-range value is a request SQS may still refuse. Every mutation
  resolves at its call site, so that refusal — like any service or
  network failure — fails the queue operation itself with nothing
  settled.
- `renew()` calls `ChangeMessageVisibility` with that same configured
  window, counted from the call. AWS counts a message's own 12-hour
  maximum from the receive rather than from the last renewal, so a job
  running past it is redelivered whatever the worker sends.
- `QueuedJob::$handle` is the `ReceiptHandle`. The backend cannot tell
  SQS's answer to an expired handle from any other API error, so it
  raises no `StaleJobHandleException`. If SQS rejects the settlement, its
  error propagates and stops the worker; if SQS accepts it, nothing
  reports the lost delivery.
- A queue name resolves to a URL through `GetQueueUrl`, cached for the
  instance's lifetime. No queue is created, and FIFO queues are not
  supported.
- `size()` is `ApproximateNumberOfMessages` plus
  `ApproximateNumberOfMessagesDelayed`, which excludes in-flight messages.

`SqsQueue` does not implement `ClearableQueueInterface` and never calls
`PurgeQueue`, because nothing SQS offers meets the clearing contract:

- `PurgeQueue` deletes messages a worker holds in flight along with
  waiting ones, keeps deleting messages sent during the up-to-60-second
  window it takes to finish, reports no count, and is rate-limited to
  once per 60 seconds per queue.
- `size()` excludes in-flight work and is an estimate, so it cannot
  report what a purge removed.
- A clear built from `ReceiveMessage` and `DeleteMessage` cannot reach a
  delayed message, which stays invisible until its delay elapses.

### RabbitMQ

**Publishing.** Each queue instance publishes on one channel in confirm
mode. Every publish is `mandatory` and persistent, and waits for the
broker's answer, because `Channel::publish()` returning means only that
the frames reached the socket. Any answer other than an acknowledgement
— `Nacked`, `Unrouted`, `Canceled`, `Waiting`, or no confirmation at all
on a channel outside confirm mode — raises
`Kinetis\QueueRabbitMq\Exception\PublishNotConfirmedException`.
Mandatory publishing turns a queue deleted under a worker into that
exception rather than a silent drop, and adds the
`X-Thesis-Mandatory-Id` header `thesis/amqp` correlates a returned
message by; that header travels with the job.

**Settlement.** `ack()` acks the delivery and `fail()` nacks it without
requeue. `release()` publishes a replacement carrying the incremented
`attempts` header, waits for its confirmation, and then nacks the
original without requeue. A `release()` carrying a delay publishes that
replacement into the delay ladder below instead of onto the real queue —
the same publication path a delayed `push()` takes — and the
confirm-then-nack order is the same. AMQP 0-9-1 has no cross-message
transaction:

- A failure before the confirmation leaves the original unacked, and
  the broker redelivers it, on the attempt it was popped on, once the
  connection drops.
- A stop after the confirmation and before the `nack` leaves the
  replacement queued and the original redelivered, so the job runs
  twice.

`attempts` and `maxAttempts` travel as headers, because AMQP has only a
boolean `redelivered` flag. `push()` writes no `attempts` header and only
`release()` writes one, so a broker redelivery after a dropped
connection arrives with the count unchanged. Instrumentation metadata
travels as a JSON-encoded `metadata` header, through the delay ladder and
`release()` alike. `QueuedJob::$handle` is the
`Thesis\Amqp\DeliveryMessage`.

**Polling and declaration.** `pop()` sweeps with `basic.get`, which never
blocks, and suspends for up to one second between sweeps, cut to what is
left of the deadline. A queue is declared durable on first touch by
`push()`, `pop()` or `release()`, and a delayed `push()` or `release()`
declares the tiers its own delay uses.

**Delay ladder.** AMQP 0-9-1 has no per-message delay, and RabbitMQ
expires a queue's messages from its head, so one holding queue with
per-message expiry would keep a three-second job waiting behind an
hour-long one. Each queue instead gets up to 22 holding tiers,
`{queue}.delay.1s`, `.delay.2s`, `.delay.4s` and so on to
`.delay.2097152s`, each with an `x-message-ttl` of its own length and a
matching `{queue}.delay.{seconds}s.in` topic exchange. A delay is spent
as the binary sum of the tiers — 3600 seconds is 2048 + 1024 + 512 + 16
— and every message in a tier owes that tier's wait, so nothing in it is
held up by a message owing longer.

The delay's bit pattern travels as the routing key. Each tier's exchange
routes a message whose bit is set into that tier's holding queue and one
whose bit is clear straight to the next exchange down; each holding
queue dead-letters into the next exchange down, and the lowest tier into
the real queue. Dead-lettering preserves the routing key, so nothing
polls between hops. A delayed `push()` enters at its highest set bit and
declares the tiers up to it — twelve for an hour — while `size()` and
`clear()` declare all 22, since a job parked by another process can be
in any of them. Tiers are ordinary durable queues, empty except while a
job waits, and need no configuration; a queue those operations have run
against shows the whole ladder in the management UI. Every tier and
exchange name contains a `.`, which the queue-name grammar forbids, so
no application queue collides with one.

The longest delay is 4,194,303 seconds (2²² − 1, about 48 days).
`thesis/amqp` encodes an `x-message-ttl` as a signed 32-bit millisecond
value, which caps the top tier at 2²¹ seconds; the limit is the client's
encoding, not RabbitMQ's.

**Counting and clearing.** `size()` sums the message counts the broker
returns when each tier and the real queue are declared. `clear()` purges
from the top tier down, in the direction a delayed message travels, then
the real queue, and sums what the broker reports removing. Both are
separate operations per queue rather than one snapshot, so a job moving
between tiers can be counted twice, missed, or outlive the purge.
Messages delivered to a consumer and not yet acked are excluded from
both by the broker's own rule.
