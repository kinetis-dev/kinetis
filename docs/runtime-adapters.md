# Runtime Adapters

Kinetis runs unmodified on four different kinds of PHP hosting, and picks
the right one automatically — you don't configure this yourself:

```{code-block} php
$adapter = Kinetis\Runtime\RuntimeDetector::detect();
```

| Deployment | What Kinetis does |
|---|---|
| FrankenPHP (worker mode) | One long-running process serves request after request — Kinetis's primary target. |
| Plain PHP-FPM | The classic model: one request in, one response out, then the script ends. Fully supported, not an afterthought. |
| AWS Lambda (via Bref) | A separate install, `kinetis/bref-adapter` — see below. |
| RoadRunner | A separate install, `kinetis/roadrunner-adapter` — see below. |

`public/index.php` calls `RuntimeDetector::detect()` once, and the exact
same file works correctly under all four — nothing in your application
code needs to know or care which one is actually running it.

## Running under FrankenPHP

This is the deployment Kinetis is built around: a single PHP process that
boots once and serves thousands of requests, keeping everything warm
between them. A complete `Caddyfile`:

```{code-block}
:caption: Caddyfile

{
    admin off
}

:8080 {
    root * public
    php_server {
        worker public/index.php
    }
}
```

```{code-block} bash
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app dunglas/frankenphp:latest \
    frankenphp run --config Caddyfile
```

```{note}
Worker mode keeps `public/index.php` — including route discovery —
loaded in memory across every request it serves, so editing a controller
while the container runs has no effect until you restart it: PHP cannot
redeclare a loaded class with new content. While you are editing code
constantly, PHP-FPM's boot-and-die model rebuilds this on every request
instead, and Kinetis falls back to it automatically with no code change.
```

```{warning}
**A deployment gotcha worth knowing about:** Caddy's `php_server`
directive falls back to classically re-executing `index.php` for any
request path that doesn't match a real static file, *before* it ever
routes to a configured worker. The `worker` directive in your `Caddyfile`
must point at that **same** `index.php` — pointing it at a different
script means every request silently keeps falling through to the classic
fallback, never once reaching your worker, with no error to indicate why.
```

### Sizing FrankenPHP's worker threads

FrankenPHP's `worker` directive accepts an explicit thread count:

```{code-block}
:caption: Caddyfile

worker {
    file public/index.php
    num 64
}
```

or the shorthand form, `worker public/index.php 64`. Left unset, it
defaults to roughly **2x your available CPU cores** — a number tuned for
CPU-bound work, not for the kind of I/O-bound workload (database calls,
outbound HTTP requests) most real applications actually spend most of
their time on.

**This number matters more than it might look, and it's easy to
mistune in both directions.** Each worker thread processes exactly one
HTTP request at a time, start to finish — `frankenphp_handle_request()`
is a blocking call that only returns once that request's response has
been fully sent, then picks up the next one. Kinetis's own
`Kinetis\Async`/`concurrently()` layer (see {doc}`concurrency`) provides
real, genuine concurrency *within* one request's own work — but it
doesn't change this: a thread that's mid-request, even one suspended on
a Fiber waiting for a database response, isn't available to pick up a
second, unrelated incoming request. Cross-request concurrency is bounded
by thread count here, the same way it's bounded by PHP-FPM's own
worker-process count under that adapter — not something Kinetis's async
layer can substitute for.

Which direction to tune depends on what your requests actually wait on:

- **Requests dominated by genuine waiting** — slow queries, remote APIs,
  anything where the thread sits idle for tens of milliseconds — want
  `num` well above the core count, closer to expected concurrent request
  volume. Undersizing here doesn't produce errors; it produces queueing
  that looks, from the outside, exactly like the application being slow.
