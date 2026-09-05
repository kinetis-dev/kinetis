# Runtime Adapters

Kinetis runs unmodified on four different kinds of PHP hosting, and picks
the right one automatically — you don't configure this yourself:

```{code-block} php
$adapter = Kinetis\Runtime\RuntimeDetector::detect(
    Kinetis\Http\Form\FormLimits::fromConfig($config),
    Kinetis\Http\TrustedProxies::fromConfig($config),
);
```

Both arguments are the application's own policy, built once from its
`Config`: how many bytes a request body may carry, and whose forwarded
headers may decide a request's scheme and client address. They are
required because an adapter bounds and parses a body before the Kernel or
its container exist, so it cannot resolve them and must not invent them —
a ceiling an adapter guessed at is a ceiling the application never
configured. `public/index.php` registers both on `AppScope` before the
bootstrap chain runs, so `bootstrap.php` or a package bootstrap can
replace either one, and reads them back out after `boot()` to hand here —
whatever the container settled on is the instance the adapter bounds this
request by and the instance `MaxBodySizeMiddleware` enforces inside the
Kernel.

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
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app \
    -v "$PWD/docker/kinetis.ini":/usr/local/etc/php/conf.d/zz-kinetis.ini:ro \
    dunglas/frankenphp:latest frankenphp run --config Caddyfile
```

```{code-block} ini
:caption: docker/kinetis.ini

enable_post_data_reading=0
```

That one setting is required, not tuning — see "Form bodies: one contract
under every runtime" below for what it does and why the bridge refuses to
run without it. `kinetis/skeleton` and `kinetis/pingpong` ship exactly
this file, copied into their images.

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
automatically and falls back to it whenever none of the other three
runtimes' own signals (FrankenPHP, RoadRunner, Lambda) are present.
Every request reruns the whole `public/index.php` script from scratch,
since PHP-FPM doesn't keep anything in memory between requests. See
{doc}`caching` for what changes about that in production, and why it
matters more here than under a persistent worker (FrankenPHP or
RoadRunner).

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

## Form bodies: one contract under every runtime

A `multipart/form-data` or `application/x-www-form-urlencoded` body is
parsed before the Kernel exists, so `MaxBodySizeMiddleware` never sees
one. `Kinetis\Http\Form` is what handles it instead — the same classes,
the same ceilings and the same refusals under all four adapters, because
all four hand the same raw bytes to the same parser.

```{important}
The two SAPI adapters require **`enable_post_data_reading=0`**.
`Kinetis\Runtime\SuperglobalsBridge` refuses to serve a request without
it, with a message naming the setting, rather than running on a body PHP
already consumed.

Left at its default, PHP reads and parses the body before any Kinetis
code exists: it populates `$_POST`/`$_FILES` for a POST form, empties
`php://input` doing so, drops everything past `max_input_vars` with only
a warning, and answers a body over `post_max_size` with an empty `$_POST`
and no error at all. None of that is observable afterwards — a form
truncated to its first 1000 fields is indistinguishable from a form that
had 1000 fields. With the setting off, `php://input` carries the whole
body for every method including POST, and Kinetis bounds and parses it
itself.

Set it in the container's `php.ini`, an `.htaccess`, or the FPM pool
config; it is `PHP_INI_PERDIR`, so it cannot be set from application
code. `request_parse_body()` is not used and cannot be: it reads the same
input stream, so it returns an empty form to anything that read the body
first — which makes "count the raw bytes, then let PHP parse them"
impossible rather than merely awkward.
```

```{important}
Every adapter, SAPI or not, requires **`arg_separator.input=&`** — its
default, and the only value `Kinetis\Http\Form\FormPairs` will parse a
body under.

