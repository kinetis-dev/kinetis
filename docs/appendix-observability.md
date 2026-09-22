# Appendix: Observability Reference

The mechanics behind {doc}`telemetry`: when spans are exported and what
an export can block, how span scopes cross Fibers, each span's lifecycle,
the framework hook boundary, and how fingerprints and vocabularies are
built. Configuration, the spans an application gets, and the privacy
rules an application relies on are in {doc}`telemetry`.

## Export

`kinetis/telemetry` sends OTLP/HTTP to `OTEL_EXPORTER_OTLP_ENDPOINT` with
`/v1/traces` appended, through a Symfony `Psr18Client` over
`kinetis/revolt-http-client`'s `AmpHttpClientFactory`, so the request
itself suspends the calling Fiber.

OpenTelemetry's batch span processor queues ended spans and exports a
batch when:

- the queue reaches the batch size (512 spans);
- a span ends at least five seconds after the first span in the current
  batch;
- the tracer provider shuts down, which the package registers as a
  shutdown function — request end under PHP-FPM, worker exit under a
  persistent worker.

The export runs inside the `end()` call that triggers it, so the request
ending that span waits for the export — under a bound the package owns.
`TracerFactory` builds the Amp client with `timeout` and `max_duration`
both at 10.0 seconds and creates the transport with `maxRetries: 0`.

The Amp adapter applies `timeout` to the TCP connect and the TLS
handshake and `max_duration` to the transfer. The bound is therefore up
to ten seconds to reach the collector plus up to ten seconds to exchange
the batch — two deadlines, not one strict ten-second total. Both are
needed: Amp reads a transfer timeout of 0 as no timeout, so without
`max_duration` a collector that accepts a request and never answers it
holds the exporting request open until the SAPI's own execution limit
ends it, or indefinitely on the CLI.

`maxRetries: 0` makes one export one wire attempt, so OpenTelemetry's
retry loop — whose delay between attempts is a blocking
`time_nanosleep()` — is never entered. Every failure is terminal on the
first attempt: the exporter logs it and the batch is dropped. A POST
that timed out may already have been stored by the collector, so its
outcome is unknown and resending it could duplicate the spans. Spans
that ended after the dropped batch are unaffected, and each later batch
trigger, including the shutdown flush, pays the same bound again while
the collector stays stalled. None of this is configurable.

The client is also built with `max_redirects` set to 0, so an export
request and its `OTEL_EXPORTER_OTLP_HEADERS` go only to the configured
endpoint. A redirect response is neither a success nor a non-retryable
client error, so it takes that same terminal path and is reported as an
export failure.

The OpenTelemetry SDK also reads `OTEL_RESOURCE_ATTRIBUTES` and
`OTEL_PHP_DETECTORS` from the process environment directly, not through
Kinetis configuration. The package merges its own resource over the
SDK's, so `OTEL_SERVICE_NAME` from Kinetis configuration wins over a
`service.name` set there.

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

A resident Fiber runs later tasks and later requests, so a scope must be
detached before the task that opened it returns — see
{ref}`runtime-reference-fibers`.

## Span lifecycles

### Request spans

`RequestSpanMiddleware` is global middleware, and its span is active
while the request runs, which is what parents every other span under it.
It extracts an incoming `traceparent` against the root context rather
than whatever the Fiber carries. The route template belongs to the
router, which runs inside the handler this middleware wraps, so the
template surfaces on the `route.match` child span as `http.route`.

### Query and transaction spans

Query spans are never activated. They read whichever span is active on
their own Fiber as their parent — the request span, or the task span
when the query runs inside a `concurrently()` task — and end
immediately, so overlapping queries never interleave that Fiber's scope
stack.

The `server.started` event marks the moment the statement reached the
server. A pooled driver that retries a statement on a fresh connection
reports it again, so a span can carry more than one. The hook that
dispatches a statement receives the system and the SQL text and no bound
parameters, so nothing downstream of it can export a parameter value.

A transaction span records `db.transaction.outcome` from what the server
confirmed: `commit` for an acknowledged `COMMIT`, `rollback` for an
acknowledged `ROLLBACK`, and `unknown` for everything else — a lost or
discarded connection, a finish nothing answered, a transaction the
server ended on its own.

