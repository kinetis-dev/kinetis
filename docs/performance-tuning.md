# Performance tuning

This page is about **matching your deployment's capacity knobs to your
actual load** — specifically the three-way interaction between worker
threads, per-thread connection pools, and the database server behind
them. It is deliberately *not* a database administration guide: MySQL
and Postgres tuning are deep, well-documented fields of their own, and
prescriptions copied from a framework's docs age badly. What belongs
here is the part only Kinetis can tell you — which knobs live on which
side, how they multiply, and what saturates first under each workload
shape. Every claim on this page is measured, not reasoned from first
principles; the setup was an 8-vCPU application host and a separate
MySQL 8.4 host with sub-millisecond round trips, under saturating HTTP
load.

The one prerequisite: read {doc}`runtime-adapters`'s "Sizing
FrankenPHP's worker threads" and {doc}`persistence`'s "Sizing
`maxConnections` under worker mode" first. This page builds on both.

## The connection budget

Under FrankenPHP worker mode, every worker thread builds its own
connection pool, so the whole deployment's ceiling is a product, not a
single number:

```{code-block} text
worker_threads × maxConnections  ≤  comfortably under the DB's max_connections
```

"Comfortably" is doing real work in that sentence: leave room for
migrations, monitoring agents, a second app instance, and anything else
that connects. A concrete working point from measurement: on 8 vCPUs,
**20 worker threads × 12 connections = 240** against MySQL's default
`max_connections` of ~1000 was the joint optimum across read-heavy and
write-heavy routes — and 64 threads × 20 connections (1,280) sailed
past the server limit and turned the overflow into hard errors under
load. Overshooting this budget is not a performance problem, it's a
correctness problem: the surplus connection attempt is *rejected*, and
the failure arrives mid-request.

Two subtler ceilings sit below `max_connections`:

- **mysqli's poll limit.** The native MySQL driver waits on queries via
  `mysqli_poll()`, which is built on the C `select()` call inside
  ext-mysqlnd and cannot track file descriptors *numbered* above 1024.
  This is separate from — and not fixed by — the Revolt event-loop
  driver extensions described in {doc}`runtime-adapters`; it binds on
  the process-wide fd table, which under FrankenPHP also holds the Go
  server's own client sockets. Keeping the connection product in the
  low hundreds keeps you clear of it.
- **Prepared-statement count (MySQL).** The PDO drivers cache prepared
  statements per connection (see {doc}`persistence`), and MySQL caps
  server-side statements globally via `max_prepared_stmt_count`
  (default 16,382). The relevant product is `connections ×
  distinct statements per connection` — generous headroom at the
  budgets above, worth knowing about at very wide ones.

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
2. **Database CPU** (`top` on the DB host). If *it's* pegged while the
   app idles, no application-side knob will move throughput; see the
   write-heavy section below before reaching for DB parameters.
3. **In-flight database work** (`SHOW GLOBAL STATUS LIKE
   'Threads_running'` on MySQL, `SELECT count(*) FROM
   pg_stat_activity WHERE state = 'active'` on Postgres). Tens of
   *running* — not merely connected — threads on a modest DB host
   means the database is queueing your queries internally; sending
   more concurrency adds latency, not throughput.

The instructive failure mode, seen in practice: application half idle,
throughput flat no matter how many threads were added. Neither CPU was
the limit — the app was serializing internally (a framework bug, since
fixed — resident Fiber reuse, see {doc}`concurrency`). The reason to
measure all three numbers is exactly that "add more threads" and "the
CPU must be full somewhere" are both instincts that measurement can
flatly contradict.

## Tuning by workload shape

**Read-heavy fan-out** (many independent `SELECT`s per request via
`concurrently()`): this shape rewards width on both axes. Threads at
2–3× vCPUs and a pool wide enough that a typical request's fan-out
doesn't queue inside it. Widening the pool from 6 to 20 connections
per thread kept improving a 20-query route right up to the point the
budget above said stop; reads scale until one of the two CPUs is
genuinely full.

**Write-heavy** (per-row `UPDATE`/`INSERT` fan-outs): the opposite.
The database's write path — row locks, index maintenance, the redo log
and its group commit — saturates long before either CPU count
suggests. Measured on the same pair: past roughly 8 connections per
thread, adding write concurrency *reduced* total throughput while the
DB host sat at ~95% CPU, and — the important negative result —
restructuring the application to cap how many updates each request
had in flight did nothing, because contention tracks **total**
in-flight writes across all requests, not any one request's share. If
your write throughput is DB-bound, the honest levers are on the
database side (hardware, schema, batching semantics — and the
durability trade-offs of parameters like
`innodb_flush_log_at_trx_commit` or Postgres's `synchronous_commit`,
which are business decisions about crash-loss tolerance, not free
speed) — no Kinetis knob will move it.

**Mixed** (most real applications): budget for the write ceiling,
spend the remainder on read width. The measured joint optimum above
(20 × 12 on 8 vCPUs) came from exactly this compromise — /queries-like
routes wanted 20 connections, write routes wanted 8, and 12 held both
within a few percent of their individual bests. When one route class
truly dominates your traffic, bias toward its optimum; the numbers are
close enough that precision beyond "width for reads, restraint for
writes" is noise.

## What not to tune

- **`DB_DRIVER`.** The `auto` split (native under worker mode, PDO
  under FPM) is itself a measured optimum; forcing PDO under worker
  mode measured ~20% *slower* on fan-out routes, and forcing the async
  driver under FPM pays client CPU for overlap that boot-and-die
  lifetimes can't use.
- **The mysqli poll window.** The native MySQL driver's 1 ms poll
  quantum looks like an obvious latency suspect against a
  sub-millisecond database; measurement cleared it — it was never the
  bottleneck, before or after the fixes above.
- **Event-loop driver extensions for speed.** `ext-event`/`ext-uv`
  exist for the fd-numbering ceiling ({doc}`runtime-adapters`), not
  throughput; measured performance is identical when both work.

## See also

- {doc}`runtime-adapters` — worker-thread sizing and the event-loop
  fd ceiling this page's budget interacts with.
- {doc}`persistence` — the drivers, `maxConnections`, and the
  prepared-statement cache.
- {doc}`concurrency` — what `concurrently()` does and what it costs.