`parse_str()` splits a body on whatever that setting names, which is a
set of characters rather than a single one. Every count, name and depth
taken here is read by splitting on `&`, so any other value parses a
different form from the one that was measured. Set to `;`, a body of
`a=1&b=2&…` becomes one field whose value is the rest of the request.
Set to `&;`, a body of `a=1;b=2;…` is one pair to the count and as many
as the client likes to the parser — past the ceilings, then cut back to
this runtime's own `max_input_vars` in silence. Anything but exactly `&`
is refused before `parse_str()` consumes a pair and before a handler is
handed a form — for a multipart body, after its envelope has been split
into parts and expanded. The setting is `PHP_INI_PERDIR` too, so nothing
at request time can move it.
```

| Limit | Default | What it counts |
|---|---|---|
| `MAX_INPUT_VARS` | 512 | pairs in a raw url-encoded body, and leaf values in the parsed form |
| `MAX_FILE_PARTS` | 16 | leaf entries in `getUploadedFiles()` |
| `MAX_NESTING_DEPTH` | 8 | array levels a name builds — `a[b][c]=1` is 3 |
| `MAX_MULTIPART_PARTS` | 512 | parts in a raw multipart envelope, unnamed ones included |
| `MAX_PART_HEADERS` | 16 | header *lines* on any one multipart part, repeats included |
| `MAX_PART_HEADER_BYTES` | 8 KiB | bytes on one multipart header line |
| `MAX_BODY_SIZE` | 2 MiB | bytes, the same env var the Kernel's own body cap reads |

The six structural ceilings are constants — they describe the shape this
framework will hydrate at all. The byte ceiling is per-application, so
`FormLimits` is a value object built once from `Config` at the entry
point and handed to the adapter and to `MaxBodySizeMiddleware` alike;
neither reads the environment on its own, and the two therefore cannot
disagree about where the edge is.

**A runtime configured below the contract is refused, not obeyed.** The
counts above sit under PHP's own defaults (`max_input_vars` 1000,
`max_input_nesting_level` 64), but a deployment is free to set either
lower — and `parse_str()` answers a list past `max_input_vars` with a
shorter array, and a name nested past `max_input_nesting_level` by
dropping that variable in complete silence. So the names a parse is about
to be handed are checked against both the contract and this runtime's own
settings first: past the contract is the usual `413`, and past a local
setting while still inside the contract is a `413` naming the setting an
operator can fix. Either way the form is refused before it is parsed
rather than handed on shortened.

**Every count is taken from the raw body, before anything parses it.**
That is not an optimization, it is the only place the real numbers exist:

- `a=1` repeated a thousand times is a thousand pairs on the wire and
  **one leaf** in the parsed form. A limit checked on the parsed result
  reads that body as a one-field form.
- A multipart part carrying no `Content-Disposition` name builds neither
  a field nor a file, so it appears nowhere in the result — while still
  costing a parser a part.
- A part repeating one header a thousand times has **one entry** in any
  parser's header map and a thousand lines on the wire.

`MultipartEnvelope` is the bounded scan that sees all three. It runs
before `riverline/multipart-parser` under Lambda and RoadRunner, whose
`getParts()` builds a part and a stream for every part before a caller
can ask how many there are — a ceiling checked on that result is checked
after the cost it exists to bound has been paid. The parsers' own output
is still validated afterwards, as the second line rather than the first.

### What a `multipart/form-data` body may say

The same scan enforces what the body *means*, which is the other half of
"one contract". `multipart/form-data` is not one language: parsers
disagree about where a part ends, whether its bytes are decoded on the
way out, and what its `Content-Disposition` says. Kinetis accepts one
reading — the byte-literal RFC 7578 subset — and refuses, on every
runtime, everything a second reading exists for.

- **The root `Content-Type` names exactly one boundary.** Its parameter
  section is read whole, under the same grammar a part's own headers
  meet, and a section that is not a complete list of distinct parameters
  is a `400`: `boundary=A; boundary=B` is the first boundary to one
  parser and the second to another, and `boundary="A"junk` is `A` to one
  and `Ajunk` or a failure to the next. A header naming no boundary at
  all is the separate, ordinary case — nothing to split the body at
  rather than two ways to split it.
- **A delimiter is `CRLF--boundary`, followed by CRLF, or by `--` and
  then CRLF or the end of the body.** Nothing else is one. A line whose
  boundary token is only a prefix (`--boundaryX`) or that carries
  transport padding before its CRLF is payload — kept byte for byte, not
  a split point and not an error. A line a parser splitting on `\n` would
  take as a delimiter while this one does not — a boundary after a bare
  LF, a stray CR before the CRLF — is a `400`: two readings of one body
  are two different forms.
- **A part's bytes are the bytes on the wire.**
  `Content-Transfer-Encoding` may only be `7bit` or `binary`, the two
  spellings that decode to themselves. `base64`, `quoted-printable` and
  `8bit` each send a parser that implements them down a decoding or
  charset-conversion path a parser that doesn't will never take; RFC 7578
  §4.7 does not use the header at all.
- **A part's metadata is the text on the wire.** No RFC 2047 encoded
  words, no RFC 5987 `name*=`/`filename*=` extended parameters, no
  escapes, surrounding spaces or semicolons inside a quoted value, and
  each parameter named once, in lowercase. A plain
  `form-data; name="user[address][city]"; filename="café.txt"` — what a
  browser sends — is unaffected.
- **A part is not itself a multipart body.** A nested envelope is a whole
  further form to a parser that recurses into it, one part's bytes to one
  that does not, and counted by no ceiling either way. RFC 7578 §4.3
  settles multiple files as repeated parts under one name.
- **A part's header lines are ordinary, complete header lines.** No
  obs-fold continuation, no line without a name, no control characters,
  and at most one each of `Content-Disposition`, `Content-Type` and
  `Content-Transfer-Encoding`.
- **A file part that declares no `Content-Type` has no client media
  type** — `getClientMediaType()` is `null`, not the
  `application/octet-stream` a parser's own default would invent.

Each rule is a place two real parsers disagree, so each is a `400` rather
than a normalization: whichever reading this framework picked would be
the other parser's answer to the same bytes. The shared runtime
conformance suite sends every one of them at every adapter and requires
the identical answer.

**Two answers, and only two.** A body that cannot be parsed is a `400`
carrying the fixed `RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`. A
body past any ceiling above is a `413` naming the limit and its
configured number, which is safe to return because it contains nothing
from the request. Both happen before the handler runs, and nothing is
ever truncated: a form that meets a ceiling is refused whole, never
handed on missing exactly the fields an attacker chose to push past the
edge.

**What is logged for a `400` is a fixed category** — `no-boundary`,
`ambiguous-boundary`, `no-parts`, `unreadable-multipart`,
`undecodable-part`, `nested-multipart`, `ambiguous-delimiter` — and never
a parser's own message. A parser
message is assembled from the input that failed, so it quotes header
names, part names, charset labels and body fragments a client chose; a
log is read, searched, shipped and rendered somewhere. What is lost is
which byte offset upset which parser, which no operator can act on; what
is kept is the category, which is what an operator triages on.

**An empty file control keeps PHP's semantics.** A file input the user
left alone is still submitted — an empty part with `filename=""` — and
PHP reports it in `$_FILES` as present with `UPLOAD_ERR_NO_FILE`, no
name, no type and no bytes. Every adapter reports the same, so upload
validation written against PHP reads "nothing was chosen" under all four
rather than accepting a successful zero-byte upload under two of them.

The byte ceiling is checked against the bytes actually in hand as well as
the declared `Content-Length`: a request that understates its length, or
declares none, is bounded only by the first.

## Raw request bodies are staged before the handler runs

A body that is not a form never reaches `Kinetis\Http\Form` at all — it
is the Kernel's, and `MaxBodySizeMiddleware` settles it. The declared
`Content-Length` is checked first, so an honestly-labeled oversized
request is refused without being read; then the body is read once,
incrementally, counted, into a replayable temporary stream, and the
request the handler receives carries that stream, rewound and complete.
Over the ceiling is a `413` and the handler never runs.

Everything downstream therefore sees one body and one length: `read()`,
`getContents()` and a plain `(string)` cast all return the identical
accepted bytes, and any of them can be used after the others. A handler
may read the body any way it likes, because by then there is no cap left
to enforce.

Settling it in front of the handler is the only way to get that. The
alternative — a stream wrapper that counts as the handler reads — cannot
be made safe. `Stringable` forbids
`__toString()` from throwing, so such a wrapper has to answer a cast with
something, and the only things available are a lie or an empty string. An
empty string is the dangerous one: a handler, or any vendor middleware
between the wrapper and it, reads an oversized request as an absent
optional body and carries on. The ceiling has to be settled before the
handler is called.

A temporary stream that will not open, a read that stalls, or a write
that stops short is this worker's failure rather than the client's, so it
is a `FormStagingException` and a server error — never a `400` or a
`413`, and never a body that reaches a handler shorter than it was sent.

## Forwarded headers are read only from a trusted edge

`X-Forwarded-Proto` and `X-Forwarded-For` are ordinary request headers:
any client that can reach the listener can send them. A client that can
choose the scheme its own request appears to have arrived over can choose
whether a `Secure` cookie is set, what every absolute URL the application
generates points at, and whether an OAuth redirect target validates.

So **the default is to read neither**. `TRUSTED_PROXIES` — a
comma-separated list of addresses and CIDR ranges — names the edge, and
only when the peer that actually connected matches one of them is a
forwarded header consulted:

```{code-block} bash
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

