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
inside `concurrently()` tasks, whose own hooks carry the request's
context across the Fiber boundary explicitly. See
{ref}`telemetry-fiber-scopes`.

(telemetry-fiber-scopes)=

## Scope ownership across Fibers

An active span's scope belongs to the Fiber that started it. This
package leaves OpenTelemetry's default Fiber-bound context storage in
place, so two `concurrently()` tasks that overlap in time each keep
their own stack: a span one task activates is neither visible to nor
detachable by the other, whatever order they suspend and resume in.

Parentage across a Fiber boundary is therefore explicit rather than
ambient. `concurrently()` hands each task hook the token its batch hook
returned, and the task span parents to the batch span that token names —
which is what keeps a task, and everything nested inside it, under the
request span the batch itself hangs from. A task that reaches no batch
span roots its own trace instead of joining a sibling's.

The same rule applies wherever a span starts on a Fiber that carries no
context: the request span names the extracted `traceparent` (or the
trace root) as its parent, and a worker's job span names the job's
propagated context, or the trace root for a job carrying none.

## SQL query spans

Nothing to wire: `kinetis/persistence`'s drivers report every statement
they dispatch through the framework's instrumentation hooks (the
section below), and this package's backend turns each report into a
client span as soon as an OTLP endpoint is configured. Every driver is
covered — PDO and native, MySQL and Postgres.

A query span is named by the statement's opening keyword (`SELECT`,
`INSERT`) and carries `db.system.name`, `db.operation.name`, and a
`kinetis.db.query_fingerprint` that groups every execution of the same
statement. A `server.started` event marks the moment the statement
reached the server: everything before it is the wait for a free pooled
connection, the number that is invisible from outside the driver.

The statement itself never travels — see
{ref}`telemetry-data-minimization` — and its bound parameter values are
never handed to a hook at all, so nothing downstream can export them.

A transaction gets a span of its own, from begin to
`COMMIT`/`ROLLBACK`, carrying `db.transaction.outcome`: commit duration
is where fsync cost shows up, which is invisible from the queries
alone. Only what the server confirmed counts as an outcome — `commit`
for an acknowledged `COMMIT`, `rollback` for an acknowledged
`ROLLBACK`, and `unknown` for everything else: a lost or discarded
connection, a finish nothing answered, a transaction the server ended
on its own.

Query spans are never activated. They read whichever span is active on
their own Fiber as their parent — the request span, or the task span
when the query runs inside a `concurrently()` task — and end
immediately, so overlapping queries never interleave that Fiber's scope
stack.

## Queue spans

Nothing to wire here either: the queue backends and `QueueWorker`
report through the same hooks.

`push()` gets a producer span named `{queue} publish`. On the worker
side, a consumer span named `{queue} process` opens when a job starts
and closes when it settles, so its duration is the job's real
processing time. It carries `messaging.destination.name`,
`kinetis.job.class`, `kinetis.job.attempt`, and `kinetis.job.outcome` —
`ack`, `release`, or `fail`, the same vocabulary {doc}`queue` settles a
job by — plus the failure's type and an error status when the job's
`handle()` threw. The consumer span is active while the job runs, so
queries and HTTP calls inside `handle()` nest under it.

Producer and consumer spans are **one trace across processes**. The
push hook hands the backend a `traceparent` carrier, the backend stores
it in the job's payload metadata, and the worker's consumer span
parents to it — however many seconds and processes apart the two are.

