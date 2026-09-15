# Appendix: Runtime Reference

The contracts behind {doc}`runtime-adapters`, {doc}`concurrency` and
{doc}`caching`: how a request body is staged, counted and parsed, how
forwarded headers and Lambda events become a request's identity, what
each adapter does at its edges, what a custom adapter must do, how
`concurrently()` schedules Fibers, and how the AOT artifact is published
and read. The guides cover choosing and running a runtime, running
independent I/O side by side, and deploying the artifact.

## Request bodies

An adapter normalizes its transport into a raw PSR-7 request and stops
there. Everything a body means is settled once, inside the Kernel, by
`Kinetis\Http\Middleware\RequestBodyMiddleware` — staging, the byte
ceiling, and the `multipart/form-data` and
`application/x-www-form-urlencoded` parse under `Kinetis\Http\Form`. The
middleware is global and unconditional. All four adapters deliver the
same bytes to it, so the same form is accepted by all four or refused by
all four with the same status.

(runtime-reference-body-staging)=
### Every body is staged before the handler runs

Staging happens for every request, form or not. The declared
`Content-Length` is checked first, so an honestly-labeled oversized
request is refused without being read; then the body is read once,
incrementally, counted, into a replayable in-memory stream
(`php://memory`), and the request the handler receives carries that
stream, rewound and complete. Reading stops as soon as the count passes
the ceiling, which is a `413`, and the handler never runs. A body that is
not a form goes no further than this — nothing parses it, and nothing
invents a `getParsedBody()` for it.

The byte ceiling is checked against the bytes in hand as well
as the declared `Content-Length`: a request that understates its length,
or declares none, is bounded only by the first.

Everything downstream therefore sees one body and one length, and no way
of reading it can fail — by then there is no cap left to enforce.
`read()` and `getContents()` answer from wherever the cursor stands, so
code that needs the whole body, after another middleware may already
have read it, uses a plain `(string)` cast — which rewinds first — or
rewinds explicitly.

Settling it in front of the handler is the only way to get that. The
alternative — a stream wrapper that counts as the handler reads — cannot
be made safe. `Stringable` forbids `__toString()` from throwing, so such
a wrapper has to answer a cast with something, and the only things
available are a lie or an empty string. An empty string is the dangerous
one: a handler, or any vendor middleware between the wrapper and it,
reads an oversized request as an absent optional body and carries on.

A staging stream that will not open, a read that stalls, or a write that
stops short is this worker's failure rather than the client's, so it is a
`FormStagingException` and a server error — never a `400` or a `413`,
and never a body that reaches a handler shorter than it was sent.

### Counts come from the raw body

`FormLimits` holds six structural ceilings as class constants — they
describe the shape this framework will hydrate at all — and the byte
ceiling, built once from `MAX_BODY_SIZE` at the entry point and bound on
`AppScope`. Nothing reads the environment on its own, so nothing can
disagree about where the edge is.

Every count is taken from the raw body, before anything parses it — the
only place the real numbers exist:

- `a=1` repeated a thousand times is a thousand pairs on the wire and
  **one leaf** in the parsed form. A limit checked on the parsed result
  reads that body as a one-field form.
- A multipart part carrying no `Content-Disposition` name builds neither
  a field nor a file, so it appears nowhere in the result — while still
  costing a parser a part.
- A part repeating one header a thousand times has **one entry** in any
  parser's header map and a thousand lines on the wire.

`MultipartEnvelope` is the bounded scan that sees all three. A parser
expands the whole body and reports its shape afterwards, so a ceiling
checked on that result is checked after the cost it exists to bound has
been paid. The scan allocates nothing per part beyond its own offsets and
refuses at the first part or header line past a ceiling; the parts it
returns are the ones the parse then builds from.

### A runtime configured below the contract

The counts sit under PHP's own defaults (`max_input_vars` 1000,
`max_input_nesting_level` 64), but a deployment is free to set either
lower — and `parse_str()` answers a list past `max_input_vars` with a
shorter array, and a name nested past `max_input_nesting_level` by
dropping that variable in complete silence. So the names a parse is about
to be handed are checked against both the contract and this runtime's own
settings first: past the contract is the usual `413`, and past a local
setting while still inside the contract is a `413` naming the setting an
operator can fix. Either way the form is refused before it is parsed
rather than handed on shortened.