- **Requests mixing CPU with fast queries** — the common case with
  `kinetis/persistence`'s native drivers, where each query is
  sub-millisecond but a request still spends real wall time suspended
  across its fan-out — want `num` **moderately above the core count**,
  around 2–3×. On an 8-vCPU host against a sub-millisecond database,
  20 threads outperform 8 on every database-touching route (a
  20-query fan-out by ~10%, single-query routes by ~9%) with no loss
  on CPU-pure routes. Go far beyond that and two costs take over:
  context-switch overhead, and — usually first — the per-thread
  database pool budget below.

Either way: measure under realistic load rather than guessing — the two
regimes want opposite corrections, and which one you're in is a property
of your routes, not of the framework.

The same "each worker thread is its own independent execution context"
fact has a second, sharper consequence if you're using
`kinetis/persistence`'s `SqlConnectionFactory`: `bootstrap.php` runs once
*per worker thread*, so each one builds its own separate database
connection pool. Oversizing `num` without correspondingly *undersizing*
each pool's `maxConnections` can exhaust your database's own connection
limit — see {doc}`persistence`'s "Sizing `maxConnections` under worker
mode" section.

### The default event-loop driver's file descriptor limit

Kinetis's concurrency primitives (see {doc}`concurrency`) run on
Revolt's event loop. Without a driver extension installed, Revolt falls
back to a driver backed by the C `select()` system call, which can only
track file descriptors *numbered* up to 1024 — a fixed ceiling, not
something raised by configuration.

Whether that ceiling can bite depends on what the loop actually
watches. The native MySQL driver watches no file descriptors at all
(mysqli exposes none; it bridges via polling), so a MySQL-only
deployment never hits *this* ceiling — though mysqli's own polling
carries a separate select()-based limit that no loop extension lifts;
see {doc}`performance-tuning`'s "mysqli's poll limit" for the
constraint and the boot-time pool warming that addresses it. The native Postgres driver, the Redis
client, and the HTTP client all register real socket watchers — and
under FrankenPHP the embedded Go server's client sockets share the same
process-wide fd table, pushing fd *numbers* past 1024 under load even
with few PHP worker threads. Any deployment in that second group should
install one of Revolt's supported extensions — `ext-uv`, `ext-ev`, or
`ext-event` — each backed by an OS-native mechanism (epoll on Linux)
with no fd-number ceiling. Revolt selects whichever is available
automatically, with no application code to change.

This is a correctness concern, not a performance one — measured
throughput is identical across drivers for typical workloads; what the
extensions buy is not being at the mercy of fd numbering. `ext-event`
has the smoothest install story on current PECL (`pecl install event`);
`ext-uv` works too but its only release must be pinned explicitly
(`pecl install uv-0.3.0` — PECL refuses non-stable packages by
default).

## Running under PHP-FPM

Nothing to configure — Kinetis detects a plain PHP-FPM environment
automatically and falls back to it whenever FrankenPHP isn't available.
Every request reruns the whole `public/index.php` script from scratch,
since PHP-FPM doesn't keep anything in memory between requests. See
{doc}`caching` for what changes about that in production, and why it
matters more here than under FrankenPHP.

One setting matters for a streamed response (an MCP progress stream,
any `StreamedResponse`): nginx buffers a FastCGI response by default and
delivers it whole once the script ends, which turns a stream into a
delayed lump. Set `fastcgi_buffering off;` in the location that proxies
to PHP-FPM — or have the response carry `X-Accel-Buffering: no`. The
conformance suite's FPM run (see {doc}`testing`) fails without it, on
purpose.

Both this adapter and the FrankenPHP one run the shared runtime
conformance suite against their real SAPI in CI — a FrankenPHP worker
behind Caddy, PHP-FPM behind nginx — not only against the `php -S`
stand-in the committed unit suite uses.

