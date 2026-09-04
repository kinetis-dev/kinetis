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
which is request end under PHP-FPM and worker exit under a persistent
worker (FrankenPHP or RoadRunner), so every shape flushes with no
further configuration.

## Request spans

`RequestSpanMiddleware` is discovered as global middleware the moment
the package is installed — nothing to register. Every request gets a
server span carrying the method, response status, and
`php.memory.usage` — under a persistent worker, a slow upward drift of
that last attribute across one worker's spans is the memory-leak
detector. An incoming `traceparent` header makes the span a child of
the caller's own trace.

No form of the request target travels on that span. A path's segments
are the user ids, email addresses, document ids and single-use tokens a
request is addressed by, and the one shape of it that stays safe — the
matched route template — belongs to the router, which runs inside the
handler this middleware wraps. The framework hooks below are where that
template surfaces: with them active, `route.match` is a child of the
request span carrying the template as `http.route`.

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

Each query span is named by the query's opening keyword (`SELECT`,
`INSERT`) and carries `db.operation.name`, a
`kinetis.db.query_fingerprint` that groups every execution of the same
statement, and — for `execute()` — `kinetis.db.parameter_count`. The
statement itself does not travel; see
{ref}`telemetry-data-minimization` for why, and for what the rest of
this package does with the inputs it sees.

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

The span carries `url.scheme`, `server.address` and `server.port`, plus
a `kinetis.http.url_fingerprint` covering the whole URL. An outgoing
URL is caller-supplied and holds a credential often enough that nothing
else in it travels — `https://user:pass@host/`, an API key or a
signature as a query parameter, a token in the fragment, a reset token
or a document id as a path segment — and a general-purpose client has
no route template to reduce a path to. The wrapped client is handed the
URL and the method exactly as written; the span names the method from a
fixed vocabulary, `HTTP` for anything outside it.

## Cache spans

Wraps any PSR-16 `CacheInterface`, {doc}`persistence`'s
`RedisSimpleCache`/`ClusteredRedisSimpleCache` included:

```{code-block} php
use Kinetis\SimpleCache\RedisSimpleCache;
use Kinetis\Telemetry\SimpleCache\TracingSimpleCache;
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

Each PSR-16 method (`get`, `set`, `delete`, `has`, `clear`,
`getMultiple`, `setMultiple`, `deleteMultiple`) gets its own span,
named by the operation and carrying a
`kinetis.cache.key_fingerprint` over the keys it touched — plus
`db.operation.batch.size` for the three multi-key methods. A cache key
is built from whatever identifies the thing being cached, which is
routinely a user id, a tenant, a session id, or a password-reset token,
so the key is as sensitive as the value and neither travels.

## Session spans

Wraps any `SessionStoreInterface`, {doc}`session`'s file/cache/SQL
stores included. `SESSION_DRIVER`'s own bindings are lazy factories
resolved on first use, so re-binding in `bootstrap.php` — the same
{ref}`custom-stores` pattern the session package's own docs already
use — replaces it cleanly:

```{code-block} php
use Kinetis\Session\SessionStoreInterface;
use Kinetis\Session\Store\FileSessionStore;
use Kinetis\Telemetry\Session\TracingSessionStore;

// The same store SESSION_DRIVER=file would have bound, wrapped —
// swap the inner store to match whichever driver is actually configured.
$app->bind(SessionStoreInterface::class, static fn (): TracingSessionStore => new TracingSessionStore(
    new FileSessionStore($config->string('SESSION_FILES_DIR', sys_get_temp_dir() . '/kinetis-sessions')),
    $app->get(TracerProviderInterface::class),
));
```

`read`, `write`, and `destroy` each get a span. A session id is a
bearer credential — whoever holds it can present the cookie and act as
that session — so it never reaches a span verbatim: its fingerprint
travels instead, enough to correlate every span for one session without
handing a trace reader the credential itself. The payload never travels
at all.

## OpenSearch spans

`kinetis/search-opensearch`'s `OpenSearchClientFactory` has no
Kinetis-owned interface to decorate — it hands back the real,
unwrapped `OpenSearch\Client` — so tracing plugs in at its own
`transportDecorator` seam instead, wrapping the fully-configured PSR-18
client right before it reaches OpenSearch's `TransportFactory`:

```{code-block} php
use Kinetis\SearchOpenSearch\OpenSearchClientFactory;
use Kinetis\Telemetry\Search\TracingOpenSearchTransport;
use Psr\Http\Client\ClientInterface;

