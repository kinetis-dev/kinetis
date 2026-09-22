# Telemetry

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/telemetry
```
````

OpenTelemetry tracing for a Kinetis application, exported over OTLP to
any tracing backend — Jaeger, Grafana Tempo, Datadog, Honeycomb, or
anything else that speaks the protocol. Requests, SQL queries and
transactions, queue jobs and the framework's own pipeline are traced as
soon as an endpoint is configured; outgoing HTTP, cache, session and
search calls are traced once their client is wrapped.

```{important}
Tracing is off until `OTEL_EXPORTER_OTLP_ENDPOINT` is set. Once it is on,
a span describes an operation and never the data it carried: no SQL text
or bound values, no cache keys or values, no request or outgoing URL
paths and query strings, no session ids or payloads, no search index
names or document ids, no exception messages or stack traces. No setting
exports them. {ref}`telemetry-data-minimization` lists what travels
instead.
```

A request that runs two queries and an HTTP call through `concurrently()`
shows their spans overlapping inside the request span — under a runtime
where they do overlap (see {ref}`concurrency-overlap`).

## Configuration

Installing the package registers its pieces automatically (via
`extra.kinetis`); one environment variable turns exporting on:

| Key | Default | Purpose |
|---|---|---|
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | The collector's OTLP/HTTP base URL, e.g. `http://jaeger:4318`; `/v1/traces` is appended. Unset means tracing is off: a no-op provider is bound and every span is free. |
| `OTEL_SERVICE_NAME` | `kinetis` | The `service.name` resource attribute — what the trace backend groups by. |
| `OTEL_EXPORTER_OTLP_HEADERS` | — | Headers on every export request, `key=value,key2=value2` — where a hosted backend's auth goes (`x-honeycomb-team=...`, Grafana Cloud's `Authorization=Basic ...`). |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | `always_on`, `always_off`, `traceidratio`, or their `parentbased_*` variants. An unrecognized name throws, never a silent fallback. |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | The ratio for the `traceidratio` samplers, `0`–`1` — `0.1` keeps roughly one trace in ten. A value outside the range throws. |

An export request, with its `OTEL_EXPORTER_OTLP_HEADERS`, goes only to
the configured endpoint: a redirect is never followed.

### When spans are exported

Spans batch in memory. A batch exports when it fills, when a span ends
five seconds or more after the batch's first span, and at shutdown —
request end under PHP-FPM, worker exit under a persistent worker. The
export runs inside the request that ends the triggering span, through
`kinetis/revolt-http-client`'s Fiber-suspending transport.

One export is one wire attempt under a fixed budget: up to ten seconds
to reach the collector and up to ten more to exchange the batch. A
collector that accepts the request and never answers it costs the
triggering request that much and no more, whether the trigger is a full
batch or the shutdown flush. There is no setting for it.

```{warning}
A batch whose export budget runs out is dropped, not resent. The
collector may already have stored it, so the outcome is unknown and a
second POST could duplicate the spans; the SDK logs the failure and the
batch is gone. Later spans are unaffected, and each subsequent batch
pays the same bound again while the collector stays stalled — keep the
collector close to the application and healthy.
```

{doc}`appendix-observability` gives the batch and export parameters and
the resource attributes the OpenTelemetry SDK reads from the environment.

## Spans you get without wiring

### Requests

`RequestSpanMiddleware` is discovered as global middleware the moment
the package is installed. Every request gets a server span carrying
`http.request.method`, `http.response.status_code` and
`php.memory.usage` — under a persistent worker, a slow upward drift of
that last attribute across one worker's spans is the memory-leak
detector. An incoming `traceparent` header makes the span a child of the
caller's trace.

The request span carries no form of the request path or query string. A
path's segments are the user ids, email addresses, document ids and
single-use tokens a request is addressed by. The matched route template
is the safe shape, and it appears as `http.route` on the `route.match`
child span once the router resolves it.

### SQL queries and transactions

Every client `kinetis/database-bridge` builds reports each statement it
dispatches — PDO and native, MySQL and Postgres. A query span is named by
the statement's opening keyword (`SELECT`, `INSERT`) and carries
`db.system.name`, `db.operation.name`, and a
`kinetis.db.query_fingerprint` that groups every execution of the same
statement. A `server.started` event marks the moment the statement
reached the server: everything before it is the wait for a free pooled
connection.

A transaction gets a span from begin to `COMMIT`/`ROLLBACK`, carrying
`db.transaction.outcome`: `commit` or `rollback` only when the server
acknowledged it, and `unknown` otherwise. Commit duration is where fsync
cost shows up.

### Queue jobs

`push()` gets a producer span named `{queue} publish`. A worker's
consumer span, `{queue} process`, opens when a job starts and closes when
it settles, carrying `messaging.destination.name`, `kinetis.job.class`,
`kinetis.job.attempt`, and `kinetis.job.outcome` — `ack`, `release`, or
`fail`, the same vocabulary {doc}`queue` settles a job by — plus the
failure's type and an error status when `handle()` threw. Queries and
HTTP calls inside `handle()` nest under it.

