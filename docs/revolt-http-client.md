# HTTP Client

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/revolt-http-client
```
````

Calling another service over HTTP, without blocking the rest of your
application while you wait on it. Requests suspend the calling Fiber, so
a worker keeps serving other requests during the round trip.

## Making requests

`Kinetis\RevoltHttpClient\Http` autowires with no registration — inject
it and call it:

```{code-block} php
use Kinetis\RevoltHttpClient\Http;

final readonly class ShipmentTracker
{
    public function __construct(
        private Http $http,
        private string $apiKey,
    ) {}

    public function track(string $number): array
    {
        return $this->http
            ->withBaseUrl('https://api.carrier.test')
            ->withToken($this->apiKey)
            ->get('/shipments', ['number' => $number])
            ->throw()
            ->json();
    }
}
```

`get()`, `post()`, `put()`, `patch()`, and `delete()` take arrays — a
query array for `get()`, a body array for the rest, JSON-encoded by
default. `send()` is the general form for anything they don't cover.

```{note}
Autowiring builds a fresh transport — and a fresh connection pool — per
request. Under a persistent worker, register a configured client on
`AppScope` in `bootstrap.php` instead, so the pool and its keep-alive
connections outlive the request rather than paying a new TCP and TLS
handshake to the same host every time:

    $app->instance(Http::class, new Http()->withTimeout(10));

`Http` is immutable and holds no per-request state, so one shared
instance is safe by construction — and call sites still specialize it
per call (`$http->withToken(...)`) without affecting the shared one.
Under boot-and-die PHP-FPM the distinction costs nothing either way.
```

## Configuring a client

Every `with*` method returns a new instance rather than changing the one
you called it on, so a configured client is safe to hold as a shared
service and specialize per call:

```{code-block} php
$api = $http->withBaseUrl('https://api.example.com')->withToken($key);

$api->get('/orders');                         // uses the shared config
$api->withTimeout(30)->get('/reports/large'); // just this call
```

| Method | Effect |
|---|---|
| `withBaseUrl(string)` | the origin and path prefix every request extends |
| `withToken(string, string $scheme = 'Bearer')` | sets `Authorization` |
| `withBasicAuth(string, string)` | HTTP basic credentials, as `Authorization` |
| `withHeaders(array)` | adds headers, overriding a same-named one already set |
| `withQuery(array)` | query parameters added to every request |
| `withTimeout(float)` | the total budget for one operation, in seconds |
| `withRetries(int $times = 3)` | extra attempts for a failure worth repeating |
| `withMaxResponseBytes(int)` | the ceiling a response body may reach |
| `asForm()` | sends array bodies as `application/x-www-form-urlencoded` |

Every one of them validates what it is given, and so does every verb
method, before any transport object exists. Input this client will not
send is refused with `HttpRequestException` and reaches no network at
all — there is no partially-configured client and no half-sent request.

## Base URLs and how paths join

A base URL is an absolute `http`/`https` URI with no userinfo, no query
string, and no fragment. Its path is a **prefix that a relative target
extends**, never one a rooted target replaces:

```{code-block} php
$api = $http->withBaseUrl('https://api.example.com/v1');

$api->get('/orders');  // https://api.example.com/v1/orders
$api->get('orders');   // the same URL — the slash is normalized either way
```

Once a base URL is set, a request URL must be relative to it. An
absolute URL there is refused, which is what keeps a configured
`Authorization` header on the origin it was issued for. A client with no
base URL takes absolute URLs, and only absolute ones.

A `.` or `..` segment is refused rather than resolved, in both — written
plainly or percent-encoded, so `%2e%2e` is the same refusal as `..`. So
is a percent-encoded `/` or `\` inside a segment: a separator that
appears only after decoding hides the segments behind it, which is what
makes `%2e%2e%2fadmin` one segment here and two wherever it is resolved.
The URL that goes on the wire is the URL you wrote.

Three more shapes are refused for the same reason, in a base URL and a
request URL alike:

- a **backslash** anywhere in it, which is not a URL character and which
  the readers that accept it read as `/`;
- a **host in anything but its canonical spelling**. Three forms are
  canonical and nothing else is: a DNS name, a dotted-quad IPv4 address,
  and a bracketed IPv6 literal that parses as one. `[:::]` and `[.]` are
  brackets around something that is not an address; `2130706433` and
  `127.1` are read as `127.0.0.1` by a resolver and as neither by a
  reader;
- a **port outside 1–65535**.

Each of them is a URL that reads as one origin and resolves as another.

## Credentials belong to one origin

A client carrying an `Authorization`, `Cookie`, or `Proxy-Authorization`
header — set by `withToken()`, `withBasicAuth()`, or `withHeaders()`,
for the client or for one call — requires `withBaseUrl()`:

```{code-block} php
// Refused: nothing says which origin may see this token.
$http->withToken($key)->get('https://api.example.com/orders');