A settlement the backend rejects as stale (see {doc}`queue`'s "When a
settlement is lost") still closes the span, carrying the attempted
outcome and the lost delivery as the recorded failure — an unclosed
span would be worse than one whose recorded exception is the lost
delivery rather than a job failure.

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
use OpenTelemetry\API\Trace\TracerProviderInterface;

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

`Http::withRetries()` retries above the transport, so each attempt
reaches this decorator separately and gets its own span — the failure
that triggered a retry stays visible.

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
`RedisSimpleCache` included:

```{code-block} php
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

Each PSR-16 method (`get`, `set`, `delete`, `has`, `clear`,
`getMultiple`, `setMultiple`, `deleteMultiple`) gets its own span,
named by the operation and carrying a
`kinetis.cache.key_fingerprint` over the keys it touched — plus
`db.operation.batch.size` for the three multi-key methods. A cache key
is built from whatever identifies the thing being cached, which is
routinely a user id, a tenant, a session id, or a password-reset token,
so the key is as sensitive as the value and neither travels.

## Session spans

Wraps any `SessionStoreInterface`, {doc}`session`'s file/Redis/SQL
stores included. `SESSION_DRIVER`'s own bindings are lazy factories
resolved on first use, so re-binding in `bootstrap.php` — the same
{ref}`custom-stores` pattern the session package's own docs already
use — replaces it cleanly:

```{code-block} php
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

`read`, `create`, `update`, and `destroy` each get a span. A session id is a
bearer credential — whoever holds it can present the cookie and act as
that session — so it never reaches a span verbatim: its fingerprint
travels instead, enough to correlate every span for one session without
handing a trace reader the credential itself. The payload never travels
at all.

## Search spans

Each engine factory hands back the real, unwrapped engine client, so
tracing plugs in at the `transportDecorator` seam {doc}`search`
describes, wrapping the fully-configured PSR-18 client right before the
engine's own transport is built around it. One decorator serves both
engines; `SearchSystem` is what tells their spans apart:

```{code-block} php
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

That builds one client. An Elasticsearch client must not become a
worker-lifetime binding, for the reason
{ref}`why-the-client-is-short-lived` gives, so tracing that engine
decorates the transport its clients are built over and leaves both
bindings not shared:

```{code-block} php
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

The transport owns the connection pool, so one traced transport serves
every client the worker builds over it. The package's own untraced
transport, built while it registered, holds no connection and is left
with nothing resolving through it.

Both engines answer a path-based REST API (`POST /orders/_search`,
`GET /orders/_doc/42`), so each span is named from the request's method
and the action its path performs (`POST _search`, `GET _doc`) rather
than needing to parse the request body's query DSL. Both halves come
from a fixed vocabulary: a path segment names a span only when it is one
of the engines' own actions, and a path that names none — or names one
this package does not list — produces `request` instead. The rest of
such a path is index names, aliases and document ids, which say which
records a call touched rather than what it did, so the path travels only
as `kinetis.search.path_fingerprint`. `db.system.name` is `opensearch`
or `elasticsearch`, from the `SearchSystem` the decorator was given.

`kinetis/search`'s adapter reads the status, headers and body before it
returns, so unlike the outgoing-HTTP decorator above there is no
deferred span lifecycle here — the span starts and ends around one call,
and a failure part-way through a response body falls inside it.

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
| A SQL statement, its literal values, its bound parameters | The opening keyword from a fixed vocabulary, `kinetis.db.query_fingerprint` |
| A cache key, single or batched, and every cached value | `kinetis.cache.key_fingerprint` over the operation's key list, `db.operation.batch.size` for the multi-key methods |
| A URL's userinfo, path, query string, and fragment | `url.scheme`, `server.address`, `server.port`, `kinetis.http.url_fingerprint` |
| An incoming request's path or query string | `http.request.method`, and `http.route` on the `route.match` span once the router resolves a template |
| A search index name, document id or alias | The action from a fixed vocabulary as the span name and `db.operation.name`, `kinetis.search.path_fingerprint` |
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
on `http.request.method`, and a search path naming no known action
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

The decorators above wrap boundaries from outside; the hooks report
from *inside* the framework itself, which is where the query, queue and
request-pipeline spans above come from. Core, the persistence drivers
and the queue packages report named moments through
`Kinetis\Instrumentation\TelemetryInterface` — a no-op until this
package's bootstrap swaps in its OTel backend, at which point every
report becomes a span with zero configuration beyond the same
`OTEL_EXPORTER_OTLP_ENDPOINT`:

- **Boot phases** — `bootstrap.env`, `bootstrap.services`, and, on a
  development boot, `bootstrap.discovery`: measured by
  `Kinetis\Runtime\HttpStartup` with plain timestamps and reported once a
  backend exists. Under boot-and-die runtimes these appear per request;
  under a worker, once per boot.
- **The request pipeline, opened up** — a span per middleware layer,
  `route.match` (carrying the matched template as `http.route`),
  hydration per DTO, `Controller::method`, and `response.encode`: the
  time between a request span and its query spans is attributed to
  these, not left as an unnamed gap.
- **Queries and transactions** — the SQL spans described above,
  reported from inside the drivers.
- **`concurrently()`** — a span for the batch and one per task, so
  overlap is visible even for tasks that aren't queries or HTTP calls.
  The batch hook hands its own token to each task hook, which is what
  parents a task to its batch across the Fiber boundary.
- **Events and listeners, MCP tool calls and resource reads** — each a
  named span pair.
- **Queue push and worker jobs** — the producer and consumer spans
  described above, carrying the trace context that joins them.

The interface is not a consumer extension point — an application
*reads* this data from its tracing backend rather than implementing it;
`TelemetryInterface`'s own docblock states why.

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
- {doc}`persistence` — the drivers whose query and transaction hooks
  become spans here, and `RedisSimpleCache`, what `TracingSimpleCache`
  wraps.
- {doc}`session` — the store interface and drivers `TracingSessionStore`
  wraps, and the `bootstrap.php` rebind pattern it reuses.
- {doc}`search` — the engine factories' own
  `transportDecorator` seam, what `TracingSearchTransport` plugs
  into.
- {doc}`search-elasticsearch` — why that engine's client is bound per
  resolution, which the wiring above preserves.