### Queue spans

The push hook hands the backend a `traceparent` carrier, and the backend
stores it with the job:

| Backend | Where the carrier travels |
|---|---|
| `kinetis/queue-redis` | the JSON payload's `metadata` field |
| `kinetis/queue-sql` | the `metadata` column |
| `kinetis/queue-rabbitmq` | an AMQP message header |
| `kinetis/queue-sqs` | an SQS message attribute |

The worker's consumer span parents to that carrier and is active while
the job's `handle()` runs, so queries and HTTP calls inside it nest under
it.

A settlement the backend rejects as stale (see {doc}`queue`'s "When a
settlement is lost") still closes the span, carrying the attempted
outcome and the lost delivery as the recorded failure — an unclosed span
would be worse than one whose recorded exception is the lost delivery
rather than a job failure.

A worker killed without graceful shutdown (see {doc}`queue` on
`ext-pcntl`) loses whatever span batch it had not yet exported — the
flush runs on shutdown, and a hard kill never reaches it.

### Outgoing HTTP spans

Requests through the Revolt transport return immediately and complete
later, so a `TracingHttpClient` span ends when the response is consumed —
its content read, decoded, cancelled, failed, or destroyed — rather than
when `request()` returns or a status code is read.

`Http::withRetries()` retries above the transport, so each attempt
reaches the decorator separately and gets its own span; the failure that
triggered a retry stays visible. The wrapped client is handed the URL and
the method exactly as written.

### Cache spans

`TracingSimpleCache` records `db.system.name` as `redis` whatever cache
it wraps. `clear()` names no keys and carries no key fingerprint, and
neither does a multi-key call with an empty key list.

### Search spans

Each engine factory hands back the real, unwrapped engine client, so
tracing plugs in at the transport decorator seam {doc}`appendix-search`
describes, wrapping the fully configured PSR-18 client right before the
engine's own transport is built around it. `SearchSystem` is what tells
the two engines' spans apart.

The transport owns the connection pool, so one traced transport serves
every Elasticsearch client the worker builds over it. The package's own
untraced transport, built while it registered, holds no connection and is
left with nothing resolving through it.

Both engines answer a path-based REST API (`POST /orders/_search`,
`GET /orders/_doc/42`), so a span is named from the request's method and
the action its path performs (`POST _search`, `GET _doc`) rather than
from the request body's query DSL. Both halves come from a fixed
vocabulary: a path segment names a span only when it is one of the
engines' own actions, and a path that names none — or one this package
does not list — produces `request` as the action (`GET request`). The
rest of such a path is index names, aliases and document ids, which say
which records a call touched rather than what it did, so the path travels
only as `kinetis.search.path_fingerprint`.

`kinetis/search`'s adapter reads the status, headers and body before it
returns, so unlike the outgoing-HTTP decorator there is no deferred span
lifecycle — the span starts and ends around one call, and a failure part
way through a response body falls inside it.

## Framework hooks

The decorators wrap boundaries from outside; the hooks report from inside
the framework, which is where the request-pipeline, query and queue spans
come from. Core, the SQL clients `kinetis/database-bridge` builds, and
the queue packages report named moments through
`Kinetis\Instrumentation\TelemetryInterface` — a no-op until this
package's bootstrap swaps in its OpenTelemetry backend:

- **Boot phases** — `bootstrap.env`, `bootstrap.services`, and, on a
  development boot, `bootstrap.discovery`: measured by
  `Kinetis\Runtime\HttpStartup` with plain timestamps and reported once a
  backend exists. Under boot-and-die runtimes these appear per request;
  under a worker, once per boot.
- **The request pipeline** — a span per middleware layer, `route.match`
  carrying the matched template as `http.route`, hydration per DTO,
  `Controller::method`, and `response.encode`: the time between a request
  span and its query spans is attributed to these rather than left as an
  unnamed gap.
- **Queries and transactions** — reported from inside the drivers of the
  clients `kinetis/database-bridge` builds.
- **`concurrently()`** — a span for the batch and one per task, so
  overlap is visible even for tasks that aren't queries or HTTP calls.
  The batch hook hands its own token to each task hook, which is what
  parents a task to its batch across the Fiber boundary.
- **Events and listeners, MCP tool calls and resource reads** — each a
  named span pair.
- **Queue push and worker jobs** — the producer and consumer spans,
  carrying the trace context that joins them.

The interface is not a consumer extension point — an application reads
this data from its tracing backend rather than implementing it;
`TelemetryInterface`'s own docblock states why.

### A failing backend never changes what the application does

`Kinetis\Instrumentation\Telemetry` — the holder every hook call site
calls — is a no-throw boundary. Every call into the installed backend is
caught: a void hook (an end hook, `phase()`) completes normally on a
backend failure instead of propagating it; a token-returning start hook
(`routeMatchStarted()`, `queryDispatched()`, `jobPushStarted()`, and the
rest) returns `null`, the same sentinel a real backend's own end hook
already tolerates when nothing started; `jobPushMetadata()` falls back to
an empty carrier. `swap()` itself is plain configuration, not a backend
call, and is never guarded.

A hook call sits inside real control flow, not beside it —
`Kernel`/`Dispatcher` call an end hook from inside a `catch`,
`concurrently()`'s batch and task hooks wrap a pooled task, and a queue
producer's `push()` calls its ending hook right after a durable send has
already succeeded. An unguarded backend failure in any of those positions
would replace the real controller exception, corrupt `concurrently()`'s
completion bookkeeping, or make a producer report a job as failed — and
therefore worth retrying — after it has already been sent once.

A contained failure is reported once, to `error_log()`, naming only the
hook, the backend's class, and the exception's class — never the
exception's message and never the hook's call arguments. A backend's
exception message is not framework-controlled content: it can carry SQL
text, a job's metadata, a credential, or a controller argument the
backend included while describing its own failure. The diagnostic call is
itself wrapped so it can never become a second failure.

## Fingerprints and vocabularies

Every decorator and hook in this package routes an operation's inputs
through one internal policy point, `Kinetis\Telemetry\Redaction`, and no
setting turns it off: a switch for the raw value would put the choice in
a configuration file, where the consequence of getting it wrong is a
credential sitting in an APM backend.

A fingerprint is a 128-bit SHA-256 prefix, written as 32 hex characters.
Two spans covering the same statement, key list, URL or path carry the
same one, so a backend groups them exactly as it would have grouped the
raw value. The digest covers the kind of input as well as the input
itself, so one byte sequence arriving as a cache key and as a URL
fingerprints differently in each place, and the two can never be joined
by comparing digests. It is pseudonymous correlation data rather than a
secret: the digest is unkeyed, so anyone holding a candidate value can
confirm it by hashing it, and a value drawn from an enumerable set stays
guessable. What the fingerprint guarantees is that the value is absent
from the trace.

Every span name, and every attribute that says what an operation did,
comes from a closed vocabulary for a second reason — a name assembled
from caller-supplied text is both an export of that text and an unbounded
number of distinct names for a backend to group. A statement opening
outside the SQL keyword list is named `SQL`, a method outside the HTTP
method list is `HTTP` on the name and `_OTHER` on `http.request.method`,
and a search path naming no known action is `request`.

`url.scheme`, `server.address` and `server.port` are the one exported
shape that is open-ended rather than drawn from a vocabulary. They name
which service a call was addressed to — a deployment's own topology
decides those — while everything the call said to that service stays
behind, so what they add to a backend's grouping is the number of hosts
an application talks to.

A failure travels as its type alone. A driver's error message quotes the
statement it rejected and the value that caused the rejection; a client's
quotes the URL it could not reach; a stack trace carries the arguments
each frame was called with. The type that does travel is a name PHP
declared rather than a location: an anonymous class is named after the
file and line it was declared at, so a span carries its nearest named
ancestor instead, or `Throwable` when it has none. The exception still
propagates unchanged, so an application that wants the message logs it
where its own redaction policy applies, and `TraceAwareLogger` puts the
trace id on that log line.

## See also

- {doc}`telemetry` — configuration, the spans an application gets, and
  what never reaches a span.
- {doc}`appendix-runtime` — Fiber reuse and the scheduling these scope
  rules follow.
- {doc}`appendix-search` — the transport decorator seam search spans use.
- {doc}`appendix-queue` — how each backend stores job metadata.