## Running on AWS Lambda

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/bref-adapter
```
````

Once installed, detection picks it up automatically — nothing else to
configure. It needs one extra dependency beyond what core ships with (for
parsing file uploads), which is why it's a separate install rather than
bundled by default.

`Kinetis\BrefAdapter\BrefLambdaAdapter` speaks the Lambda Runtime API
directly (poll `.../invocation/next`, run the request, post the response
to `.../invocation/{id}/response`) and converts to/from API Gateway's
**HTTP API payload format 2.0** event shape — the format a Function URL
or an HTTP API (as opposed to the older REST API) integration sends.
ALB and the older REST API's payload format 1.0 aren't handled.

Every field this depends on is validated before an event is ever routed
— a direct Lambda invocation (not just API Gateway) can carry arbitrary
JSON, so this is checked, not assumed. `"version": "2.0"` is required
and checked explicitly, specifically because it's the one field that
tells a genuine payload-v2 event apart from anything else that happens
to be shaped similarly — a payload-format-1 event carrying a
coincidentally (or deliberately) v2-shaped `requestContext.http` would
otherwise pass a check that only looked at that nested shape.
`rawPath` and `requestContext.http.method` are required as non-empty
strings; every other field this adapter reads (`rawQueryString`,
`headers`, `queryStringParameters`, `body`, `isBase64Encoded`,
`requestContext.http.sourceIp`, `cookies`) is optional but, when
present, is checked for the right type — including the exact
collection shape, not just "is this an array": `headers` and
`queryStringParameters` must each be a genuine JSON *object* with
string values (an array-valued entry, or the field being a JSON list
instead of an object, is rejected), and `cookies` must be a genuine
JSON *list* of strings (a JSON object — `{"session": "abc"}` rather
than `["session=abc"]` — is rejected, not silently accepted as a
one-entry list). This distinction only exists at the raw JSON level:
`json_decode(..., associative: true)` collapses `{}` and `[]`, and an
object-valued and a string-valued map entry, into the identical shape
of plain PHP array — so validation runs against a separate,
non-associative decode of the same body first, which is the only
decode mode where a JSON object and a JSON list actually stay
distinguishable. Anything that fails any of these checks is rejected
outright, reported to the Runtime API's invocation error endpoint —
never silently degraded into a plausible-looking request built from
whichever fields happen to be missing or malformed.

### What's mapped, and how

- **Method, path, query string, and headers** — straight from the
  event's own `requestContext.http.method`/`rawPath`/`rawQueryString`/
  `headers`. A purely-numeric header name (`"123"`, valid per RFC 9110 —
  digits are ordinary token characters) is mapped correctly: PHP's own
  `json_decode(..., associative: true)` coerces a canonical-integer JSON
  object key into a real PHP int array key, so it's cast back to a
  string before reaching PSR-7's `withHeader()`, which requires one.
  `queryStringParameters` has the identical coercion and no equivalent
  fix — PHP always coerces a canonical-integer string used as an array
  *key* back to int, regardless of any cast applied first, unlike a
  genuine function-argument cast — but this is harmless rather than
  disclosed as a gap: `withQueryParams()` never rejects an int key the
  way `withHeader()` rejects an int argument, and PHP's own array-lookup
  semantics coerce a numeric-string *read* the identical way, so
  `$request->getQueryParams()['123']` still finds the value regardless
  of which key type is actually stored.
- **Cookies** — payload format 2.0 carries these as their own top-level
  `cookies: string[]` list, never folded into `headers`. Reconstructed
  into a real `Cookie` header and into `getCookieParams()`, so cookie-
  and session-based authentication (see {doc}`session`) works the same
  as it does under FrankenPHP or FPM.
- **The client's IP address** — `requestContext.http.sourceIp` is
  mapped to the request's `REMOTE_ADDR` server parameter. Nothing else
  here has one: every invocation arrives over the Runtime API, not a
  socket PHP itself accepted, so without this every request would look
  identical to code reading `REMOTE_ADDR` (`RateLimitMiddleware`'s
  identifier for one — see {doc}`middleware` — and any per-client
  logging).
- **The request body** — a base64-encoded body (`isBase64Encoded: true`)
  is decoded strictly: invalid base64 is answered with a `400` rather
  than silently becoming an empty body. `multipart/form-data` and
  `application/x-www-form-urlencoded` bodies are parsed into
  `getParsedBody()`/`getUploadedFiles()` the same way core's own
  adapters parse a form body, and a multipart body with no usable
  boundary gets the same `400` — the identical response, with the
  identical fixed message, `SuperglobalsBridge` gives a body
  `request_parse_body()` rejects under FrankenPHP or FPM. Either way
  the real reason is logged, never returned, and the handler never
  runs. There is no SAPI here to enforce `post_max_size`: the only cap
  on a form body is Lambda's own invocation payload limit (6 MB).
  `MaxBodySizeMiddleware`'s cap on a raw JSON body applies as
  everywhere else.
- **The response body** — checked for valid UTF-8 before being handed
  to the Runtime API, which receives the whole response as one JSON
  document. A body that isn't valid UTF-8 (an image, a PDF, any binary
  payload) is base64-encoded and `isBase64Encoded: true` is set on the
  payload — API Gateway decodes it again on the way out. A body that's
  already valid UTF-8 is sent as-is.
- **Response cookies** — every `Set-Cookie` header value is emitted as
  its own entry in the payload's `cookies` array, never comma-joined
  with any other `Set-Cookie` value into one header. This matters
  because a cookie's own attributes (`Expires`, in particular) already
  contain a comma, so folding two cookies together the way ordinary
  repeated headers are folded here would produce a value no client
  could parse back into distinct cookies.

### What isn't supported

- **Response streaming.** The Runtime API's poll/respond contract is
  strictly one invocation → one response payload; a controller
  returning a `Kinetis\Runtime\StreamableResponseInterface` throws
  immediately rather than silently buffering or dropping the stream.
  Real Lambda response streaming needs a Function URL configured with
  `InvokeMode: RESPONSE_STREAM`, a different invocation model this
  adapter doesn't implement.
- **ALB and REST API (payload format 1.0) events.** Only the HTTP API's
  format 2.0 shape is understood — see the event-validation paragraph
  above for exactly what's checked and how an unsupported or malformed
  event is reported.
- **A Runtime API the adapter can't reach.** A poll or a response POST
  that fails outright (connection refused, a non-2xx status) throws
  instead of being treated as an empty response — there is no
  invocation to serve and nothing meaningful to fall back to, so
  surfacing the failure (visible in CloudWatch as the function
  erroring) is the correct outcome rather than continuing silently.

## Running under RoadRunner

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/roadrunner-adapter
```
````