An entry that is not an address or a CIDR range is refused at startup
rather than silently matching nothing, since a range that never matches
looks exactly like a correct one that is never reached.

The rule is the same under FrankenPHP, PHP-FPM and RoadRunner. A
forwarded scheme from an untrusted peer is ignored completely — it can
neither promote a request to `https` nor downgrade one — and a trusted
proxy that sends something other than exactly `http` or `https`,
including two schemes folded into one header, is a fixed `400` before the
handler: there is no rule that picks the right answer out of two, and the
peer that could have gotten it right is the one that got it wrong.

Lambda is the exception that proves it: an invocation arrives over the
Runtime API with no connecting client at all, and `x-forwarded-proto` is
API Gateway's own field on an event it built. The gateway is the edge by
construction — and the value is still validated rather than believed, as
the identity rules below describe.

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

### Request identity comes from one authoritative field each

A payload-v2 event describes where the request was addressed in five
places that can disagree: `requestContext.domainName`, the `host`
header, `x-forwarded-proto`, `x-forwarded-port`, and
`requestContext.http.protocol`. Reading each one wherever it happens to
be needed produces a request whose URI, `Host` header and request target
are three different answers to the same question — and an application
generating an absolute URL, signing a canonical request, or comparing an
origin then behaves differently under Lambda than under any other
runtime, for no reason it can see.

