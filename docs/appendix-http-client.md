# Appendix: HTTP client contracts

The exact request, response and transport contracts behind
{doc}`revolt-http-client`. Start with the guide to install the client,
send a request and handle failures.

## What the client validates

The client owns the rules its guarantees rest on, and checks them before
a transport object exists: the URL and origin a credential is confined
to, the headers it owns, the bounds an operation runs under, and the set
of per-call options. Input it refuses throws `HttpRequestException` with
the `InvalidRequest` category and reaches no network.

Everything else — the request method, and the value types inside `json`,
`body`, and `query` — is Symfony's HTTP client's to validate, where that
grammar is already defined. When it refuses to construct a request, the
failure is also `InvalidRequest`, carrying neither the value it refused
nor the vendor's message. That failure is never retried: the same
request would be refused again.

(http-client-reference-urls)=

## Base URLs and request URLs

A base URL is an absolute `http`/`https` URL with no userinfo, no query
string, and no fragment. Its path is a **prefix that a relative target
extends**, never one a rooted target replaces:

```{code-block} php
$api = $http->withBaseUrl('https://api.example.com/v1');

$api->get('/orders');  // https://api.example.com/v1/orders
$api->get('orders');   // the same URL — the slash is normalized either way
```

Once a base URL is set, a request URL must be relative to it. An
absolute or scheme-relative URL there is refused. A client with no base
URL takes absolute URLs, and only absolute ones.

A `.` or `..` segment is refused rather than resolved, in both — written
plainly or percent-encoded, so `%2e%2e` is the same refusal as `..`. So
is a percent-encoded `/` or `\` inside a segment: a separator that
appears only after decoding hides the segments behind it, which is what
makes `%2e%2e%2fadmin` one segment here and two wherever it is resolved.
The URL that goes on the wire is the URL you wrote.

Two more shapes are refused in a base URL and a request URL alike: a
**backslash** anywhere in it, which is not a URL character and which the
readers that accept it read as `/`; and any **byte outside printable
ASCII**, which has to be percent-encoded before it can be sent.

### Why a credential has one destination

A client carrying an `Authorization` or `Cookie` header, set for the
client or for one call, requires `withBaseUrl()`. Without a base URL the
call site chooses the whole URL, which would mean the call site chooses
who receives the credential. With one, every rule above holds — the
target is relative, an absolute or scheme-relative URL is refused, a
scheme downgrade is refused because it would be an absolute URL, and a
3xx is never followed — so the credential has one reachable destination.
There is no list of sensitive headers to strip on the way out, because
nothing this client sends leaves the origin it was configured for.

**The validated URL alone names where a request goes.** `Host` is
refused wherever you write it, in any casing. A `Host` of your own would
send this client's credentials to one URL while naming a different
server to a shared proxy in front of it, and there is no second
authority here for a proxy to be told about.

`Proxy-Authorization` is the credential this cannot cover, since it is
addressed to a proxy and not to the origin the base URL names. It is
refused rather than pinned to something it is not sent to.

A transport you inject can carry credentials or a base URI of its own,
in its default options. Those are invisible here and therefore unpinned;
see {ref}`http-client-reference-transports`.

### Redirects

Every request is sent with `max_redirects` set to 0, so a 3xx is a
terminal response: `status()` reports it and `header('Location')` is
there to read. Following a redirect means deciding, per response,
whether a new origin may see this client's `Authorization` header, its
cookies, and its body — including a plain-HTTP origin. That decision
belongs to the caller who knows what the credential is for, so the
client makes the redirect visible instead of acting on it.

## Headers

Header input has one shape: a string name, and a value that is a string
or a non-empty list of strings.

```{code-block} php
$http->withHeaders([
    'X-Tenant' => 'acme',                // one value
    'X-Feature' => ['beta', 'preview'],  // several values for one name
]);
```

A name is an RFC 9110 token. Anything else as a value is refused rather
than converted — a number, a boolean, `null`, a `Stringable`, a
resource, an iterator. What a cast would produce is not what you wrote,
and a header is not the place to find that out. A value carrying CR, LF,
NUL, or another control character is refused outright: that is response
splitting, not a header.

**Within one array a name appears once.** Two spellings of one field
name in the same array — `'Authorization'` and `'authorization'` — carry
no order that HTTP itself would honour, so the array is refused rather
than one spelling being picked for you. Repetition is expressed by the
list form.

Precedence lives *between* arrays: a later `withHeaders()` overrides an
earlier one for the same name, case-insensitively, and a per-call header
overrides a configured one the same way — never sending both as
ambiguous duplicates:

```{code-block} php
$api = $http->withHeaders(['Authorization' => 'Bearer old-token']);

