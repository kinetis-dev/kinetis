# Performance tuning

Kinetis exposes three capacity knobs that multiply into one deployment
envelope: worker thread count, per-thread connection pool width, and
the database server's own limits behind them. This page covers how
they interact, what saturates first under each workload shape, and
which numbers to read before turning anything. Database-side tuning
itself belongs to your database's documentation; what's here is the
interaction surface. The figures quoted come from load testing on an
8-vCPU application host against a dedicated MySQL 8.4 host with
sub-millisecond round trips.

Read {doc}`runtime-adapters`'s "Sizing FrankenPHP's worker threads"
and {doc}`persistence`'s "Sizing `maxConnections` under worker mode"
first. This page builds on both.

## The connection budget

Under FrankenPHP worker mode, every worker thread builds its own
connection pool, so the deployment's ceiling is a product, not a
single number:

```{code-block} text
worker_threads × maxConnections  ≤  comfortably under the DB's max_connections
```

Pool width comes from `DB_MAX_CONNECTIONS` (connection-scoped, like
every `DB_*` key) or the `$poolOptions` argument to
`SqlConnectionFactory::fromConfig()`; worker thread count from your
runtime's own worker setting.

"Comfortably" is doing real work in that sentence: leave room for
migrations, monitoring agents, a second app instance, and anything
else that connects. A concrete working point: on 8 vCPUs, **20 worker
threads × 12 connections = 240** against MySQL's default
`max_connections` of ~1000 is the joint optimum across read-heavy and
write-heavy routes. Overshooting the budget (64 threads × 20
connections = 1,280 against the same limit) is not a performance
problem but a correctness one: the surplus connection attempt is
*rejected*, and the failure arrives mid-request.

Two subtler ceilings sit below `max_connections`:

- **mysqli's poll limit.** The native MySQL driver waits on queries
  via `mysqli_poll()`, which is built on the C `select()` call inside
  ext-mysqlnd and cannot track file descriptors *numbered* above
  1024. This is separate from — and not fixed by — the Revolt
  event-loop driver extensions described in {doc}`runtime-adapters`;
  it binds on the process-wide fd table, which under FrankenPHP also
  holds the Go server's own client sockets. What decides whether a
  connection is pollable is its descriptor *number at connect time*: a
  pool connecting lazily under load opens sockets numbered after every
  client socket the server already holds, so even a within-budget pool
  can land past the ceiling mid-burst — the failure arrives as
  `mysqli_poll()` warnings and failed queries under concurrency, never
  at low load. Warm the pool at worker boot instead
  (`DB_WARM_CONNECTIONS`, or `warmConnections` in `$poolOptions` — see
  {doc}`persistence`): connections opened before traffic exists claim
  low numbers and keep them for the process's lifetime. Keep
  `worker threads × maxConnections` under ~1000 so a fully warmed pool
  fits below the ceiling at all.
- **Prepared-statement count (MySQL).** The PDO drivers cache
  prepared statements per connection (see {doc}`persistence`), and
  MySQL caps server-side statements globally via
  `max_prepared_stmt_count` (default 16,382). The relevant product is
  `connections × distinct statements per connection` — generous
  headroom at the budgets above, worth knowing about at very wide
  ones.

Under PHP-FPM the same budget exists with different names: one PDO
connection per FPM worker, so `pm.max_children` *is* your connection
count. An FPM pool of 128 static workers is 128 connections.

## Observe before you tune

The knobs only help if you turn the one that's actually binding, and
the binding constraint is a property of your routes, not of the
framework. Three numbers, all cheap to get during a load test, answer
"who saturates first":

1. **Application CPU** (`top` on the app host). If it's pegged, more
   threads won't help — the work itself needs to get cheaper.
2. **Database CPU** (`top` on the DB host). If *it's* pegged while
   the app idles, no application-side knob will move throughput; see
   the write-heavy section below.
3. **In-flight database work** (`SHOW GLOBAL STATUS LIKE
   'Threads_running'` on MySQL, `SELECT count(*) FROM
   pg_stat_activity WHERE state = 'active'` on Postgres). Tens of
   *running* — not merely connected — threads on a modest DB host
   means the database is queueing your queries internally; sending
   more concurrency adds latency, not throughput.

Read all three before turning anything: "add more threads" and "the
CPU must be full somewhere" are both instincts that measurement
regularly contradicts.

## Tuning by workload shape

**Read-heavy fan-out** (many independent `SELECT`s per request via
`concurrently()`): this shape rewards width on both axes — threads at
2–3× vCPUs, and a pool wide enough that a typical request's fan-out
doesn't queue inside it. A 20-query route keeps gaining from pool
width all the way to 20 connections per thread; reads scale until one
of the two CPUs is genuinely full.

**Write-heavy** (per-row `UPDATE`/`INSERT` fan-outs): the opposite.
The database's write path — row locks, index maintenance, the redo
log and its group commit — saturates long before either CPU count
suggests. Past roughly 8 connections per thread, additional write
concurrency *reduces* total throughput while the DB host runs at ~95%
CPU. Capping how many writes each individual request has in flight
does not help — contention tracks **total** in-flight writes across
all requests, not any one request's share — so the levers for
DB-bound write throughput are on the database side: hardware, schema,
batching semantics, and the durability trade-offs of parameters like
`innodb_flush_log_at_trx_commit` or Postgres's `synchronous_commit`,
which are business decisions about crash-loss tolerance, not free
speed.

**Mixed** (most real applications): budget for the write ceiling,
spend the remainder on read width. The 20 × 12 working point above
comes from exactly this compromise — read fan-out routes want 20
connections, write routes want 8, and 12 holds both within a few
percent of their individual bests. When one route class truly
dominates your traffic, bias toward its optimum; the numbers are
close enough that precision beyond "width for reads, restraint for
writes" is noise.

## What not to tune

- **`DB_DRIVER`.** The `auto` split (native under worker mode, PDO
  under FPM) is itself a measured optimum. Under a worker, a 20-query
  fan-out completes in about 1.7 ms of wall time on the native driver
  against 3.7 ms on PDO — the queries genuinely overlap, which is the
  whole point of the driver. Under FPM there is nothing to overlap and
  the pool is built and discarded per request, which is expensive: for
  the same 20 queries, `DB_MAX_CONNECTIONS` of 1, 4, 8 and 20 costs
  3.2, 4.3, 6.3 and 13.4 ms of CPU, against 2.3 ms for PDO's single
  connection. Forcing the async driver there pays for overlap a
  boot-and-die lifetime cannot use, and pays again for every connection
  it opens.
- **The mysqli poll window.** The native MySQL driver's 1 ms poll
  quantum looks like an obvious latency suspect against a
  sub-millisecond database; it isn't — measured throughput is
  insensitive to it.
- **Event-loop driver extensions.** `ext-event`/`ext-uv` exist for
  the fd-numbering ceiling ({doc}`runtime-adapters`), not throughput;
  measured performance is identical when both work.

## See also

- {doc}`runtime-adapters` — worker-thread sizing and the event-loop
  fd ceiling this page's budget interacts with.
- {doc}`persistence` — the drivers, `maxConnections`, and the
  prepared-statement cache.
- {doc}`concurrency` — what `concurrently()` does and what it costs.
