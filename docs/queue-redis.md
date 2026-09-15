# Queue (Redis)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/queue-redis
```
````

Adds Redis as a backend for {doc}`queue`. Switching to it changes
configuration, not application code.

```{code-block} text
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
QUEUE_VISIBILITY_TIMEOUT_SECONDS=300
```

```{code-block} sh
vendor/bin/kinetis queue:work --queue=high,default
```

## Redis transport and Cluster

`kinetis/queue-redis` sends every command through `kinetis/redis`
({doc}`redis`), which Composer installs with it: a transport that never
re-sends a command whose reply it did not receive.

`kinetis/redis` supports Redis Cluster for application use: its
`ClusterClient` routes commands by slot (see
[Redis Cluster](redis.md#redis-cluster)), and `kinetis/cache-redis`
uses it under `REDIS_CLUSTER=true` (see {doc}`appendix-packages`).
**This queue backend's supported connection is a
single Redis node.** It does not read `REDIS_CLUSTER` or
`REDIS_CLUSTER_SEEDS`, it requires `REDIS_URL` or `REDIS_HOST`, and it
follows no cluster redirect, so point it at a standalone Redis server,
not at a cluster. An application whose cache uses a cluster gives the
queue its own server through a named connection:

```{code-block} text
REDIS_CLUSTER=true
REDIS_CLUSTER_SEEDS=10.0.0.1:6379,10.0.0.2:6379,10.0.0.3:6379

QUEUE_CONNECTION=redis
QUEUE_CONNECTION_NAME=jobs
REDIS_JOBS_HOST=queue-redis.internal
```

## Configuring

Besides `QUEUE_VISIBILITY_TIMEOUT_SECONDS`, the backend reads the `REDIS_*`
keys the cache reads, scoped by `QUEUE_CONNECTION_NAME`: `REDIS_URL`, or
`REDIS_HOST` with `REDIS_PORT` and `REDIS_DATABASE`; `REDIS_PASSWORD`;
`REDIS_TIMEOUT`; and `REDIS_TLS`, `REDIS_TLS_VERIFY_PEER` and
`REDIS_TLS_CA_FILE`. {doc}`config` lists their defaults. The queue opens
its own connection rather than sharing the cache's, so `REDIS_TIMEOUT` is
its own per-command budget.

## Lease timeout

`QUEUE_VISIBILITY_TIMEOUT_SECONDS` (default `300`, at least `1`) is how
long a popped job stays leased to its worker. When a worker dies before
settling a job, any worker's next `pop()` on that queue takes the job
back once the lease expires, with its attempt count increased. No
separate reaper process runs.

A lease is never renewed. Set the timeout above your slowest job: a job
still running when its lease expires runs again beside the first.
[Redis mechanisms](appendix-queue.md#redis) describes the lease
algorithm.

## When a Redis command fails

A command whose reply never arrives raises
`Kinetis\Redis\Exception\OutcomeUnknown`, and the transport never sends
it again. From `push()`, the job may be queued. From `pop()`, a job may
stay leased to no worker until its lease expires. From `ack()`,
`release()` or `fail()`, the settlement may or may not have happened and
the job may run again. `Kinetis\Redis\Exception\ConnectionFailed` means
the command never reached Redis. Raised inside `queue:work`, either
exception stops the worker.

## Clearing a queue

`RedisQueue` declares `ClearableQueueInterface` (see {doc}`queue`'s
"Clearing is a separate capability"). Clearing removes a queue's pending
and delayed jobs and reports how many; jobs leased to a running worker
are untouched. `queue:stats` counts pending jobs, delayed jobs and
expired leases.

## Delays and retries

A delayed job becomes available on the first `pop()` sweep after its
delay elapses, so it runs late while every worker is busy. The delay is
measured with the clocks of the pushing and popping hosts, so keep them
synchronized; lease expiry uses the Redis server's clock. Retries follow
{doc}`queue`: `maxAttempts`, `QUEUE_MAX_ATTEMPTS`, and immediate release.

## See also

- {doc}`queue` — jobs, workers, retries and delivery guarantees.
- {doc}`appendix-queue` — the lease algorithm and delivery contracts.
- {doc}`redis` — the transport, Redis Cluster, and what a failed
  command's outcome means.
- {doc}`appendix-packages` — `kinetis/cache-redis`, the cluster-capable
  cache that reads the same `REDIS_*` keys.
- {doc}`config` — named connections and every `REDIS_*` key.