// Overrides the configured Authorization, casing and all — only one
// Authorization header is ever sent.
$api->send('GET', '/orders', ['headers' => ['authorization' => 'Bearer new-token']]);
```

Three names are this client's own and are refused wherever you write
them: `Host` and `Proxy-Authorization`, for the reasons above, and
`Accept-Encoding`, because the response-byte ceiling depends on the
identity encoding this client asks for — see
{ref}`http-client-reference-ceiling`.

## Query parameters and bodies

Query parameters are passed as an array, written into an absolute URL,
or both. `withQuery()` adds parameters to every request, and a verb's or
`send()`'s own array is merged over them. A URL's own query string is
passed through byte for byte when no array accompanies it, which is what
a signed URL needs; an array alongside it is merged and re-encoded by
the transport, so pick one for a URL whose exact bytes matter.

Bodies are the transport's to encode. `post()`/`put()`/`patch()`/
`delete()` send JSON; `asForm()` switches them to
`application/x-www-form-urlencoded`. Either way the `Content-Type` is a
default a header of your own overrides. A value that cannot be encoded
is refused as `InvalidRequest`, without the value or the vendor message
in it.

(http-client-reference-send)=

## `send()` options and streamed bodies

`send()` is the general form — any method, a raw body, an upload, a
header only this call needs:

```{code-block} php
$http->withBaseUrl('https://api.example.com')->send('POST', '/documents', [
    'headers' => ['Content-Type' => 'application/pdf'],
    'body' => fopen($path, 'r'),
]);
```

Its `$options` is an exact map of what this client can check:
`headers`, `query`, `json`, `body`, and `timeout`. `json` and `body` are
exclusive, and `timeout` replaces the client's total budget for this
call. Anything else is refused, and the transport's own retry, redirect,
duration, credential and buffering options are among them: each belongs
to a `with*` method, so a per-call setting can never sit alongside, and
disagree with, the client's own policy.

`body` is the one place you can hand over something this package cannot
inspect: a **stream** resource or a `Closure`. Those are sent as they
are, and a stream cannot be made replayable: it is consumed as it is
read. A client with retries configured refuses one outright on a method
it retries rather than sending a second request with a body that is
already gone. A method it never retries, such as the `POST` above, is
sent once and takes one on any client; a streamed `PUT` is sent from a
client without retries.

(http-client-reference-deadline)=

## Deadline and retries

### The deadline

`withTimeout()` is the budget for the **whole operation** — every
attempt, every backoff between them, and every read of the response that
comes out of it — not a fresh allowance per attempt. It is a finite
number of seconds greater than zero, and defaults to
`Http::DEFAULT_TIMEOUT_SECONDS`, 30. Running out throws
`HttpRequestException` with the `Timeout` category.

It is measured on a **monotonic** clock, so a clock correction during a
long request cannot shorten or extend it, and it is enforced by this
client rather than only handed to the transport. Every attempt is given
what is left of the budget as `timeout` and `max_duration`, and a
transport is free to ignore both — so the deadline is checked again
before and after every read, and from inside the transfer through the
same progress hook the byte ceiling uses. A transport that blocks past
the budget and then answers gets a `Timeout`, not a late success. What
cannot be done from here is interrupting it mid-block; nothing in PHP
does that.

### Retries

`withRetries()` is the only retry layer there is, and it retries only a
request whose method is exactly `GET`, `HEAD`, `OPTIONS`, `TRACE`,
`PUT`, or `DELETE` — the methods RFC 9110 defines as idempotent. It
sends such a request again, up to `$times` more times (at most 10), with
backoff doubling from 100 ms, for:

- a transport failure — DNS, a refused connection, a dropped socket;
- a status the server itself marks as worth repeating: 429, 500, 502,
  503, 504.

Every other status is an answer, returned as it is — repeating the
request cannot change a 404. Running out of retries is not itself a
failure either: the last answer the server gave is the answer you get. A
transport failure that outlives them has no answer to give back, so it
throws. A request this client or the transport refused, a timeout, and a
response past the byte ceiling are never retried.

Backoff waits inside the one deadline. When the next one would not fit,
the last response received is returned rather than waited past the
budget; with no response in hand, the transport failure propagates.

**Every other method is sent once**, `POST` and `PATCH` included, even
by a client with retries configured. Neither a transport failure nor a
retryable status proves such a request was not applied: the first leaves
its outcome unknown, and a 503 can follow work the server already did.
The method is matched exactly, and nothing else about the request — an
idempotency-key header included — changes the decision.

A client with retries waits for the response status inside `send()` on
a method it retries, since that status is what the decision is made on.
Every other request — any method on a client without retries, and a
method this client never retries on one with them — returns from
`send()` as soon as it is issued, and every read stays deferred: the
path that lets `concurrently()` overlap requests, and the one where a
`POST`'s transport failure raises from the read that meets it.

Every response an attempt abandons is released as the loop abandons it,
so a retried request costs one connection rather than one per attempt.

(http-client-reference-ceiling)=

## Response byte ceiling

`withMaxResponseBytes()` is the ceiling one response body may reach, in
bytes. It defaults to `Http::DEFAULT_MAX_RESPONSE_BYTES` — 8 MiB —
because an upstream you do not control decides how much it sends, and a
worker that buffers whatever arrives is a worker one reply can exhaust.

**Every request asks for identity encoding**, and `Accept-Encoding` is
not yours to set. That is what makes the ceiling a bound on memory
rather than on bytes off the wire: given no `Accept-Encoding` of its
own, the Amp-backed Symfony transport asks for gzip and inflates the
body transparently, so a kilobyte of gzip becomes a megabyte held before
anything can measure it. Asking for identity turns that inflation off,
and the bytes counted are the bytes kept. A server is free to answer
with a compressed body anyway — it then arrives, and is bounded, as the
compressed bytes it is, and `Content-Encoding` is on the response for
you to read. The cost is plain: this client trades compressed transfers
for a ceiling that means what it says.

Within that, the ceiling is checked at each of the three points a body
can pass it, so no path ends with the whole of an untrusted reply in
memory:

- a `Content-Length` larger than the ceiling fails before any body is
  fetched;
- a transfer that passes the ceiling as it arrives is aborted there,
  which is what covers a response declaring no length or declaring one
  it exceeds;
- what did arrive is measured before it is handed back, so a transport
  that ignored the first two checks is caught by the one that needs
  nothing from it.

Exactly the ceiling is a body like any other; one byte past it throws
with the `ResponseTooLarge` category, and the response is released
rather than left holding a connection nothing will read.

The refusal surfaces from whichever read reaches it. Usually that is
`body()`, `json()`, or `jsonPath()`. It can also be `status()` — a
transport delivers body bytes while it answers a status wait, and a
client with retries waits for the status inside `send()` on every method
it retries. What the ceiling never does is fetch a body nobody asked
for: a `HEAD` request, or a status that arrives before any body does,
costs nothing.

The ceiling owns the transport's progress hook, which is why
`on_progress` is not a per-call option: a hook of your own would replace
the one enforcing this. The same hook enforces the deadline, so a
transfer still arriving after the budget is spent is stopped there. A
transport is free to wrap what that hook raises; which failure you get
is decided from the budget's own state rather than from the exception's
type, so a wrapped abort is still reported as the ceiling or the
deadline it was.

(http-client-reference-response)=

## Reading a response

Reading is deferred until something asks for the body, status, or
headers. The body is read once and kept, so `body()` and `json()`
together fetch once. `header()` returns the first value of a header, and
`headers()` every value, keyed by lowercase name.

`json()`/`jsonPath()` expect a JSON object or array — anything a JSON
API returns for a resource. A body that is valid JSON but whose
top-level value is a bare string, number, boolean, or `null` throws with
the `Conversion` category the same way invalid JSON does, reporting the
decoded value's type rather than the value. `jsonPath()` returns its
`$default`, `null` unless given, when nothing is at the path.

An integer too large for PHP's own int type is decoded as a **string**
rather than rounded into a float, so an API that keys resources by ids
beyond JavaScript's safe integer range hands back its digits exactly as
they were sent.

### Giving a response back

`HttpResponse` owns the underlying transport response for as long as it
lives. `discard()` is how you end that early, for a response whose
status was all you wanted:

```{code-block} php
$response = $http->send('HEAD', 'https://api.example.com/large-report');
$exists = $response->successful();
$response->discard();
```

It never throws and never blocks: cancelling is a local operation, and a
transport that raises while being cancelled has nothing left to tell a
caller who already said they were done. Calling it twice does nothing.
Every read after it fails with the `Discarded` category, an earlier full
read included, rather than returning an undefined result.

A response nobody discards releases the same way when PHP collects the
object, without blocking and without raising from wherever the
collection happened. That fallback is what keeps an ignored response
from holding a connection for as long as the object happens to live; it
is not the API to reach for, because *when* a collection happens is
PHP's decision and not yours. `discard()` is the one that releases at a
moment you chose. A body read to its end leaves nothing to release, and
neither path cancels a response that is already complete.

(http-client-reference-failures)=

## Failures

One exception type, `Kinetis\RevoltHttpClient\Exception\HttpRequestException`,
covers everything this client throws, across validation, transport,
timeout, status, and decoding. Its `category` is an `HttpFailure` chosen
at the point of failure from the fixed list in the
{doc}`revolt-http-client` guide, which also states what `Transport` and
`Timeout` mean for a write. `getMessage()` is prose. `status` is the
HTTP status for `ErrorStatus` and `Conversion`, and 0 otherwise.

`InvalidRequest` covers everything this client or the transport refused
to send — a misconfigured client, a per-call option, a body value — and
always means the same thing: nothing reached the network, and a repeat
would be refused the same way.

```{warning}
An exception from this package carries the request method, the origin
(scheme, host, and non-default port), an HTTP status, and a category —
and nothing else. No path, no query string, no userinfo, no header, no
credential, no request or response body. A vendor exception is never
chained and its message is never copied either, because a lower-level
HTTP or DNS client routinely names the full URI it failed on, userinfo
and all, and an exception message is the one thing a logging pipeline
records by default.