Producer and consumer spans are one trace across processes: the push
stores the trace context with the job, and the consumer span parents to
it however much later the job runs. A worker killed without graceful
shutdown loses the spans it had not exported yet.

### Framework internals

The same hooks report spans from inside the framework:

- boot phases — `bootstrap.env`, `bootstrap.services`, and on a
  development boot `bootstrap.discovery`;
- each middleware layer, `route.match`, DTO hydration, the controller
  method, and `response.encode`;
- a `concurrently()` batch and each of its tasks;
- event listeners, MCP tool calls and resource reads.

{doc}`appendix-observability` describes the hook boundary and how span
parentage crosses Fibers.

## Spans you wire in

### Outgoing HTTP

Hand the tracing transport to {doc}`revolt-http-client`'s `Http`:

```{code-block} php
:caption: bootstrap.php

use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Kinetis\RevoltHttpClient\Http;
use Kinetis\Telemetry\HttpClient\TracingHttpClient;
use OpenTelemetry\API\Trace\TracerProviderInterface;

$app->instance(Http::class, new Http(new TracingHttpClient(
    AmpHttpClientFactory::create(),
    $app->get(TracerProviderInterface::class),
)));
```

Each outgoing request gets a client span, and a `traceparent` header is
injected so an instrumented downstream service joins the trace. The span
ends when the response is consumed, not when `request()` returns, and
each retry from `Http::withRetries()` gets its own span.

The span carries `url.scheme`, `server.address` and `server.port`, plus a
`kinetis.http.url_fingerprint` covering the whole URL. Nothing else from
the URL travels: userinfo, path, query string and fragment are where
credentials, API keys, signatures and reset tokens appear.

### Cache

Wraps a PSR-16 `CacheInterface`, {doc}`redis`'s `RedisSimpleCache`
included:

```{code-block} php
:caption: bootstrap.php

use Kinetis\SimpleCache\RedisSimpleCache;
use Kinetis\Telemetry\SimpleCache\TracingSimpleCache;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\SimpleCache\CacheInterface;

// fromConfig() returns null when neither REDIS_URL nor REDIS_HOST is
// set — the same "Redis is optional" case AppScope::boot() itself
// falls back to NullSimpleCache for.
$redis = RedisSimpleCache::fromConfig($config)
    ?? throw new RuntimeException('REDIS_HOST/REDIS_URL must be set to trace the cache.');

$app->instance(CacheInterface::class, new TracingSimpleCache(
    $redis,
    $app->get(TracerProviderInterface::class),
));
```

Each PSR-16 method gets a span named by the operation, carrying a
`kinetis.cache.key_fingerprint` over the keys it touched and
`db.operation.batch.size` for the multi-key methods. A cache key is built
from whatever identifies the cached thing — a user id, a tenant, a
session id, a reset token — so neither the key nor the value travels.

### Sessions

Wraps any `SessionStoreInterface`, {doc}`session`'s file, Redis and SQL
stores included. `SESSION_DRIVER`'s own bindings are lazy factories, so
re-binding in `bootstrap.php` — the {ref}`custom-stores` pattern —
replaces it cleanly:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Telemetry\Session\TracingSessionStore;
use OpenTelemetry\API\Trace\TracerProviderInterface;

// The same store SESSION_DRIVER=file would have bound, wrapped —
// swap the inner store to match whichever driver is actually configured.
$app->bind(SessionStoreInterface::class, static fn (): TracingSessionStore => new TracingSessionStore(
    new FileSessionStore($config->string('SESSION_FILES_DIR', sys_get_temp_dir() . '/kinetis-sessions')),
    $app->get(TracerProviderInterface::class),
));
```

`read`, `create`, `update`, and `destroy` each get a span. A session id is
a bearer credential, so only `kinetis.session.id_fingerprint` travels,
enough to correlate one session's spans. The payload never travels.

### Search

Tracing plugs into the search client's transport. For OpenSearch, pass
the decorator to the factory:

```{code-block} php
:caption: bootstrap.php

use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use Kinetis\Telemetry\Search\SearchSystem;
use Kinetis\Telemetry\Search\TracingSearchTransport;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Http\Client\ClientInterface;

$client = OpenSearchClientFactory::fromConfig(
    $config,
    transportDecorator: static fn (ClientInterface $inner): ClientInterface
        => new TracingSearchTransport($inner, $app->get(TracerProviderInterface::class), SearchSystem::OpenSearch),
);
```

An Elasticsearch client must not become a worker-lifetime binding, for
the reason {ref}`why-the-client-is-short-lived` gives. Decorate the shared
transport and keep both client bindings per resolution:

```{code-block} php
:caption: bootstrap.php

use Elastic\Elasticsearch\Client;
use Kinetis\Search\SearchClient;
use Kinetis\Search\SearchTransport;
use Kinetis\SearchElasticsearch\ElasticsearchClient;
use Kinetis\SearchElasticsearch\ElasticsearchClientFactory;
use Kinetis\Telemetry\Search\SearchSystem;
use Kinetis\Telemetry\Search\TracingSearchTransport;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientInterface;