So one field decides each part, every other field must agree with it,
and an event where they don't is rejected before anything is dispatched:

- **Host** — `requestContext.domainName`, the one field a client cannot
  write. A `host` header is accepted only if it names that same domain,
  with or without a port; one naming a different domain is refused. The
  `Host` header the application reads is rebuilt from the domain, so it
  cannot disagree with the URI.
- **Port** — `x-forwarded-port`, or the port in the `host` header, and
  they must match when both are present. A port that is the scheme's
  default is not part of the authority, exactly as PSR-7's own URI
  treats it.
- **Scheme** — `x-forwarded-proto`; `https` when it's absent, because an
  HTTP API and a Function URL have no plaintext mode at all. Anything
  other than `http` or `https` is refused.
- **Protocol version** — `requestContext.http.protocol`.
- **Request target** — `rawPath` and `rawQueryString`, byte for byte,
  set as the request target rather than rebuilt from a parsed path and
  a re-encoded query.

Every string that ends up in the URI must be valid UTF-8 with no control
characters or spaces, and `rawPath` must be an absolute path carrying no
query or fragment of its own. Invalid UTF-8 in a path would otherwise
travel as far as encoding the response payload and fail there, turning a
bad request into a failed invocation; a control character in a request
target is request smuggling looking for somewhere to land.

### What's mapped, and how