// Sent: the token can reach api.example.com and nowhere else.
$http->withBaseUrl('https://api.example.com')->withToken($key)->get('/orders');
```

Without a base URL the call site chooses the whole URL, which would mean
the call site chooses who receives the credential. With one, every rule
above already holds — the target is relative, an absolute or
scheme-relative URL is refused, a scheme downgrade is refused because it
would be an absolute URL, and a 3xx is never followed — so the
credential has one reachable destination.

**Reaching a second origin is a second client**, configured with the
credential that origin should see. That is the whole mechanism: there is
no list of sensitive headers to strip on the way out, because nothing
this client sends ever leaves the origin it was configured for.

`Proxy-Authorization` is the credential this cannot cover, since it is
addressed to a proxy and not to the origin the base URL names. It is
refused rather than pinned to something it is not sent to.

A transport you inject can carry credentials of its own, in its default
options. Those are invisible here and therefore unpinned; see [what an
injected transport must be](#what-an-injected-transport-must-be).

## Headers

Header input takes exactly two forms — the associative one and the raw
`"Name: value"` line one — and **one array uses one of them
throughout**:

```{code-block} php
$http->withHeaders([
    'X-Tenant' => 'acme',                // one value
    'X-Feature' => ['beta', 'preview'],  // several values for one name
]);