### Why the SAPI adapters need `enable_post_data_reading=0`

Left at its default, PHP reads and parses the body before any Kinetis
code exists: it populates `$_POST`/`$_FILES` for a POST form, empties
`php://input` doing so, drops everything past `max_input_vars` with only
a warning, and answers a body over `post_max_size` with an empty `$_POST`
and no error at all. None of that is observable afterwards — a form
truncated to its first 1000 fields is indistinguishable from a form that
had 1000 fields. With the setting off, `php://input` carries the whole
body for every method including POST.

`Kinetis\Runtime\SuperglobalsBridge` checks the setting before it builds
a request and refuses to serve one without it, with a message naming the
setting. `request_parse_body()` is not used and cannot be: it reads the
same input stream, so it would leave nothing for the middleware that owns
the body.

### Why `arg_separator.input` must be `&`

`parse_str()` splits a body on whatever `arg_separator.input` names,
which is a set of characters rather than a single one. Every count, name
and depth taken here is read by splitting on `&`, so any other value
parses a different form from the one that was measured. Set to `;`, a
body of `a=1&b=2&…` becomes one field whose value is the rest of the
request. Set to `&;`, a body of `a=1;b=2;…` is one pair to the count and
as many as the client likes to the parser — past the ceilings, then cut
back to this runtime's own `max_input_vars` in silence.

`Kinetis\Http\Form\FormPairs` checks the setting whenever it parses a
form — before `parse_str()` consumes a pair and before a handler is
handed a form; for a multipart body, after its envelope has been split
into parts and expanded. Anything but exactly `&` is a
`FormParserConfigurationException`, a server error naming the setting.
The setting is `PHP_INI_PERDIR`, so nothing at request time can move it.

(runtime-reference-multipart)=
### What a `multipart/form-data` body may say

The same scan enforces what the body *means*. `multipart/form-data` is
not one language: parsers disagree about where a part ends, whether its
bytes are decoded on the way out, and what its `Content-Disposition`
says. Kinetis accepts one reading — the byte-literal RFC 7578 subset —
and refuses, on every runtime, everything a second reading exists for.

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
the identical answer — see {ref}`testing-reference-conformance`.

### Refusals and what is logged

A body that cannot be parsed is a `400` carrying the fixed
`RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`. A body past any
ceiling is a `413` naming the limit and its configured number, which is
safe to return because it contains nothing from the request. Both happen
before the handler runs, and nothing is ever truncated: a form that meets
a ceiling is refused whole, never handed on missing exactly the fields an
attacker chose to push past the edge.

What is logged for a `400` is a fixed category — `no-boundary`,
`ambiguous-boundary`, `no-parts`, `unreadable-multipart`,
`undecodable-part`, `nested-multipart`, `ambiguous-delimiter` — and never
a parser's own message. Two refusals outside the middleware log fixed
categories the same way: `invalid-base64` for a Lambda body that is not
strict base64, and `unreadable-forwarded-header` for a trusted edge's
unreadable scheme. A parser message is assembled from the input that
failed, so it quotes header names, part names, charset labels and body
fragments a client chose; a log is read, searched, shipped and rendered
somewhere. What is lost is which byte offset upset which parser, which
no operator can act on; what is kept is the category, which is what an
operator triages on.

### The empty file control

A file input the user left alone is still submitted — an empty part with
`filename=""` — and PHP reports it in `$_FILES` as present with
`UPLOAD_ERR_NO_FILE`, no name, no type and no bytes. Every adapter
reports the same, so upload validation written against PHP reads
"nothing was chosen" under all four rather than accepting a successful
zero-byte upload under two of them.

(runtime-reference-forwarded-headers)=
## Forwarded headers

`X-Forwarded-Proto` is an ordinary request header: any client that can
reach the listener can send it. A client that can choose the scheme its
own request appears to have arrived over can choose whether a `Secure`
cookie is set, what every absolute URL the application generates points
at, and whether an OAuth redirect target validates.