- **Method, path, query string, and headers** — straight from the
  event's own `requestContext.http.method`/`rawPath`/`rawQueryString`/
  `headers`. A purely-numeric header name (`"123"`, valid per RFC 9110 —
  digits are ordinary token characters) is mapped correctly: PHP's own
  `json_decode(..., associative: true)` coerces a canonical-integer JSON
  object key into a real PHP int array key, so it's cast back to a
  string before reaching PSR-7's `withHeader()`, which requires one.
- **Query parameters** — `parse_str()` over `rawQueryString`, the same
  bytes with the same function every other runtime uses. The event's own
  `queryStringParameters` is API Gateway's lossy summary of that query:
  it comma-joins a repeated parameter into one value, which PHP would
  then read as a single parameter whose value contains a comma. It is
  validated as part of the event's shape and read nowhere. A
  purely-numeric parameter name ends up as an int array key here as it
  does on every adapter — PHP always coerces a canonical-integer string
  used as an array *key*, regardless of any cast applied first — but
  PHP's own array-lookup semantics coerce a numeric-string *read* the
  identical way, so `$request->getQueryParams()['123']` still finds the
  value.
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
  `getParsedBody()`/`getUploadedFiles()` by the same rules PHP's own
  parser follows — `user[address][city]` nests, `tags[]` appends, a
  repeated plain name replaces — and held to the same ceilings and the
  same wire-level multipart contract, with the same `400`/`413`,
  described under "Form bodies: one contract under every runtime" above.
  `riverline/multipart-parser` does the multipart parse here, behind
  that contract rather than instead of it. There is no SAPI here at all, so those ceilings
  are the whole defense; Lambda's own 6 MB invocation payload limit sits
  above them rather than in place of them. `MaxBodySizeMiddleware`'s cap on a raw
  JSON body applies as everywhere else.
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
  event is reported. An event whose request identity doesn't cohere is
  rejected the same way: reported to the invocation error endpoint,
  never dispatched.
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

`http.raw_body: true`, which RoadRunner's own Go source spells out:
without it, RoadRunner parses
`multipart/form-data`/`application/x-www-form-urlencoded` bodies itself,
in Go, before the PHP worker is ever invoked, and a body it can't parse
never reaches PHP at all — the client gets RoadRunner's own error
response instead of this framework's `400`/JSON shape. Setting it
disables that Go-side parsing entirely, so every body — well-formed or
not — reaches this adapter's own parser untouched, the same reason
`kinetis/bref-adapter` needs one: a request body here is one in-memory
string with no live `php://input` stream behind it, and parsing an
arbitrary multipart string needs `riverline/multipart-parser`, which
core does not carry. The ceilings, the contract and the parse-failure
vocabulary are still core's `Kinetis\Http\Form`; only the parser
differs.

