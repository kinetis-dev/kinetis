# Telemetry

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/telemetry
```
````

OpenTelemetry tracing for a Kinetis application: a span per request, per
SQL query, per queue job, and per outgoing HTTP call, exported over OTLP
to any tracing backend — Jaeger, Grafana Tempo, Datadog, Honeycomb, or
anything else that speaks the protocol. Export goes through
`kinetis/revolt-http-client`'s Fiber-suspending transport, so flushing a
span batch never blocks the worker.

The distinctive trace this produces: spans that *overlap in time*. A
request that runs two queries and an HTTP call through `concurrently()`
shows all three side by side inside the request span — the visual proof
of what non-blocking I/O actually did for that request.

## Configuration

Installing the package registers its pieces automatically (via
`extra.kinetis`); one environment variable turns exporting on:

| Key | Default | Purpose |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | The collector's OTLP/HTTP base URL, e.g. `http://jaeger:4318`. Unset means tracing is off: a no-op provider is bound and every span is free. |
| `OTEL_SERVICE_NAME` | `kinetis` | The `service.name` resource attribute — what the trace backend groups by. |
| `OTEL_EXPORTER_OTLP_HEADERS` | — | Headers on every export request, `key=value,key2=value2` — where a hosted backend's auth goes (`x-honeycomb-team=...`, Grafana Cloud's `Authorization=Basic ...`). |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | `always_on`, `always_off`, `traceidratio`, or their `parentbased_*` variants. An unrecognized name throws, never a silent fallback. |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | The ratio for the `traceidratio` samplers, `0`–`1` — `0.1` keeps roughly one trace in ten. |

Spans batch in memory and export when the batch fills or at shutdown —
which is request end under PHP-FPM and worker exit under FrankenPHP, so
both shapes flush with no further configuration.

## Request spans

`RequestSpanMiddleware` is discovered as global middleware the moment
the package is installed — nothing to register. Every request gets a
server span carrying the method, `url.path`, response status, and
`php.memory.usage` — under a persistent worker, a slow upward drift of
that last attribute across one worker's spans is the memory-leak
detector. An incoming `traceparent` header makes the span a child of
the caller's own trace.

The request span is *active* while the request runs, which is what
parents every other span below under it automatically — including
inside `concurrently()` tasks.

## SQL query spans

Wrap whatever link `bootstrap.php` registers:

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\Telemetry\Persistence\TracingMysqlLink;
use OpenTelemetry\API\Trace\TracerProviderInterface;

return static function (AppScope $app, Config $config): void {
    $app->instance(MysqlLink::class, new TracingMysqlLink(
        SqlConnectionFactory::fromConfig($config),
        $app->get(TracerProviderInterface::class),
    ));
};
```

`TracingPostgresLink` is the Postgres side. Both implement their dialect
marker themselves, so {doc}`query-builder` dialect detection sees the
decorated link exactly like the real one, and both wrap the transactions
they begin — `COMMIT` and `ROLLBACK` get spans too, which is where fsync
cost becomes visible.

Each query span is named by the query's first keyword (`SELECT`,
`INSERT`) and carries the full SQL as `db.query.text`. Bound parameter
values are never recorded — they are exactly the data most likely to be
sensitive.

## Queue spans

Wrap the queue the same way:

```{code-block} php
use Kinetis\Queue\QueueFactory;
use Kinetis\Queue\QueueInterface;
use Kinetis\Telemetry\Queue\TracingQueue;