$transport = SearchTransport::fromConfig(
    $config,
    ElasticsearchClientFactory::CONFIG_PREFIX,
    decorator: static fn (ClientInterface $inner): ClientInterface
        => new TracingSearchTransport($inner, $app->get(TracerProviderInterface::class), SearchSystem::Elasticsearch),
);

$app->bind(
    Client::class,
    static fn (): Client => ElasticsearchClientFactory::over($transport, $config),
    shared: false,
);

$app->bind(
    SearchClient::class,
    static fn (ContainerInterface $container): SearchClient
        => new ElasticsearchClient($container->get(Client::class)),
    shared: false,
);
```

A search span is named from the HTTP method and the engine action the
path performs — `POST _search`, `GET _doc` — and carries
`db.system.name` (`opensearch` or `elasticsearch`) and
`kinetis.search.path_fingerprint`. Index names, aliases and document ids
do not travel.

## Log correlation

`TraceAwareLogger` wraps the application's PSR-3 logger and adds the
active span's `trace_id` and `span_id` to each entry's context, so log
lines join their trace in a backend that receives both:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Telemetry\Logging\TraceAwareLogger;
use Psr\Log\LoggerInterface;

$app->instance(LoggerInterface::class, new TraceAwareLogger($realLogger));
```

An entry logged with no active span gets no ids, and ids the caller put
in the context are kept. The logger does not send logs anywhere; the
wrapped logger does.

(telemetry-data-minimization)=
## What never reaches a span

A trace is exported to a backend, retained there, and readable by
everyone with access to it — a wider audience than the database, cache,
or upstream service an operation's input was addressed to. So a span
describes an operation and never the data it carried, and no setting
turns that off.

| Never exported | Exported instead |
|---|---|
| A SQL statement, its literal values, its bound parameters | The opening keyword from a fixed vocabulary, `kinetis.db.query_fingerprint` |
| A cache key, single or batched, and every cached value | `kinetis.cache.key_fingerprint` over the operation's key list, `db.operation.batch.size` for the multi-key methods |
| A URL's userinfo, path, query string, and fragment | `url.scheme`, `server.address`, `server.port`, `kinetis.http.url_fingerprint` |
| An incoming request's path or query string | `http.request.method`, and `http.route` on the `route.match` span once the router resolves a template |
| A search index name, document id or alias | The method and the engine action from fixed vocabularies as the span name, `db.operation.name`, `kinetis.search.path_fingerprint` |
| A session id, and the session payload | `kinetis.session.id_fingerprint` |
| A failure's message and stack trace | The exception's type — its own class, or an anonymous class's nearest named ancestor — as the span status description and as an `exception` event's `exception.type` |

A fingerprint is a truncated SHA-256 digest: equal inputs group together
in the backend, and the value itself is absent from the trace. It is not
a secret — the digest is unkeyed, so anyone holding a candidate value can
confirm it by hashing it, and a value drawn from a small set stays
guessable.

`server.address` and `server.port` name which services the application
calls; the deployment's own topology decides those.

An exception still propagates unchanged. An application that wants the
message logs it where its own redaction policy applies, and
`TraceAwareLogger` puts the trace id on that log line.

{doc}`appendix-observability` describes how fingerprints are built and
the closed vocabularies span names come from.

## A failing backend never changes what the application does

Every call from the framework into the telemetry backend is contained. A
backend that throws stops producing spans; it cannot replace a
controller's exception, disturb `concurrently()`, or make a queue
producer report a sent job as failed. A contained failure is logged once
to `error_log()`, naming the hook, the backend class and the exception
class, never the exception message or the hook's arguments.
{doc}`appendix-observability` gives the containment rules for each hook.

## What stays out of scope

The OTel *metrics* signal — counters and gauges exported on their own
schedule — is not implemented: a periodic exporter needs a timing shape
that fits a worker's idle periods, and the per-request
`php.memory.usage` span attribute already covers the leak-detection case
that matters most. Business metrics are the application's own concern
through OTel's API directly; this package instruments what the framework
owns and stops there.

## See also

- {doc}`logging` — PSR-3 logging, which `TraceAwareLogger` decorates.
- {doc}`performance-tuning` — what to do with a trace once it shows where
  a request spends its time.
- {doc}`concurrency` — why concurrent tasks appear as overlapping spans,
  and when they do not overlap.
- {doc}`queue` — trace propagation across a queue, so a job's spans join
  the request that pushed it.
- {doc}`persistence` — the drivers whose query and transaction hooks
  become spans.
- {doc}`redis` — `RedisSimpleCache`, what `TracingSimpleCache` wraps.
- {doc}`session` — the store interface and drivers `TracingSessionStore`
  wraps.
- {doc}`search-elasticsearch` — why that engine's client is bound per
  resolution, which the wiring above preserves.
- {doc}`appendix-observability` — export timing, Fiber scope ownership,
  span lifecycles, framework hooks and fingerprint construction.