A misconfigured `raw_body` doesn't fail silently: `RoadRunnerAdapter`
detects the resulting Go-side pre-parsed body (a real attribute
RoadRunner's own `PSR7Worker` stamps on every request) and reports it as
a clear configuration error naming `http.raw_body: true`, rather than
re-parsing an already-parsed body and silently producing wrong fields.

Both halves of that detection are checked, on every request rather than
only on a form one. A request whose attribute says RoadRunner parsed the
body is the misconfiguration above. A request that doesn't carry the
attribute at all — a worker library that doesn't set it — doesn't mean
`raw_body` is on; it means nothing here can tell, and that is refused
too rather than assumed good, since assuming it good is exactly how the
first case would go undetected.

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

`RoadRunnerAdapter` itself applies the shared form contract on top —
the ceilings under "Form bodies: one contract under every runtime"
above, including `MAX_BODY_SIZE` (the same env var and default, 2 MiB,
`MaxBodySizeMiddleware` uses, carried in the same `FormLimits` instance).
The byte ceiling is checked against the bytes actually in hand as well as
any declared `Content-Length`, so a request that understates its length
or declares none is still bounded; what it cannot bound is the read
itself, since RoadRunner has already handed the whole body over as one
in-memory string by the time this adapter runs. That is what
`http.max_request_size` is for, and why it is required rather than
optional.

### `X-Forwarded-Proto` decides the URI scheme, from a trusted edge

RoadRunner's own listener is plaintext whenever TLS is terminated in
front of it, which is the ordinary deployment — so without this an
application behind a load balancer generates `http://` URLs for an
`https://` site. `RoadRunnerAdapter` applies `X-Forwarded-Proto` to the
request URI under the same `TRUSTED_PROXIES` policy core's superglobals
bridge uses; see "Forwarded headers are read only from a trusted edge"
above for the whole rule. A directly reachable `rr serve` with no policy
configured reads the header from nobody, which is the safe default for
exactly that deployment.

### Sizing RoadRunner's worker processes

The same underlying shape {doc}`runtime-adapters`'s "Sizing FrankenPHP's
worker threads" section describes applies here, just with a process in
place of a thread: `.rr.yaml`'s `http.pool.num_workers` sets how many
PHP worker processes RoadRunner keeps running, each handling exactly
one HTTP request at a time, start to finish. `bootstrap.php` (and every
`extra.kinetis` package bootstrap) runs once per worker process, so
each one builds its own separate service instances — including a
database connection pool via `kinetis/persistence`'s
`SqlConnectionFactory`. Oversizing `num_workers` without correspondingly
undersizing each pool's `maxConnections` can exhaust your database's
own connection limit exactly the same way it can under FrankenPHP — see
{doc}`persistence`'s "Sizing `maxConnections` under worker mode"
section.

Where this genuinely differs from FrankenPHP, not just in name: each
RoadRunner worker is a separate OS process rather than a thread sharing
one process, so there's no cross-worker contention on that process's
own resources (the kernel `mm` lock contention `Kinetis\Async\FiberPool`
exists to avoid under FrankenPHP's threaded model doesn't arise here in
the same way, since nothing is shared to contend over) — but process
creation itself has its own, different overhead. FrankenPHP's own
measured thread-sizing ratios (2–3× vCPUs for a mixed CPU/fast-query
workload) come from load testing threads specifically and haven't been
separately re-measured against RoadRunner's process model; see
{doc}`performance-tuning`'s own note on this before assuming they
transfer unchanged. Measure under realistic load either way.

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
  how narrow the trigger is. A permanent limitation until the upstream
  library fixes it, and one the shared conformance suite asserts rather
  than skips: this adapter's driver declares that the header does not
  survive, and the suite then requires it to be *absent*, never present
  under some other name or carrying some other value.
- **Cookie order.** Every other adapter here preserves the exact order a
  client sent its cookies in. RoadRunner represents cookies as a Go
  `map[string]string` on the way to PHP, and Go randomizes map iteration
  order by design — a request's cookies can arrive re-ordered, observed
  at roughly 1 request in 10 across repeated real runs, not
  deterministic. Declared and asserted the same way as the header above:
  the names and values are checked on every run, the order only where
  the environment can keep it.

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
land, the URI's scheme, authority and request target, form and binary
bodies, the form-complexity ceilings, response cookies, streaming, the
`400` for a body the environment can't parse — whose fixed message is
`RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`) runs against yours
with no further test code.

An adapter that parses form bodies itself, the way `kinetis/bref-adapter`
and `kinetis/roadrunner-adapter` do, gets the rules from
`Kinetis\Http\Form` rather than writing its own: `MediaType` decides
which bodies are forms, `StagedMultipartBody` copies one into a stream
for a parser and refuses to hand over a body it could not stage whole,
`MultipartFormBuilder` and `UrlEncodedForm` build the two PSR-7
structures by PHP's own nesting rules, and `FormLimits` is every ceiling
in one place. That is what keeps the accepted spellings, the nesting,
and the point at which a client is refused identical under every
runtime; see {ref}`multipart-form-data-file-uploads`.

You can also construct any adapter directly if you want to force a
specific one instead of relying on automatic detection. Every adapter
takes the same two policies `RuntimeDetector::detect()` would have
handed it, for the same reason — it bounds and parses a request body
before the Kernel or its container exist:

```{code-block} php
$adapter = new Kinetis\Runtime\Adapters\FpmAdapter(
    $app->get(Kinetis\Http\Form\FormLimits::class),
    $app->get(Kinetis\Http\TrustedProxies::class),
);
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