$http->withHeaders([
    'X-Request-Id: 0f9b',                // the raw "Name: value" line form
    'X-Tenant: acme',
]);
```

An array holding both notations is refused rather than read as one: two
ways of writing the same thing in the same array is a sign the two
halves came from different places, and merging them is a guess. Mix
freely *between* calls instead — each `withHeaders()` takes whichever
form suits it.

A name is an RFC 9110 token; a value is a string, or a non-empty list of
strings. Anything else is refused rather than converted — a number, a
boolean, `null`, a `Stringable`, a resource, an iterator. What a cast
would produce is not what you wrote, and a header is not the place to
find that out.

A value carrying CR, LF, NUL, or another control character is refused
outright: that is response splitting, not a header.

Two names are this client's own and are refused wherever you write them:

- **`Accept-Encoding`**, because the response-byte ceiling depends on
  the identity encoding this client asks for — see [How much of a
  response is read](#how-much-of-a-response-is-read).
- **`Proxy-Authorization`**, because a proxy credential is addressed to
  a proxy rather than to the request's own origin, so a base URL cannot
  confine it the way it confines the others. A proxy that needs
  credentials belongs on a transport of your own.

**Within one array a name appears once.** Two spellings of one field
name in the same array — `'Authorization'` and `'authorization'` —
carry no order that HTTP itself would honour, so the array is refused
rather than one spelling being picked for you. Repetition is expressed
by the list form.

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

## Query parameters and bodies

Query parameter values are strings, numbers, booleans, or nested arrays
of those. `null` is refused, because URL encoding drops it and the
parameter you wrote would silently not be sent; so is a non-finite
float, and so is any object.

Query parameters are passed as an array, or written into an absolute
URL, but not both at once — the two have no defined order, so a request
carrying both is refused rather than merged into a guess. An absolute
URL's own query string is passed through byte for byte, which is what a
signed URL needs.

A body array is encoded here, not by the transport, so a value that
cannot be encoded fails as this package's own exception rather than a
vendor one carrying the value that failed. `post()`/`put()`/`patch()`/
`delete()` send JSON; `asForm()` switches them to
`application/x-www-form-urlencoded`. Either way the `Content-Type` is a
default a header of your own overrides.

## The `send()` escape hatch, and its limits

`send()` is the general form — a raw body, an upload, a header only this
call needs:

```{code-block} php
$http->send('POST', 'https://api.example.com/documents', [
    'headers' => ['Content-Type' => 'application/pdf'],
    'body' => fopen($path, 'r'),
]);
```

Its `$options` is an exact map of what this client can check:
`headers`, `query`, `json`, `body`, and `timeout`. Anything else is
refused, in one of two ways:

- An option this client owns — `base_uri`, `max_redirects`,
  `max_retries`, `retry_failed`, `retry_strategy`, `max_duration`,
  `auth_basic`, `auth_bearer`, `on_progress`, `buffer` — names the
  builder that sets it. A per-call retry, redirect, or size setting
  cannot sit alongside, and disagree with, the client's own policy,
  because it is never accepted in the first place.
- Any other option is refused as unsupported. An option this boundary
  cannot check is an option none of the guarantees on this page would
  still hold for.

`body` is the one place you can hand over something this package cannot
inspect: a **stream** resource or a `Closure`. Those are sent as they
are — and that is the limit of what `send()` can promise. A resource
that is not a stream has no bytes to send and is refused as plainly as a
value of the wrong type. It cannot validate bytes it never sees either,
and it cannot make a stream replayable: a stream is consumed as it is
read, so a client with retries configured refuses one outright rather
than sending a second request with a body that is already gone. Send it
from a client without retries.

## Timeouts and retries

`withTimeout()` is the budget for the **whole operation** — every
attempt, every backoff between them, and every read of the response that
comes out of it — not a fresh allowance per attempt. It is a finite
number of seconds greater than zero, and defaults to 30. Running out
throws `HttpRequestException` with the `Timeout` category.

It is measured on a **monotonic** clock, so a clock correction during a
long request cannot shorten or extend it, and it is enforced by this
client rather than only handed to the transport. Every attempt is given
what is left of the budget as `timeout` and `max_duration`, and a
transport is free to ignore both — so the deadline is asked again after
the request is issued, after every read that answers, and from inside
the transfer through the same progress hook the ceiling uses. A
transport that blocks past the budget and then answers gets a `Timeout`,
not a late success. What cannot be done from here is interrupting it
mid-block; nothing in PHP does that.

`withRetries()` is the only retry layer there is. It sends the request
again, up to `$times` more times (at most 10), with exponential backoff
from 100 ms, for:

- a transport failure — DNS, a refused connection, a dropped socket, or
  a transport that refused to build the request at all;
- a status the server itself marks as worth repeating: 423, 425, 429,
  500, 502, 503, 504, 507, 509.

Every other status is an answer, returned as it is — repeating the
request cannot change a 404. Running out of retries is not itself a
failure either: the last answer the server gave is the answer you get. A
transport failure that outlives them has no answer to give back, so it
throws. A response past the byte ceiling is never retried: the same
request would fetch the same oversized body again.

```{code-block} php
$resilient = $http->withBaseUrl('https://api.example.com')->withRetries(3)->withTimeout(10);
```

Two things follow from owning the retry layer here:

- A retrying client waits for the response status inside `send()`, since
  that status is what the decision is made on. Without retries, `send()`
  returns as soon as the request is issued and every read stays
  deferred — the path that lets `concurrently()` overlap requests.
- Retrying a request that is not idempotent is your call to make.
  Neither a 5xx nor a dropped connection proves the server did not
  already act on it.

Every response an attempt abandons is released as the loop abandons it,
so a retried request costs one connection rather than one per attempt.

## How much of a response is read

`withMaxResponseBytes()` is the ceiling one response body may reach, in
bytes. It defaults to `Http::DEFAULT_MAX_RESPONSE_BYTES` — 8 MiB —
because an upstream you do not control decides how much it sends, and a
worker that buffers whatever arrives is a worker one reply can exhaust.

```{code-block} php
$reports = $http->withBaseUrl('https://api.example.com')->withMaxResponseBytes(64 * 1024 * 1024);
```

**Every request asks for identity encoding**, and `Accept-Encoding` is
not yours to set. That is what makes the ceiling a bound on memory
rather than on bytes off the wire: given no `Accept-Encoding` of its
own, a Symfony response inflates a compressed body transparently, so a
kilobyte of gzip becomes a megabyte held before anything can measure it.
Asking for identity turns that inflation off, and the bytes counted are
the bytes kept. A server is free to answer with a compressed body
anyway — it then arrives, and is bounded, as the compressed bytes it is,
and `Content-Encoding` is on the response for you to read. The cost is
plain: this client trades compressed transfers for a ceiling that means
what it says.

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
retrying client always waits for the status inside `send()`. What the
ceiling never does is fetch a body nobody asked for: a `HEAD` request,
or a status that arrives before any body does, costs nothing.

The ceiling owns the transport's progress hook, which is why
`on_progress` is refused as a per-call option: a hook of your own would
replace the one enforcing this. The same hook enforces the deadline, so
a transfer still arriving after the budget is spent is stopped there.

## Redirects are not followed

A 3xx is a terminal response: `status()` reports it and
`header('Location')` is there to read.

```{code-block} php
$response = $http->get('https://api.example.com/documents/42');