$app->instance(QueueInterface::class, new TracingQueue(
    QueueFactory::fromConfig($config),
    $app->get(TracerProviderInterface::class),
));
```

`push()` gets a producer span. On the worker side, a consumer span opens
when `pop()` hands a job over and closes at `ack()`, `release()`, or
`fail()` — its duration is the job's real processing time, and it
carries the outcome and attempt number. The consumer span is active
while the job runs, so queries and HTTP calls inside `handle()` nest
under it.

With the framework hooks active (the section below), producer and
consumer spans join **one trace across processes**: the hooks store a
`traceparent` in the job's payload metadata at `push()` and the worker's
consumer span parents to it, however many seconds and processes apart
the two are. This decorator honors that metadata on `pop()`; its own
`push()` cannot inject it (a decorator has no way to reach the payload),
so producer-side propagation is hook-only.

One operational note: a worker killed without graceful shutdown (see
{doc}`queue` on `ext-pcntl`) loses whatever span batch it had not yet
exported — the flush runs on shutdown, and a hard kill never reaches
it.

## Outgoing HTTP spans

Hand the tracing transport to {doc}`revolt-http-client`'s `Http`:

```{code-block} php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\Telemetry\HttpClient\TracingHttpClient;

$app->instance(Http::class, new Http(new TracingHttpClient(
    AmpHttpClientFactory::create(),
    $app->get(TracerProviderInterface::class),
)));
```

Each outgoing request gets a client span, and a `traceparent` header is
injected so an instrumented downstream service joins the trace — the
span crosses process and language boundaries. Because requests through
this transport return immediately and complete later, the span ends
when the response is actually consumed, not when `request()` returns.

When composing with `Http::withRetries()`, wrap the tracing transport
first and add retries on top — each attempt then gets its own span, so
the failure that triggered a retry stays visible.

## Log correlation

`TraceAwareLogger` wraps whatever PSR-3 logger the application
registers and adds the active span's `trace_id`/`span_id` to every
entry's context, so log lines join their trace in a backend that
receives both:

```{code-block} php
use Kinetis\Telemetry\Logging\TraceAwareLogger;
use Psr\Log\LoggerInterface;

$app->instance(LoggerInterface::class, new TraceAwareLogger($realLogger));
```

## Framework hooks: spans from inside the framework

The decorators above measure at boundaries the framework exposes; the
hooks measure from *inside* them. Core (and the persistence and queue
packages) report named moments through
`Kinetis\Instrumentation\TelemetryInterface` — a no-op until this
package's bootstrap swaps in its OTel backend, at which point every
report becomes a span with zero configuration beyond the same
`OTEL_EXPORTER_OTLP_ENDPOINT`:

- **Boot phases** — `bootstrap.env`, `bootstrap.discovery`,
  `bootstrap.services`, measured by the entry point with plain
  timestamps and reported once a backend exists. Under boot-and-die
  runtimes these appear per request; under a worker, once per boot.
- **The request pipeline, opened up** — a span per middleware layer,
  `route.match` (carrying the matched template as `http.route`),
  hydration per DTO, `Controller::method`, and `response.encode`. The
  previously unattributed gap between a request span and its query
  spans now has names.
- **Queries, split at the pool boundary** — a span per query from
  inside the drivers, with a `server.started` event marking the moment
  it actually went to the server: everything before that event is time
  spent waiting for a free pooled connection, the number that is
  invisible from outside.
- **Transactions** — begin to `COMMIT`/`ROLLBACK`, with the outcome as
  an attribute.
- **`concurrently()`** — a span for the batch and one per task, so
  overlap is visible even for tasks that aren't queries or HTTP calls.
- **Events and listeners, MCP tool calls and resource reads, queue
  push and worker jobs** — each a named span pair.

The hook set is deliberately broad while under evaluation, and will be
thinned by measurement — see the interface's own docblock. Measured
cost with no backend installed: a hook pair costs about 90ns, and a
fully hooked dispatch adds one to two microseconds. The interface is not a consumer
extension point — an application *reads* this data from its tracing
backend rather than implementing the interface.

Note the overlap with the decorators: with hooks active, the SQL and
queue decorators report the same operations a second time. Prefer the
hooks (they see more); keep the decorators for selective tracing with
no OTLP endpoint configured elsewhere, or drop them.

## What stays out of scope

The OTel *metrics* signal — counters and gauges exported on their own
schedule — is deferred: a periodic exporter needs a timing shape that
fits a worker's idle periods, and the per-request `php.memory.usage`
span attribute already covers the leak-detection case that matters
most. Business metrics are the application's own concern through OTel's
API directly; this package instruments what the framework owns and
stops there.