`Kinetis\Http\TrustedProxies::fromConfig()` builds the policy from
`TRUSTED_PROXIES`, a comma-separated list of addresses and CIDR ranges.
An empty list trusts no peer. An entry that is not an address or a CIDR
range throws `InvalidTrustedProxyException` at startup rather than
silently matching nothing, since a range that never matches looks
exactly like a correct one that is never reached.

`Kinetis\Runtime\HttpStartup` registers the policy on `AppScope` before
the bootstrap chain runs, so `bootstrap.php` or a package bootstrap can
replace it, and reads it back after `boot()` to hand to
`RuntimeDetector::detect()`. An adapter settles a request's scheme before
the Kernel or its container exist, so it cannot resolve the policy and
must not invent one.

- `SuperglobalsBridge`, under FrankenPHP and PHP-FPM, rebuilds the scheme
  from what the environment serves — PSR-7's own server-request creation
  applies `X-Forwarded-Proto` unconditionally — and moves it only when the
  connecting peer matches the policy.
- `RoadRunnerAdapter` applies `X-Forwarded-Proto` to the request URI only
  when the connecting peer matches the policy.
- A trusted peer that sends anything other than exactly `http` or
  `https`, including two schemes folded into one header, is a `400`
  carrying `RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`, answered
  while the request is still being built and before any middleware
  exists. The reason is logged as `unreadable-forwarded-header` and never
  returned. There is no rule that picks the right answer out of two, and
  the peer that could have gotten it right is the one that got it wrong.

No adapter rewrites `REMOTE_ADDR` from `X-Forwarded-For`: the request's
client address stays the peer that connected. `RateLimitMiddleware`
walks the forwarded chain under its own policy — see
{doc}`appendix-middleware`'s "Forwarded client identity".

Lambda is the one runtime this policy does not reach, and
`BrefLambdaAdapter` is the one adapter that takes no `TrustedProxies` at
all. An invocation arrives over the Runtime API with no connecting client
to weigh: `x-forwarded-proto` is API Gateway's own field on an event it
built, so the gateway is the edge by construction. What replaces the
policy there is a platform fact — an HTTP API and a Function URL are
TLS-only, so the scheme is `https` and a forwarded header cannot move it.
An event claiming `http` describes an invocation the platform cannot have
delivered, and is refused as malformed rather than honored or ignored.

## FrankenPHP and PHP-FPM

`FrankenPhpAdapter` loops on `frankenphp_handle_request()` and lets an
uncaught exception propagate, which ends the worker. `FpmAdapter` serves
one request per script run. Both build their request through
`SuperglobalsBridge`.

A streamed response's failing emitter is the one throwable the bridge
contains. By then the status, the headers and some number of body bytes
have left the process, so there is no replacement response to send — and
under a persistent worker an escaping throwable ends the worker itself,
taking the warm state every later request on that thread would have used
down with one client's broken stream. `SuperglobalsBridge::emit()` catches
it, writes the exception class, message and `file:line` to the SAPI error
log, and returns to the loop; the client sees the truncated body a
half-sent response can only end as. The Kernel's own wrapper still
releases the request scope and re-raises the emitter's failure to whoever
invoked it, and a request that fails before emission begins is still a
`500` from `ExceptionHandlerMiddleware`.

(runtime-reference-lambda)=
## AWS Lambda events

`Kinetis\BrefAdapter\BrefLambdaAdapter` speaks the Lambda Runtime API
directly, with plain stream-context HTTP rather than `ext-curl` or the
`bref/bref` package: poll `/2018-06-01/runtime/invocation/next`, run the
request, post the response to `.../invocation/{id}/response`, or report a
rejected event to `.../invocation/{id}/error`. It converts to and from
API Gateway's **HTTP API payload format 2.0** event shape — the format a
Function URL or an HTTP API integration sends. ALB and the older REST
API's payload format 1.0 aren't handled.

### Event validation

Every field this depends on is validated before an event is routed. A
direct Lambda invocation can carry arbitrary JSON. `"version": "2.0"`
is required to identify a payload-v2 event; a payload-format-1 event
with a similar `requestContext.http` shape is still refused.