if ($response->redirect()) {
    $next = $response->header('Location');
}
```

Following a redirect means deciding, per response, whether a new origin
may see this client's `Authorization` header, its cookies, and its
body — including a plain-HTTP origin. That decision belongs to the
caller who knows what the credential is for, so this client makes the
redirect visible instead of acting on it. Re-issuing the request against
the new location, with credentials chosen and re-signed for it, is an
ordinary second call.

## Reading the response

```{code-block} php
$response = $http->get('https://api.example.com/orders/42');

$response->status();                    // 200
$response->successful();                // true for any 2xx
$response->json();                      // decoded body, as an array
$response->jsonPath('customer.email');  // one value, dot-delimited
$response->body();                      // the raw string
$response->header('X-Request-Id');
```

Reading is deferred until something asks for the body, status, or
headers. The body is read once and kept, so `body()` and `json()`
together fetch once.

`json()`/`jsonPath()` expect a JSON object or array — anything a JSON
API returns for a resource. A body that is valid JSON but whose
top-level value is a bare string, number, boolean, or `null` throws
`HttpRequestException` the same way invalid JSON does, reporting the
decoded value's type rather than the value.

An integer too large for PHP's own int type is decoded as a **string**
rather than rounded into a float, so an API that keys resources by ids
beyond JavaScript's safe integer range hands back its digits exactly as
they were sent.

**An error status is not an exception.** A 404 from an API you are
probing is information, and whether it should stop your code is your
decision, not the client's — so `failed()`, `clientError()`,
`serverError()`, and `redirect()` are answers you can branch on:

```{code-block} php
$response = $http->get("https://api.example.com/users/{$id}");

if ($response->clientError()) {
    return null;
}
```

`throw()` opts into the other behavior, and returns the response when it
succeeded so it chains:

```{code-block} php
$order = $http->get('https://api.example.com/orders/42')->throw()->json();
```

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
caller who already said they were done. Calling it twice, or after a
full read, does nothing. Reading after it is a defined failure — the
`Discarded` category — rather than an undefined result.

A response nobody discards releases the same way when PHP collects the
object, without blocking and without raising from wherever the
collection happened. That fallback is what keeps an ignored response
from holding a connection for as long as the object happens to live; it
is not the API to reach for, because *when* a collection happens is
PHP's decision and not yours. `discard()` is the one that releases at a
moment you chose. A body read to its end leaves nothing to release, and
neither path cancels a response that is already complete.

## Failures

One exception type covers everything this client throws, across
validation, encoding, transport, timeout, status, and decoding:

```{code-block} php
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;

try {
    $order = $http->withTimeout(5)->get('https://api.example.com/orders/42')->throw()->json();
} catch (HttpRequestException $e) {
    // $e->category is an HttpFailure: InvalidConfiguration, InvalidRequest,
    // Conversion, Transport, Timeout, ResponseTooLarge, ErrorStatus, or
    // Discarded.
    // $e->status is the HTTP status, or 0 when nothing answered at all.
    $recoverable = $e->category === HttpFailure::Timeout;
}
```

Branch on `category`, which is chosen at the point of failure from that
fixed list. `getMessage()` is prose.

```{warning}
An exception from this package carries the request method, the origin
(scheme, host, and non-default port), an HTTP status, and a category —
and nothing else. No path, no query string, no userinfo, no header, no
credential, no request or response body. A vendor exception is never
chained and its message is never copied either, because a lower-level
HTTP or DNS client routinely names the full URI it failed on, userinfo
and all, and an exception message is the one thing a logging pipeline
records by default.

That holds for the whole object, not just its message: `(string) $e`,
`getTraceAsString()`, `serialize($e)`, and `json_encode($e)` all stay
within it. Parameters that forward your input are marked
`#[\SensitiveParameter]`, so a stack trace shows a redaction marker
where an argument would have been.

The upstream's own error payload is where an API explains itself, and it
is read from the response — the one place where taking it is a
decision:

    if ($response->failed()) {
        $log->warning('upstream said', ['body' => $response->body()]);
    }
```

## Several requests at once

Because requests suspend rather than block, `concurrently()` (see
{doc}`concurrency`) overlaps them with no pooling API of its own:

```{code-block} php
use function Kinetis\Async\concurrently;

[$user, $orders] = concurrently([
    fn () => $http->get("https://api.example.com/users/{$id}")->json(),
    fn () => $http->get("https://api.example.com/users/{$id}/orders")->json(),
]);
```

Both round trips happen over the same period rather than one after the
other.

## Testing against it

`Http` takes any Symfony `HttpClientInterface`, so a test substitutes one
without touching the network:

```{code-block} php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$http = new Http(new MockHttpClient([
    new MockResponse('{"id": 42}', ['http_code' => 200]),
]));