`getMessage()`, `(string) $e`, and `getTraceAsString()` all stay within
that: parameters that forward your input are marked
`#[\SensitiveParameter]`, so a rendered trace shows a redaction marker
where an argument would have been. What PHP puts there is a
`SensitiveParameterValue` object that still holds the value, so
`getTrace()` and `serialize($e)` — anything reading trace *arguments*
rather than rendering them — are not safe to forward.

The upstream's own error payload is where an API explains itself, and it
is read from the response — the one place where taking it is a
decision:

    if ($response->failed()) {
        $log->warning('upstream said', ['body' => $response->body()]);
    }
```

(http-client-reference-transports)=

## Injected transports and tests

`Http` takes any Symfony `HttpClientInterface`, so a test substitutes one
without touching the network:

```{code-block} php
use Kinetis\RevoltHttpClient\Http;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new Http(new MockHttpClient([
    new MockResponse('{"id": 42}', ['http_code' => 200]),
]));

self::assertSame(42, $http->get('https://api.example.com/orders/42')->jsonPath('id'));
```

The guarantees in the guide and on this page are this client's, and
three of them need the transport's cooperation:

- **It must make one wire attempt per request.** A retry layer under
  this one multiplies the attempts and spends the total timeout outside
  it, where nothing counts it. The default transport
  `AmpHttpClientFactory::create()` builds makes one attempt; a transport
  you supply is yours to keep to one.
- **It must not carry credentials or a base URI of its own.** Default
  options set on the transport are invisible here, so origin pinning
  cannot pin them. A transport that carries them answers for where they
  go.
- **It suspends, or it blocks.** Only the Revolt-backed default suspends
  the calling Fiber. A synchronous Symfony transport — `CurlHttpClient`,
  `NativeHttpClient` — is accepted and blocks the process for the length
  of the request, so nothing else on the event loop runs meanwhile. The
  timeouts, retries, and ceiling still apply to it; the concurrency does
  not.

## The transport on its own

`AmpHttpClientFactory::create()` returns the underlying
`Symfony\Contracts\HttpClient\HttpClientInterface` — a thin factory
around `Symfony\Component\HttpClient\AmpHttpClient`, backed by the
current, Revolt-based `amphp/http-client` generation. Use it where a
library wants to be handed a client of its own:

```{code-block} php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create(
    defaultOptions: ['timeout' => 5],
    maxHostConnections: 10,
);