`rawPath` and `requestContext.http.method` are required as non-empty
strings; every other field this adapter reads (`rawQueryString`,
`headers`, `queryStringParameters`, `body`, `isBase64Encoded`,
`requestContext.http.sourceIp`, `cookies`) is optional but, when present,
is checked for the right type — including the exact collection shape,
not only "is this an array": `headers` and `queryStringParameters` must
each be a genuine JSON *object* with string values (an array-valued
entry, or the field being a JSON list instead of an object, is
rejected), and `cookies` must be a genuine JSON *list* of strings (a JSON
object — `{"session": "abc"}` rather than `["session=abc"]` — is
rejected, not silently accepted as a one-entry list).

This distinction only exists at the raw JSON level:
`json_decode(..., associative: true)` collapses `{}` and `[]`, and an
object-valued and a string-valued map entry, into the identical shape of
plain PHP array — so validation runs against a separate,
non-associative decode of the same body first, which is the only decode
mode where a JSON object and a JSON list stay distinguishable.
Anything that fails any of these checks is reported to the Runtime API's
invocation error endpoint — never silently degraded into a
plausible-looking request built from whichever fields happen to be
missing or malformed. An event naming one header under two spellings, a
header name that is not an RFC 9110 token, or a header value with a
control character is refused the same way.

### Request identity

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
- **Scheme** — `https`, decided by the platform rather than by the
  event: an HTTP API and a Function URL have no plaintext mode at all,
  so there is no listener a plaintext request could have arrived on.
  `x-forwarded-proto` is checked against that instead of deciding it —
  absent or `https` is what API Gateway sends, and any other value,
  `http` included, is refused with everything else that contradicts
  itself.
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

### Field mapping

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
  does on every adapter, and PHP's array-lookup semantics coerce a
  numeric-string *read* the identical way, so
  `$request->getQueryParams()['123']` still finds the value.
- **Cookies** — payload format 2.0 carries these as their own top-level
  `cookies: string[]` list, never folded into `headers`. Reconstructed
  into a real `Cookie` header and into `getCookieParams()`, so cookie-
  and session-based authentication works the same as it does under
  FrankenPHP or FPM.
- **The client's IP address** — `requestContext.http.sourceIp` is mapped
  to the request's `REMOTE_ADDR` server parameter. Every invocation
  arrives over the Runtime API, not a socket PHP itself accepted, so
  without this every request would look identical to code reading
  `REMOTE_ADDR`.
- **The request body** — a base64-encoded body (`isBase64Encoded: true`)
  is decoded strictly: invalid base64 is a `400` rather than an empty
  body. The decoded bytes are handed on raw to `RequestBodyMiddleware`.
- **The response body** — checked for valid UTF-8 before being handed to
  the Runtime API, which receives the whole response as one JSON
  document. A body that isn't valid UTF-8 (an image, a PDF, any binary
  payload) is base64-encoded and `isBase64Encoded: true` is set on the
  payload — API Gateway decodes it again on the way out. A body that's
  already valid UTF-8 is sent as-is.
- **Response cookies** — every `Set-Cookie` header value is emitted as
  its own entry in the payload's `cookies` array, never comma-joined
  with any other `Set-Cookie` value. A cookie's own attributes
  (`Expires`, in particular) already contain a comma, so folding two
  cookies together would produce a value no client could parse back into
  distinct cookies.

### Streaming and Runtime API failures

A controller returning a `Kinetis\Runtime\StreamableResponseInterface`
throws immediately rather than silently buffering or dropping the stream.
The response is abandoned first, so the request scope behind it is
released on the invocation that created it rather than surviving the
container's freeze. The Runtime API's poll/respond contract is one
invocation, one response payload; Lambda response streaming needs a
Function URL configured with `InvokeMode: RESPONSE_STREAM`, a different
invocation model this adapter doesn't implement.

A poll or a response POST that fails outright (connection refused, a
non-2xx status) throws instead of being treated as an empty response —
there is no invocation to serve and nothing meaningful to fall back to,
so the function erroring, visible in CloudWatch, is the outcome.

## RoadRunner

`Kinetis\RoadRunnerAdapter\RoadRunnerAdapter` builds a
`Spiral\RoadRunner\Http\PSR7Worker` over `Spiral\RoadRunner\Worker::create()`
and loops `waitRequest()`/`respond()`. It folds a repeated header into
one comma-joined value first, since `PSR7Worker` presents repeats as
separate array values.

### `http.raw_body` detection