Once installed, detection picks it up automatically from `RR_MODE`, the
environment variable RoadRunner's own `rr serve` sets when it spawns
the worker — nothing else to configure to be found. It needs two extra
dependencies beyond what core ships with (RoadRunner's own PHP worker
library, and the same form-body parser `kinetis/bref-adapter` uses),
which is why it's a separate install rather than bundled by default.

`Kinetis\RoadRunnerAdapter\RoadRunnerAdapter` speaks RoadRunner's own
Goridge/`PSR7Worker` protocol — a persistent worker loop, structurally
the closest of the four to FrankenPHP's, but built on RoadRunner's own
PHP library rather than a raw request-handling function.

**Two RoadRunner configuration settings are required**, not optional:

```{code-block} yaml
version: "3"

server:
  command: "php public/index.php"

http:
  address: 0.0.0.0:8080
  raw_body: true
  max_request_size: 10
```

`http.raw_body: true` — confirmed directly against RoadRunner's own Go
source, not assumed: without it, RoadRunner parses
`multipart/form-data`/`application/x-www-form-urlencoded` bodies itself,
in Go, before the PHP worker is ever invoked, and a body it can't parse
never reaches PHP at all — the client gets RoadRunner's own error
response instead of this framework's `400`/JSON shape. Setting it
disables that Go-side parsing entirely, so every body — well-formed or
not — reaches this adapter's own parser untouched, the same reason
`kinetis/bref-adapter` needs one: a request body here is one in-memory
string with no live `php://input` stream behind it, so PHP 8.4's
`request_parse_body()` (what `FrankenPhpAdapter`/`FpmAdapter` use for
the same problem) can't help.