$response = $client->request('GET', 'https://example.com/');
```

It takes Symfony's default request options, a client configurator, and
connection limits (`maxHostConnections` defaults to 6). One request
through it is one wire attempt: the Amp delegate is the connection pool
itself, with no interceptor above it to repeat a failed request, where
Symfony's own `AmpHttpClient` default installs AMPHP's `RetryRequests`.
That leaves the retry decision with whoever can count it —
`withRetries()` for `Http`, which is built on this same transport, or an
SDK's own retry policy for a client handed one. Pass a
`$clientConfigurator` to build the delegate yourself, and the
interceptors it installs are yours.

```{warning}
This is a plain Symfony client, not the boundary `Http` puts in front of
one. Nothing else on this page applies to it. Symfony's full option
grammar, its streaming API, its own exception types, its redirect
following (up to 20 redirects by default), and its own size and
lifecycle behavior are what you get, and whatever you hand it is what it
does: no preflight validation, no origin pinning, no owned retry layer,
no total deadline, no response-byte ceiling.
```

### Outside Kinetis

This package depends on nothing beyond `symfony/http-client` (and its
`symfony/http-client-contracts`), `amphp/http-client`, and
`revolt/event-loop` — no `kinetis/framework`, and no Kinetis-specific
class anywhere in it. `composer require kinetis/revolt-http-client` in
an unrelated project is a complete install, of `Http` and of the
transport.

The transport is what an AsyncAws client accepts: every service client
extends `AsyncAws\Core\AbstractApi`, whose constructor takes an optional
`?HttpClientInterface $httpClient` as its third argument:

```{code-block} php
use AsyncAws\S3\S3Client;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$s3 = new S3Client(['region' => 'us-east-1'], null, AmpHttpClientFactory::create());
```

Any other library that accepts an injectable `HttpClientInterface` —
Symfony's own components, or a third-party SDK built the same way —
takes it the same way.

## See also

- {doc}`revolt-http-client` — send a request, configure a client and
  handle failures.
- {doc}`concurrency` — overlapping requests with `concurrently()`.
- {doc}`telemetry` — the tracing transport for outgoing requests.