Without `http.raw_body: true`, RoadRunner parses
`multipart/form-data`/`application/x-www-form-urlencoded` bodies itself,
in Go, before the PHP worker is invoked, and a body it can't parse never
reaches PHP — the client gets RoadRunner's own error response instead of
this framework's `400`. The adapter reads the `rr_parsed_body` attribute
`PSR7Worker` stamps on every request, on every request rather than only
on a form one. `true` is the misconfiguration, reported as an error
naming `http.raw_body: true`. An attribute that is absent — a worker
library that doesn't set it — doesn't mean `raw_body` is on; it means
nothing here can tell, and that is refused too rather than assumed good,
since assuming it good is exactly how the first case would go undetected.

### `http.max_request_size`

RoadRunner reads the whole body into memory as one string before any PHP
runs, and there is no SAPI to enforce `upload_max_filesize` or
`post_max_size`. `http.max_request_size` is enforced in Go, wrapping the
request in `http.MaxBytesReader` before the PHP worker is invoked — the
only place a body with no declared `Content-Length` (a chunked request)
can be bounded, since by the time the adapter runs it holds the whole
body with nothing left to read incrementally. RoadRunner's own default is
1000 MB.

### Streaming, numeric header names and cookie order

`Worker::create()`'s default `interceptSideEffects: true` installs a
global output-buffer redirect (`StdoutHandler::register()`) sending every
stray `echo`/`header()` call to RoadRunner's log stream instead of the
client — required to keep the Goridge binary protocol on STDOUT
uncorrupted, and the reason `Kinetis\Http\StreamedResponse`'s emitter
closures can't be used here: their output would be silently redirected
the same way. A controller returning a `StreamableResponseInterface`
gets a `501` instead, after the handler runs, and the response is
abandoned before the refusal goes back, so the request scope behind it is
released on that request. RoadRunner's own `HttpWorker::respondStream()`
is a different, lower-level generator-based API than
`PSR7Worker::respond()`, and bridging one onto the other needs its own
design pass.

`"123"` is a valid RFC 9110 header name, but `spiral/roadrunner-http`'s
own request decoding drops it before the adapter sees the request: PHP
coerces a numeric string array key to an `int`, and that library's
`is_string($key)` filter then deletes it. Recovering it would mean
reimplementing that library's JSON/protobuf request decoding instead of
using `PSR7Worker`. RoadRunner represents cookies as a Go
`map[string]string` on the way to PHP, and Go randomizes map iteration
order, so a request's cookies can arrive re-ordered. The conformance
driver declares both, and the shared suite asserts both directions:
the numeric header must be absent rather than renamed, and cookie names
and values are checked on every run, their order only where the
environment keeps it.

### Worker processes

Each RoadRunner worker is a separate OS process rather than a thread
sharing one process, so nothing is shared between workers to contend
over — but process creation has its own, different overhead. FrankenPHP's
measured thread-sizing ratios come from load testing threads and haven't
been re-measured against RoadRunner's process model.

### `ext-sockets` in the package's CI

`kinetis/roadrunner-adapter`'s Alpine-based checks (install, PHPStan,
Psalm, the committed unit suite) each run in a separate, stateless
container that never loads `ext-sockets` at runtime, so compiling it
repeatedly would be pure cost; Composer's platform check is bypassed
there instead. `kinetis/persistence` and `kinetis/database-bridge`
compile it in their PHPUnit steps, because the native Postgres driver
refuses to construct without the extension. The `roadrunner-conformance`
job gets a prebuilt extension from `shivammathur/setup-php` — see
{doc}`appendix-ci`.

(runtime-reference-custom-adapter)=
## Writing a runtime adapter

To target another environment, implement this interface and Kinetis
drives it the same way it drives the four built-in adapters:

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
the end of every request — worth doing in a worker that keeps serving,
pure waste under a boot-per-request SAPI, where request shutdown releases
that memory anyway.

Hold the adapter to the contract the built-in ones meet: implement a
`Kinetis\Testing\Runtime\RuntimeAdapterDriver` for it and extend
`RuntimeAdapterConformanceTestCase` — see
{ref}`testing-reference-conformance`. Every behavior the core adapters
agree on (how a repeated header folds, where cookies land, the URI's
scheme, authority and request target, form and binary bodies, the
form-complexity ceilings, response cookies, streaming, and the `400` for
a body the environment can't parse, whose fixed message is
`RuntimeAdapterInterface::MALFORMED_BODY_MESSAGE`) runs against it with
no further test code.