A misconfigured `raw_body` doesn't fail silently: `RoadRunnerAdapter`
detects the resulting Go-side pre-parsed body (a real attribute
RoadRunner's own `PSR7Worker` stamps on every request) and reports it as
a clear configuration error naming `http.raw_body: true`, rather than
re-parsing an already-parsed body and silently producing wrong fields.

### `http.max_request_size` is the real defense against an oversized body

`raw_body: true` above means neither `upload_max_filesize`/
`post_max_size` (no SAPI here to enforce them) nor
`MaxBodySizeMiddleware` (a `multipart/form-data`/
`application/x-www-form-urlencoded` body is parsed by this adapter
*before* the Kernel's own middleware pipeline ever runs — see
{doc}`middleware`) bound how large a form body can be. Left unset,
RoadRunner's own default is a generous 1000 MB — confirmed directly
against its Go source, not assumed — which is real but not a sane
production limit on its own.

**Set `http.max_request_size` explicitly** (real megabytes, RoadRunner's
own unit — `10` above is 10 MB, matching a typical small-upload API; size
it to what your application actually needs). This is enforced in Go,
wrapping the request in a real `http.MaxBytesReader` before your PHP
worker is ever invoked — the only place a body with no declared
`Content-Length` at all (a genuinely chunked request) can be bounded,
since by the time this adapter's own code runs, RoadRunner has already
handed it the whole body as one in-memory string with nothing left to
read incrementally.

This is a separate ceiling from `MAX_BODY_SIZE` (below), and the two
don't automatically agree: the example's `max_request_size: 10` allows
up to 10 MB through to PHP, but `MAX_BODY_SIZE` still defaults to 2 MiB,
so a form body between those two sizes reaches PHP and is then rejected
there instead of at the Go layer. Either is a real rejection — nothing
gets silently accepted — but if you want one consistent limit, set both
to match (`MAX_BODY_SIZE=10485760` alongside `max_request_size: 10`).

`RoadRunnerAdapter` itself adds one more, narrower check on top: a
form body whose *declared* `Content-Length` already exceeds
`MAX_BODY_SIZE` (the same env var and default — 2 MiB — `MaxBodySizeMiddleware`
uses) is rejected with a `413` before this adapter's own multipart/
url-encoded parser ever runs — the whole body has already been handed
to PHP as one in-memory string by this point (see above), so what this
check actually prevents is spending time copying and parsing something
already known to be too large, not the initial read itself. This is
defense in depth for the common, honestly-labeled case — not a
substitute for `http.max_request_size`, which is what actually bounds
the case this check can't see: a request that lies about its length, or
declares none.

### `ext-sockets` under an Alpine-based image

`spiral/roadrunner-worker` hard-requires PHP's `sockets` extension.
Alpine's own `$PHPIZE_DEPS` build-tools set is not enough on its own —
`docker-php-ext-install sockets` fails there with a missing
`linux/sock_diag.h` — but adding `apk add linux-headers` alongside it
closes the gap; confirmed directly, not assumed, against both
`php:8.3-cli-alpine` and `php:8.4-cli-alpine`:

```{code-block} dockerfile
FROM php:8.4-cli-alpine
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
 && docker-php-ext-install sockets
```

This package's own CI deliberately does *not* do this — every step of
its Alpine-based checks (install, PHPStan, Psalm, the committed unit
suite) runs in its own separate, stateless container, none of which
ever load `ext-sockets` at runtime, so compiling it from source
repeatedly would be pure cost with nothing to show for it; Composer's
platform check is bypassed there instead. A real deployment image is
the opposite case — one build, reused for the worker's whole
lifetime — where the cost above is paid once and is worth it.

### A crash in one request doesn't take the worker down

Unlike `FrankenPhpAdapter`, which lets an uncaught exception propagate
and end the worker process, `RoadRunnerAdapter::run()` catches it,
reports it to RoadRunner via `Worker::error()` (a clean error response
to that one client), and keeps serving requests on the same worker —
confirmed directly, not assumed, by forcing a handler to throw and then
sending another request to the same running process. Letting an
exception propagate here would kill the whole persistent worker over
one bad request, a materially worse failure than any other adapter
risks, since it costs `AppScope`'s warm state until RoadRunner's own
supervisor respawns the worker. If you configure a short
`pool.supervisor.exec_ttl` for other reasons, know that it bounds a
worker's *total* lifetime regardless of this — RoadRunner's own default
is `0s` (unlimited).

### What isn't supported

- **Response streaming.** `Worker::create()`'s default
  `interceptSideEffects: true` installs a global output-buffer redirect
  (`StdoutHandler::register()`) sending every stray `echo`/`header()`
  call to RoadRunner's own log stream instead of the client — required
  to keep the Goridge binary protocol on STDOUT uncorrupted, and the
  reason `Kinetis\Http\StreamedResponse`'s emitter closures can't be
  used here: their output would be silently redirected the same way,
  with nothing erroring anywhere. A controller returning a
  `Kinetis\Runtime\StreamableResponseInterface` gets a real `501`
  instead, after the handler runs — never buffered or dropped silently.
  RoadRunner's own `HttpWorker::respondStream()` is a genuinely
  different, lower-level generator-based API than `PSR7Worker::respond()`,
  and bridging one onto the other needs its own design pass.
- **A purely-numeric header name.** `"123"` is a valid RFC 9110 header
  name (digits are ordinary token characters), and every other adapter
  here maps it correctly — but `spiral/roadrunner-http`'s own request
  decoding drops it before this adapter ever sees the request: PHP
  coerces a numeric string array key to an `int`, and that library's
  `is_string($key)` filter then deletes it. Confirmed by reading its
  source directly, not inferred from the symptom. Recovering it would
  mean reimplementing that library's own JSON/protobuf request decoding
  in this package instead of using `PSR7Worker` — disproportionate to
  how narrow the trigger is. A disclosed, permanent limitation until
  the upstream library fixes it.
- **Cookie order, occasionally.** Every other adapter here preserves
  the exact order a client sent its cookies in. RoadRunner represents
  cookies as a Go `map[string]string` on the way to PHP, and Go
  randomizes map iteration order by design — a request's cookies can
  arrive re-ordered, observed at roughly 1 request in 10 across
  repeated real runs, not deterministic. The values themselves are
  never lost, only their order.

## Writing your own adapter

If you need to target something else entirely, implement this interface
and Kinetis will drive it the same way it drives the four adapters above:

```{code-block} php
namespace Kinetis\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface RuntimeAdapterInterface
{
    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function run(callable $handler): void;

    public function isPersistent(): bool;
}
```

`isPersistent()` tells Kinetis whether to force a memory cleanup pass at
the end of every request — worth doing in a long-running process, pure
waste in one that's about to exit anyway.

Then hold it to the same contract as the built-in ones: implement a
`Kinetis\Testing\Runtime\RuntimeAdapterDriver` for it and extend
`RuntimeAdapterConformanceTestCase` — see {doc}`testing`. Every behavior
the core adapters agree on (how a repeated header folds, where cookies
land, form and binary bodies, response cookies, streaming, the `400` for
a body the environment can't parse — whose fixed message is
`RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`) runs against yours
with no further test code.

You can also construct any adapter directly if you want to force a
specific one instead of relying on automatic detection:

```{code-block} php
$adapter = new Kinetis\Runtime\Adapters\FpmAdapter();
```

## See also

- {doc}`core-concepts` — why your application code never needs to know
  which adapter is running it.
- {doc}`concurrency` — what `Kinetis\Async`/`concurrently()` actually
  provides, and what it doesn't.
- {doc}`caching` — the production build step, and why it matters most
  under PHP-FPM.
- {doc}`appendix` — the exact internals of each built-in adapter.
- {doc}`performance-tuning` — the worker-threads x connections
  budget, what to observe under load, and tuning by workload shape.