$client = OpenSearchClientFactory::fromConfig(
    $config,
    transportDecorator: static fn (ClientInterface $inner): ClientInterface
        => new TracingOpenSearchTransport($inner, $app->get(TracerProviderInterface::class)),
);
```

OpenSearch's REST API is path-based (`POST /orders/_search`,
`GET /orders/_doc/42`), so each span is named from the request's method
and the action its path performs (`POST _search`, `GET _doc`) rather
than needing to parse the request body's query DSL. Both halves come
from a fixed vocabulary: a path segment names a span only when it is
one of OpenSearch's own actions, and a path that names none — or names
one this package does not list — produces `request` instead. The rest
of such a path is index names, aliases and document ids, which say
which records a call touched rather than what it did, so the path
travels only as `kinetis.search.path_fingerprint`.

PSR-18's `sendRequest()` always hands back a complete response, so
unlike the outgoing-HTTP decorator above there is no deferred span
lifecycle here — the span starts and ends around one call.

(telemetry-data-minimization)=

## What never reaches a span

A trace is exported to a third-party backend, retained there, and
readable by everyone with access to it — a wider audience than the
database, cache, or upstream service an operation's input was addressed
to. So a span here describes an operation and never the data it
carried. Every decorator and hook in this package routes an operation's
inputs through one internal policy point, and there is no setting that
turns it off: a switch for the raw value would put the choice in a
configuration file, where the consequence of getting it wrong is a
credential sitting in an APM backend.

| Never exported | Exported instead |
|---|---|
| A SQL statement, its literal values, its bound parameters | The opening keyword from a fixed vocabulary, `kinetis.db.query_fingerprint`, `kinetis.db.parameter_count` |
| A cache key, single or batched, and every cached value | `kinetis.cache.key_fingerprint` over the operation's key list, `db.operation.batch.size` for the multi-key methods |
| A URL's userinfo, path, query string, and fragment | `url.scheme`, `server.address`, `server.port`, `kinetis.http.url_fingerprint` |
| An incoming request's path or query string | `http.request.method`, and `http.route` on the `route.match` span once the router resolves a template |
| An OpenSearch index name, document id or alias | The action from a fixed vocabulary as the span name and `db.operation.name`, `kinetis.search.path_fingerprint` |
| A session id, and the session payload | `kinetis.session.id_fingerprint` |
| A failure's message and stack trace | The exception's type — its own class, or an anonymous subclass's nearest named ancestor — as the span status description and as an `exception` event's `exception.type` |

A fingerprint is a 128-bit SHA-256 prefix, written as 32 hex
characters. Two spans covering the same statement, key list, URL or
path carry the same one, so a backend groups them exactly as it would
have grouped the raw value, and neither span carries the value. A
digest covers the kind of input as well as the input itself, so one
byte sequence arriving as a cache key and as a URL fingerprints
differently in each place, and the two can never be joined by comparing
digests. It is pseudonymous correlation data rather than a secret: the
digest is unkeyed, so anyone holding a candidate value can confirm it
by hashing it, and a value drawn from an enumerable set stays
guessable. What the fingerprint guarantees is that the value is absent
from the trace.

Every span name, and every attribute that says what an operation did,
comes from a closed vocabulary for a second reason on top of that one —
a name assembled from caller-supplied text is both an export of that
text and an unbounded number of distinct names for a backend to group.
A statement opening outside the SQL keyword list is named `SQL`, a
method outside the HTTP method list is `HTTP` on the name and `_OTHER`
on `http.request.method`, and an OpenSearch path naming no known action
is `request`.

`url.scheme`, `server.address` and `server.port` are the one exported
shape that is open-ended rather than drawn from a vocabulary. They name
which service a call was addressed to — a deployment's own topology
decides those — while everything the call said to that service stays
behind, so what they add to a backend's grouping is the number of hosts
an application talks to.

The exception rule is the one that reads as a loss and is not. A
driver's error message quotes the statement it rejected and the value
that caused the rejection; a client's quotes the URL it could not
reach; a stack trace carries the arguments each frame was called with.
The type that does travel is a name PHP declared rather than a
location: an anonymous subclass is named after the file and line it was
declared at, so a span carries its nearest named ancestor instead.
The exception still propagates unchanged, so an application that wants
the message logs it where its own redaction policy applies — and
`TraceAwareLogger`, below, puts the trace id on that log line, which is
what joins the two back together. Core makes the same call for the same
reason when it reports a contained backend failure, described under
"A failing backend never changes what the application does".

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
  hydration per DTO, `Controller::method`, and `response.encode`: the
  time between a request span and its query spans is attributed to
  these, not left as an unnamed gap.
- **Queries, split at the pool boundary** — a span per query from
  inside the drivers, carrying the keyword and statement fingerprint
  the SQL decorator carries and no more, with a `server.started` event
  marking the moment it actually went to the server: everything before
  that event is time spent waiting for a free pooled connection, the
  number that is invisible from outside.
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
no OTLP endpoint configured elsewhere, or drop them. The cache, session,
and OpenSearch decorators have no hook equivalent — they're the only
source of tracing for those three, not a second copy of anything.

### A failing backend never changes what the application does

`Kinetis\Instrumentation\Telemetry` — the holder every hook call site
above actually calls — is a no-throw boundary. Every call into the
installed backend is caught: a void hook (an end hook, `phase()`)
completes normally on a backend failure instead of propagating it; a
token-returning start hook (`routeMatchStarted()`, `queryDispatched()`,
`jobPushStarted()`, and the rest) returns `null`, the same sentinel a
real backend's own end hook already tolerates when nothing started;
`jobPushMetadata()` falls back to an empty carrier. `swap()` itself is
plain configuration, not a backend call, and is never guarded.

This matters because a hook call sits inside real control flow, not
beside it — `Kernel`/`Dispatcher` call an end hook from inside a
`catch`, `concurrently()`'s batch/task hooks wrap a fiber-pooled task,
and a queue producer's `push()` calls its ending hook right after a
durable send has already succeeded. An unguarded backend failure in any
of those positions would replace the real controller exception, corrupt
`concurrently()`'s own completion bookkeeping, or make a producer report
a job as failed — and therefore worth retrying — after it has already
been sent once. None of that can happen: the worst a broken telemetry
backend can do is stop producing telemetry.

A contained failure is still reported once, to `error_log()`, naming
only the hook, the backend's class, and the exception's class — never
the exception's own message and never the hook's own call arguments. A
backend's exception message is not framework-controlled content: it can
legitimately carry SQL text, a job's metadata, a credential, or a
controller argument the backend included while describing its own
failure, so none of it is safe to write to a shared log. The diagnostic
call is itself wrapped so it can never become a second failure.

## What stays out of scope

The OTel *metrics* signal — counters and gauges exported on their own
schedule — is deferred: a periodic exporter needs a timing shape that
fits a worker's idle periods, and the per-request `php.memory.usage`
span attribute already covers the leak-detection case that matters
most. Business metrics are the application's own concern through OTel's
API directly; this package instruments what the framework owns and
stops there.

## See also

- {doc}`logging` — PSR-3 logging, which `TraceAwareLogger` decorates to
  correlate log lines with the span they happened in.
- {doc}`performance-tuning` — what to do with a trace once it shows where
  a request spends its time.
- {doc}`concurrency` — why concurrent tasks appear as overlapping spans,
  and what that looks like when they do not.
- {doc}`queue` — trace propagation across a queue, so a job's spans join
  the request that pushed it.
- {doc}`persistence` — `RedisSimpleCache`/`ClusteredRedisSimpleCache`,
  what `TracingSimpleCache` wraps.
- {doc}`session` — the store interface and drivers `TracingSessionStore`
  wraps, and the `bootstrap.php` rebind pattern it reuses.
- {doc}`search-opensearch` — `OpenSearchClientFactory`'s own
  `transportDecorator` seam, what `TracingOpenSearchTransport` plugs
  into.