self::assertSame(42, $http->get('https://api.example.com/orders/42')->jsonPath('id'));
```

### What an injected transport must be

The guarantees on this page are this client's, and three of them need
the transport's cooperation:

- **It must not retry.** A `RetryableHttpClient` is refused where you
  inject it: a second retry layer under this one multiplies the attempts
  and spends the total timeout outside it. Configure retries with
  `withRetries()` on the client that owns them. A retry layer *inside* a
  transport is invisible to that check, which is why `Http` builds its
  own transport with `AmpHttpClientFactory::createWithoutRetries()`; a
  transport you build yourself is yours to keep to one wire attempt.
- **It must not carry credentials or a base URI of its own.** Default
  options set on the transport are invisible here, so the origin pinning
  above cannot pin them. A transport that carries them answers for where
  they go.
- **It suspends, or it blocks.** Only the Revolt-backed default suspends
  the calling Fiber. A synchronous Symfony transport — `CurlHttpClient`,
  `NativeHttpClient` — is accepted and blocks the process for the length
  of the request, so nothing else on the event loop runs meanwhile. The
  timeouts, retries, and ceiling on this page still apply to it; the
  concurrency does not.

A decorator this client cannot see through — one wrapping a retrying
client, or implementing retries itself — is yours to keep honest.

## The transport on its own

`AmpHttpClientFactory::create()` returns the underlying
`Symfony\Contracts\HttpClient\HttpClientInterface` — a thin factory
around `Symfony\Component\HttpClient\AmpHttpClient`, backed by the
current, Revolt-based `amphp/http-client` generation rather than
Symfony's default `curl` transport. Use it where a library wants to be
handed a client of its own:

```{code-block} php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create();
$response = $client->request('GET', 'https://example.com/');
```

`create()` mirrors Symfony's own constructor, which means it also
inherits Symfony's own default: the Amp delegate is wrapped in an
interceptor that repeats a failed request twice more. That is a retry
layer, and it is one `Http` will not sit on top of, so `Http` builds its
transport with `createWithoutRetries()` instead — the same client with
the pooled delegate handed back untouched, one wire attempt per request.
Use that one wherever a second retry layer would be a problem.

```{warning}
This is a plain Symfony client, not the boundary `Http` puts in front of
one — a deliberate escape hatch for standalone use, kept because a
library that wants a client of its own wants a real one. Nothing on this
page applies to it. Symfony's full option grammar, its streaming API,
its own exception types, its redirect following (which does not know
what a credential is for), its Amp-level request retries, and its own
size and lifecycle behavior are what you get, and whatever you hand it
is what it does: no preflight validation, no origin pinning, no owned
retry layer, no total deadline, no response-byte ceiling.
```

## Using it outside Kinetis entirely

This package depends on nothing beyond `symfony/http-client` (and its
`symfony/http-client-contracts`) and `amphp/http-client` — no
`kinetis/framework`, no Kinetis-specific class
anywhere in it. `AmpHttpClientFactory::create()` returns a plain
`Symfony\Contracts\HttpClient\HttpClientInterface`, which is exactly what
gets accepted by:

- **Any AsyncAws client** — S3, SQS, SES, DynamoDB, or any of its other
  ~40 service clients all extend `AsyncAws\Core\AbstractApi`, whose
  constructor takes an optional `?HttpClientInterface $httpClient`:

  ```{code-block} php
  use AsyncAws\S3\S3Client;
  use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

  $s3 = new S3Client(['region' => 'us-east-1'], null, AmpHttpClientFactory::create());
  ```

- **Any other library** that accepts an injectable `HttpClientInterface`
  — Symfony's own components, or any third-party SDK built the same way.
  None of this requires the Kinetis framework itself to be installed;
  `composer require kinetis/revolt-http-client` on its own, in an
  unrelated project, is a complete, working install.

## Options

`AmpHttpClientFactory::create()` takes the same options you'd pass
directly to Symfony's HTTP client — default request options, a client
configurator callback, and connection limits:

```{code-block} php
AmpHttpClientFactory::create(
    defaultOptions: ['timeout' => 5],
    maxHostConnections: 10,
);
```

## See also

- {doc}`persistence` — MySQL, Postgres, and Redis, which run the same way:
  without blocking the rest of your application.
- {doc}`storage` — file storage that behaves the same way.