An adapter never parses a form body itself. It delivers the raw bytes,
and `RequestBodyMiddleware` applies `Kinetis\Http\Form` to them inside
the Kernel — which is what keeps the accepted spellings, the nesting, and
the point at which a client is refused identical under every runtime.

An adapter handed a `Kinetis\Runtime\StreamableResponseInterface` sends
the status and headers from the response itself, then invokes
`getEmitter()` — that closure writes body bytes and nothing else. The
request's `RequestScope` is still alive while it runs, so a controller's
streaming code resolves from its own container, and the scope is released
as soon as the emitter returns. An adapter that can't stream calls
`abandon()` on the response and answers with its own instead: that
releases the same scope, on the same request, without writing a byte of
the body. Settling one of those two ways is the whole contract — the
Kernel's own release at the start of the next request is the defensive
path for a response that reached neither, and it logs a warning naming
the method and path when it fires.

An emitter that throws is the one failure an adapter contains rather than
lets propagate, for the reason given under "FrankenPHP and PHP-FPM"
above.

(runtime-reference-fibers)=
## Fiber scheduling

`Kinetis\Async` is a thin layer over [Revolt](https://revolt.run/), the
AMPHP v3 event loop. PHP Fibers are cooperative coroutines; they need an
event loop scheduling I/O around them.

### The suspend/resume pattern

Every non-blocking primitive in `Kinetis\Async` is built the same way:
capture the currently-running `Fiber`, register a Revolt watcher for
whatever condition it waits on, then suspend — and let the watcher's
callback resume the Fiber once that condition is met.

```{code-block} php
:caption: The pattern Socket and Timer both reduce to

use Revolt\EventLoop;

$fiber = Fiber::getCurrent();

EventLoop::onReadable($stream, static function (string $watcherId) use ($fiber): void {
    EventLoop::cancel($watcherId);
    $fiber?->resume();
});

Fiber::suspend();
```

Called outside a Fiber there is nothing to resume, and `Fiber::suspend()`
throws PHP's own `FiberError`. `concurrently()` does not need a
surrounding Fiber: its caller waits on a Revolt suspension, which runs
the loop from ordinary code.

### Resident Fibers

Each task runs in its own `Fiber`, drawn from `Kinetis\Async\FiberPool`,
a pool of resident Fibers that park between tasks instead of terminating.
Constructing a `Fiber` allocates a whole C stack and destroying it frees
one, so reusing a parked resident keeps a fan-out from paying that cost
per task. The pool is per PHP thread and keeps at most 64 idle Fibers. A
task suspended on I/O keeps its Fiber until it finishes, and a wider
burst still runs, on fresh Fibers that aren't retained afterwards.

A resident Fiber outlives the task that parked it, so the next task to
run on it may belong to a later batch — and, in a persistent worker, to a
later request. Anything that attaches Fiber-local or Fiber-keyed state
must detach or release it before the task returns, on both the success
and the failure path, or a later task inherits it. Fiber identity is the
carrier that executes a task, not an identifier for that task, its batch,
or its request; do not key anything on it that has to outlive the task.
Span scopes follow this rule — see {ref}`telemetry-fiber-scopes`.

### Waiting and completion

While tasks are in flight, the caller waits on a Revolt suspension that
the last task to finish resumes — the event loop drives every suspended
task no matter how many times each one suspends internally, or in what
order they finish. Results come back in task order. A task that fails
does not abort the others; the first failure in task order is rethrown
once every task has finished. A task that suspends with nothing
registered to resume it surfaces as `Kinetis\Async\Exception\DeadlockException`
rather than a hang.

### What each client waits on

A task overlaps with its siblings only at the points where it suspends:

- The native PostgreSQL driver registers readable and writable watchers
  on its socket.
- The native MySQL driver has no socket to watch — mysqli exposes none —
  and polls `mysqli_poll()` from a repeating loop timer. That poll carries
  a `select()`-based descriptor ceiling of its own; see
  {doc}`performance-tuning`'s "mysqli's poll limit".
- `Kinetis\Redis\Client` and `Amp\Redis\RedisClient` over it wait on
  `Amp\Future`.
- `Kinetis\RevoltHttpClient\Http` waits through Symfony's
  `AmpHttpClient`.
- A PDO client never suspends. A task that runs PDO statements finishes
  before `FiberPool` hands the next task a Fiber, so PDO tasks run one
  after another whatever else the batch holds.

{ref}`database-reference-native-io` describes the native drivers' waits
in full.

(runtime-reference-aot-artifact)=
## The AOT artifact

### Format

`.kinetis-cache/compiled.php` is plain PHP returning a literal array, so
a boot `require`s it and has the data with no decoding step, and
OPcache's shared opcode cache — keyed by realpath, shared across every
worker process on a host — skips re-parsing it from the second request
on.

It is one file rather than one per discovery section because a boot
needs the same compile pass throughout: an HTTP boot reconstructs routes,
event listeners and plugin data, and the CLI reconstructs commands, event
listeners and plugin data. Reading them from separate files makes
"routes from one build, listeners from another" a state a mid-deploy
request can land in. Reading them from one file makes it
unrepresentable. Reconstruction is still only what an entry point uses —
an HTTP boot never builds a `CommandRegistry` — but never from a file the
rest of the artifact didn't come with.

A compiled artifact carries scalars, arrays and enum cases, and the
build refuses any other object anywhere in one with
`UnexportableArtifactException`, a compile defect rather than a
persistence failure.

### Contents

- The route table.
- Command definitions.
- The `#[AsGlobalMiddleware]`-discovered class list, already priority-sorted.
- The `#[AsOpenApiMiddleware]`-discovered class list, published as the
  built-in `openapi` middleware group, already priority-sorted.
- The `#[AsMiddlewareGroup]`-declared groups, each group's own members
  already priority-sorted.
- The `#[Listener]`-discovered event listener list, grouped by event class,
  already priority-sorted.
- HTTP parameter-binding plans (how each request's data maps onto your
  controller method's parameters).
- DTO validation plans.
- The installed packages' bootstrap-class list (declared via
  `extra.kinetis` — see {doc}`cli`), so production never re-reads
  `vendor/composer/installed.json` per request.
- Every installed package's own `CacheableDiscoveryInterface` data —
  declared via `extra.kinetis`'s `discovery` key, also see {doc}`cli`.
  MCP tools and resources are one such entry — see {doc}`mcp`.

`GlobalMiddlewareDiscovery::discoverAll()` performs exactly one
project-wide scan for all three middleware attributes, not three — see
{doc}`middleware`.

Installed Latte or Twig view adapters use their own generated directories
under the same cache root. They are separate cache layers and are never
folded into `compiled.php`:

```{code-block} text
.kinetis-cache/
├── compiled.php    routes + global/openapi middleware + named middleware
│                   groups + HTTP binding plans + validation plans for
│                   DTOs reachable from HTTP routes + command definitions
│                   + event listeners grouped by event class + every
│                   installed package's own CacheableDiscoveryInterface
│                   data + the package bootstrap-class list
└── views/          generated only by a compiled-template adapter
    ├── latte/      present when kinetis/views-latte is selected
    └── twig/       present when kinetis/views-twig is selected
```

### Publishing atomically

`CacheStore::write()` never modifies the live file. It renders the whole
artifact into a uniquely-named temporary file beside it, `require`s that
file back to confirm it returns the array it was rendered from, and only
then `rename()`s it onto `compiled.php` — atomic within one directory on
POSIX, a directory-entry swap rather than a data copy. A reader sees the
complete previous artifact or the complete new one, never a partial
write. A publish that fails at any step leaves whatever was already
there untouched, and removes its own temporary file.

The rename is followed by `opcache_invalidate()` where OPcache is
loaded. It reaches the calling process's own OPcache and nothing else. A
boot that compiles in memory and publishes the result goes on serving
that in-memory bundle, so invalidation is not what makes the fallback
publish usable. What it covers is narrower: this process may already
have required a stale or rejected artifact from this path, and clearing
that entry makes a later include by this same process eligible to see
the replacement. It does not reach a separate serving pool, which is why
{doc}`caching` builds the artifact before workers start.

Concurrent publishers are not serialized. Workers cold-starting against
a missing artifact each compile once and each publish their own complete
copy; whichever rename lands last is what later readers get. Every racing
compile discovers the same classes, so the cost is bounded, one-time
per process, rather than a difference in what gets served.

### Missing, rejected and unwritable artifacts

An artifact that is missing, will not parse, does not return an array, or
carries a `CacheFormat::VERSION` this build does not speak is treated as
absent. So is one whose section a `fromArray()` rejects with a classified
artifact exception (`CacheArtifactExceptionInterface`). In each case the
boot compiles in memory and publishes the result. Any other exception
from reconstruction propagates. Nothing is retained, pinned, or
garbage-collected — there is one file, replaced in place.

A boot publishes only what it is already serving: the fresh compile is
reconstructed into live objects first, so one that cannot become them
fails the request instead of being published for the next process to
reject and recompile into the identical failure. That covers the
sections the entry point uses. `kinetis build`, which has no boot of its
own to serve, reconstructs the whole artifact — the route table, the
command list, the event listeners and every installed package's
`CacheableDiscoveryInterface` data — before writing, and a rejected
section fails the command and leaves the previous artifact in place.

A publish that fails with `CacheWriteException` — a read-only mount, a
full disk — does not take the application down. The boot serves the
value it compiled in memory and writes one line to the error log naming
the artifact it could not publish. Every later boot on that machine pays
the compile again and reports again, so a permanently unwritable cache
directory is visible rather than silent.

### MCP tool schemas

A tool's generated `inputSchema` is the one place this format needs care.
JSON Schema distinguishes the empty object `{}` from the empty array
`[]` — an empty `properties` map is one, an empty `required` list the
other — and a PHP array expresses only the second, so `JsonSchema` spells
`{}` as a live `stdClass`, which an artifact cannot carry. `McpRegistry`
therefore stores the schema as its own JSON text, in `inputSchemaJson`:
a plain string, and the one notation that already carries the
distinction, so every empty object and every empty array comes back the
type it went in as, at any depth. That text is the cache's own
representation of the schema, not the bytes a transport puts on the
wire. Text that will not parse, or a document whose root is not a JSON
object, is rejected as an invalid artifact, like a malformed route
entry: the boot falls back to live discovery and recompiles, rather than
serving a tool schema that no longer matches what the application
declares. The full rule is in {doc}`appendix-packages`'s `McpRegistry`
entry.

### Performance characteristics

For a persistent worker (FrankenPHP or RoadRunner), `Router::register()`
only ever runs once regardless of caching, since boot happens once for
the whole worker's lifetime. What still runs on every dispatch, cached or
not, is `Dispatcher`/`Hydrator`'s parameter-binding and validation-plan
derivation — precomputing that is typically 10-30% faster, with
query-parameter and validated-body-DTO routes seeing more benefit than
plain path-parameter routes.

For a boot-and-die runtime (PHP-FPM), the picture is different.
Reflecting an already-compiled class's metadata is cheap and roughly
constant per method, regardless of that method's body size — a
controller's file size isn't the lever. What matters is that live mode's
`Router::register()` has to autoload every registered controller class
on every request, because it can't know which route will match until
the whole table is built. The cached path never touches those files at
boot — `Router::fromArray()` holds class names as plain strings — so only
the one controller dispatched to gets autoloaded. For an
application with many controller classes, this makes the cached path
several times faster overall: controller-class count and file size are
the real lever at scale, not DTO or binding-plan count.

Cold-start time (container or process startup) dominates the
application-level difference at that scale, but a lazily compiled
deployment is still consistently the slowest cold configuration: its
first request pays the compile-and-write cost a pre-warmed deployment
already paid ahead of time.

## See also

- {doc}`runtime-adapters` — choosing and running a runtime, and the
  request-body and forwarded-header settings each one needs.
- {doc}`concurrency` — running independent I/O side by side, and when it
  overlaps.
- {doc}`caching` — building and deploying the AOT artifact.
- {doc}`appendix-testing` — the conformance suite that holds every
  adapter to this contract.
- {doc}`appendix` — the framework's runtime, async and cache namespaces.
