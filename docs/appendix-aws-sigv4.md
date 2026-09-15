# Appendix: AWS SigV4 contracts

The exact origin, signing, transport and failure contracts behind
{doc}`aws-sigv4`. Start with the guide to configure an origin,
credentials and a signed Amazon OpenSearch Service client.

(aws-sigv4-reference-origin)=

## The trusted origin

`$origin` is mandatory, and it is the whole of what this client will
sign for. There is no mode that signs whatever host a request happens to
name.

It must be an absolute `http`/`https` URI: a scheme, a host, an optional
port, and an optional path prefix, and nothing else. The host is a
registered name or a dotted-quad IPv4 address, `http` included so a
LocalStack or other AWS-compatible local endpoint works; an IPv6 origin
is out of scope. Userinfo, a query string, a fragment, a percent sign or
backslash in the authority, whitespace or a control character anywhere,
a malformed percent escape, a `.` or `..` path segment, a non-numeric or
out-of-range port, and an invalid host are each rejected. Parsing
happens once, at construction, so a misconfigured endpoint fails
immediately rather than on the first request that needs it.

`$region` and `$service` are each 1 to 64 ASCII characters: a letter or
digit, then letters, digits, `.`, `-` or `_`. `$service` has no
default.

A configured origin, region, or service name that fails validation
throws `Kinetis\AwsSigV4\Exception\SigningException` from
`new SigV4SigningClient(...)`, not from `sendRequest()`. Its message
names the field and the rule, never the value — a rejected origin can
carry a password in its userinfo or a token in its query string.

Scheme and host are compared case-insensitively, and an absent port
means 80 for `http` and 443 for `https` — so `https://api.example.com`
and `https://API.Example.com:443` are the same origin.

Every request is checked against it before anything else happens:

- A relative request (`/users`, or `users`, which PSR-7 permits) is
  resolved against the origin. Its path is joined to the configured
  prefix with exactly one slash regardless of which side already
  supplies one, so `users` under the prefix `/prod` resolves to
  `/prod/users`, not `/produsers`. The query string is untouched.
- An absolute request must already name the origin exactly, and keeps
  the path it came with.
- The path prefix binds both: a target that lies outside it —
  `https://api.example.com/admin` under the origin
  `https://api.example.com/prod`, or a relative `../admin` — is
  rejected. The comparison is segment-wise, so `/production` does not
  pass for the prefix `/prod`.
- Anything else is rejected: another host, another port, an `http`
  target under an `https` origin, a `//host/path` network-path
  reference, userinfo, a target carrying a control character, a
  backslash, or a malformed percent escape.

A rejection throws
`Kinetis\AwsSigV4\Exception\UntrustedOriginException` before the
credential provider is called, before the request body is read, and
before the transport is touched.

## What is signed is what is sent

A signature covers a path and a query string, so a target that changes
between signing and sending is a signature over something that never
went out. HTTP clients normalize on the way out — `/a/../b` becomes
`/b`, `/%7Efoo` becomes `/~foo` — so this package normalizes first, by
its own rule, and checks the origin and the path prefix against that
final form:

- A percent escape standing for an unreserved character (`A-Z`, `a-z`,
  `0-9`, `-`, `.`, `_`, `~`) is decoded; RFC 3986 calls the two
  spellings one target.
- Every other escape keeps its bytes and takes uppercase hex digits.
  `%2F` stays an encoded slash rather than becoming a segment
  separator.
- A character outside the unreserved set, the sub-delimiters, `:`, `@`
  and `/` is percent-encoded.
- The path then has its repeated `/` collapsed to one, and its `.` and
  `..` segments removed after decoding, so a segment spelled `%2E%2E`
  counts as one. An empty path becomes `/`, and `//example//` becomes
  `/example/`.
- A fragment is dropped: it never reaches an HTTP request line.

The URI that is signed and sent is then built from the origin's own
canonical scheme, host and port, so the authority a signature covers is
the configured one however the request spelled it, and the outgoing
request itself is built by this package rather than carried over from
the caller's PSR-7 object. The rule applied to its own output changes
nothing, which is what makes the target the transport sends
byte-identical to the one the signature was computed over.

The practical consequence: `/prod/../../secrets` under the origin
`https://api.example.com/prod` is rejected rather than signed for
`/prod/../../secrets` and sent to `/secrets`.

(aws-sigv4-reference-canonical)=

## The canonical request

The signature is computed over the request in that final wire form.
Both sides of a SigV4 signature derive the same canonical text from the
same bytes, so the rules below are what the service applies too:

- **URI** — the wire path's own bytes percent-encoded segment by
  segment, with `/` left as `/`. Everything outside the unreserved set
  becomes `%XX`, the `%` of an escape already on the wire included: wire
  `/a%2Fb` is canonically `/a%252Fb`, and `/a/b` is `/a/b`. The request
  line carries neither — only the canonical text takes that second
  encoding layer, which is what the service applies to the target it
  receives, so two targets that differ on the wire sign apart.
- **Query** — each `&`-separated pair split at its first `=`, name and
  value decoded and re-encoded, and pairs sorted bytewise by encoded
  name then encoded value with duplicates kept. `?a=10&a=9`
  canonicalizes to `a=10&a=9`, since `1` sorts before `9`. A literal `+`
  is the plus character and encodes as `%2B`; a space is `%20`.
- **Headers** — names lowercased and sorted bytewise, values trimmed
  with internal whitespace collapsed to one space, and a repeated
  header's values joined with `,` in the order the request holds them.
  The outgoing request keeps every value separately: the join is
  canonical input, not what is sent.

`Host`, `X-Amz-Date`, `Authorization` and `X-Amz-Security-Token` belong
to the client and are written over whatever the request carried. `Host`
comes from the trusted origin's own authority, and the security-token
header is removed when the resolved credentials carry no token. Every
other header on the request is signed and sent as you wrote it —
`Content-Type` included, since it decides how a service reads the body —
apart from the payload-hash header below. A header added by the
transport's default options is sent but not signed.

The headers left out of the signature are the ones an HTTP client owns
on the way out: `Authorization`, `Content-Length`, `Expect`,
`User-Agent`, `Accept-Encoding`, `Connection`, `Transfer-Encoding`,
`TE`, and `Proxy-Authorization`. Signing one of those binds the
signature to a value this package does not control.

`X-Amz-Content-Sha256` is not added. Set one yourself — under any
spelling, however many times — and the request goes out carrying a
single value, the SHA-256 of the body bytes that were read. The header a
service reads as the payload hash names the same bytes the canonical
request does, so a value of your own cannot point the two at different
payloads.

## Redirects, retries and the transport

One `sendRequest()` is one network attempt, and a 3xx response is
returned as the response — nothing is re-signed and no second request is
made, so a `Location` cannot carry an `Authorization` or
`X-Amz-Security-Token` header off the configured origin. Follow one by
checking the status and sending a new request of your own.

That holds because the transport is `Kinetis\AwsSigV4\SignedTransport`,
built by this package and wrapped in Symfony's PSR-18 adapter. It
forwards one delegate call per request, with `max_redirects => 0`
written onto the request itself rather than left in a default option
another layer could merge over. Its delegate is a Symfony
`AmpHttpClient` over a bare `Amp\Http\Client\PooledHttpClient`: the
AMPHP client configurator is pinned to one that installs no interceptor,
so nothing in the chain retries a request or follows a `Location`.

Both halves of that need owning rather than configuring. An AMPHP client
configurator is what installs an interceptor: `RetryRequests` replays a
request below the PSR-18 boundary where no option and no caller sees it
happen, and `FollowRedirects` sends one on, with AMPHP keeping
`Authorization` across a redirect whose authority matches — an `https`
to `http` hop on the same host included. Above the same boundary,
Symfony's `RetryableHttpClient` with a strategy that treats a 302 as
retryable sends the signed request a second time and answers with what
the retry said, and `ScopingHttpClient` merges its own per-URL defaults
over every request it forwards.

So `transport:` takes a `SignedTransport` and nothing else. Its
constructor is private, and `create()` takes default options — the
deadlines below, headers an endpoint always needs — so no client and no
configurator of yours goes underneath a signature. Put a wrapping client
above `SigV4SigningClient` instead, where a replay costs a fresh
signature and is visible as one. A `max_redirects` in those options is
overridden. With no `transport:` argument, the client builds
`SignedTransport::create()` and uses it for the signed request and the
default credential chain.

In a test, `SignedTransport::answeredInProcess()` answers from a
function of your own on the calling thread and opens no connection. The
function receives the method, the URL and the merged options of each
request:

```{code-block} php
use Kinetis\AwsSigV4\SignedTransport;
use Symfony\Component\HttpClient\Response\MockResponse;

$transport = SignedTransport::answeredInProcess(
    static fn (string $method, string $url, array $options): MockResponse
        => new MockResponse('{"acknowledged":true}', ['http_code' => 200]),
);
```

(aws-sigv4-reference-deadlines)=

## Deadlines

Every request through `SignedTransport::create()` is bounded twice:
`timeout`, 30 seconds by default, is the longest gap it waits between
bytes, and `max_duration`, also 30 seconds, bounds it end to end. The
total bound is the finite one, since a peer that trickles a byte at a
time never goes idle. Either takes a value of your own without moving
the other.

`sendRequest()` covers the request through the response headers and
returns there. The body is read lazily behind that, so a read of it that
outlives the same deadline, or whose connection fails, throws a PSR
stream `RuntimeException` rather than anything `sendRequest()` raises.
Such a message can name the URL it was reading, which carries no
credential: this package signs with the `Authorization` and
`X-Amz-Security-Token` headers only.

(aws-sigv4-reference-credentials)=

## Credential resolution

With no provider passed, credentials are resolved through AsyncAws's
five providers, in AsyncAws's order: environment variables (including
the STS assume-role that `AWS_ROLE_ARN` selects), web identity, the
shared credentials and config files, ECS or EKS pod identity, then IMDS.
Every provider in it that calls AWS uses the same `SignedTransport` the
signed request travels on, so a configured metadata token is sent to the
endpoint that was configured and to nothing a `Location` names.

The first unexpired credentials a lookup resolves are held and reused
until they expire; credentials with no expiry are held for the life of
the client. A provider that answers with nothing, or with credentials
that have already expired, is passed over and the same lookup continues
down the chain. A lookup that reaches the end of the chain without an
answer holds nothing, so a transient ECS, IMDS or token-file failure
costs one lookup rather than the worker's remaining lifetime.

Pass a `CredentialProvider` as `credentialProvider:` to use something
else. A provider passed that way replaces the chain entirely and stays
yours: the client holds nothing it returns, so a provider that calls a
service on every lookup does so for every request.

```{code-block} php
use AsyncAws\Core\Credentials\Credentials;
use Kinetis\AwsSigV4\SigV4SigningClient;

$client = new SigV4SigningClient(
    origin: 'https://search-orders-abc123.eu-west-1.es.amazonaws.com',
    region: 'eu-west-1',
    service: 'es',
    credentialProvider: new Credentials($accessKeyId, $secretAccessKey),
);
```

(aws-sigv4-reference-body)=

## Request bodies and blocking work

SigV4 signs over the body's exact bytes, so `sendRequest()` reads the
request's body into memory as a plain string. A large body is fully
buffered, not streamed, and peak memory during a signed request is a
multiple of the body's own size rather than bounded by it. There is no
size ceiling on what this client will sign; a ceiling on what may be
uploaded to S3 belongs to {doc}`storage-s3`, where such an upload is
built.

**Signing consumes the body.** The stream is read once, from wherever
its cursor already sits through to EOF, and a fresh stream built from
those bytes is what gets signed and sent. Your own stream is left at its
end. A body positioned mid-stream is signed and sent from that position,
and one that has already been read signs and sends as empty — so pass a
body positioned where you want it read, and pass it once. A stream that
cannot be seeked at all (PSR-7 permits these — a chunked body, a pipe)
needs nothing special.

A request through the transport suspends the calling Fiber rather than
blocking it, and so does every credential lookup that reaches the
network: `SignedTransport` is AMPHP-backed. The rest is synchronous work
on the calling thread: the shared credentials and config files, an SSO
cache file, and a web identity token file are read with blocking
filesystem calls, on first resolution and on each refresh, and capturing
and hashing the request body is CPU work.

(aws-sigv4-reference-failures)=

## Failures

`SigV4SigningClient` implements `Psr\Http\Client\ClientInterface`, and
every failure it raises implements `ClientExceptionInterface`, so one
`catch` around `sendRequest()` covers all of them:

| Exception | PSR-18 category | Raised when |
| --- | --- | --- |
| `UntrustedOriginException` | `RequestExceptionInterface` | the request target does not resolve to the configured origin |
| `UnsignableRequestException` | `RequestExceptionInterface` | credentials could not be resolved, the credential provider failed, the body could not be captured, or signing failed |
| `TransportFailureException` | `RequestExceptionInterface` | the transport rejected the signed request — an option or a URL it would not accept |
| `NetworkFailureException` | `NetworkExceptionInterface` | the connection could not be made, was lost, or timed out |

All four are in `Kinetis\AwsSigV4\Exception`. PSR-18 requires the last
of those to be distinguishable from the rest: a request the transport
will not accept fails the same way again, and a connection failure
leaves the request's outcome unknown. Retrying is yours to decide and
costs a fresh signature — this client signs one request per
`sendRequest()` call and never replays one. The {doc}`aws-sigv4` guide
states what to check before repeating a write.

Each carries a fixed message naming only that category, and
`getRequest()` returns the request you passed to `sendRequest()` — never
a resolved, normalized or signed one, so a signed `Authorization` or
`X-Amz-Security-Token` header has no way out through it.

No cause is chained. A credential provider, URI parser, signer, or
transport `Throwable` carries endpoint text, token file contents, or the
signed request in its own message and trace, and a chained cause reaches
every ordinary error channel — `(string) $e`, PSR-3 normalization, a
`getPrevious()` walk. Those causes are discarded rather than stored:
diagnose transport problems through the transport's own logger, which
sees the real failure before this package converts it.

A stack trace holds the arguments each frame was called with when
`zend.exception_ignore_args` is off. This package's own parameters are
marked `#[SensitiveParameter]`, and PHP refuses to serialize a
`SensitiveParameterValue` — so with arguments recorded, `serialize()` on
one of these exceptions throws rather than producing a payload that
would need auditing for what it carries. Log the message and the
endpoint you were reaching.

(aws-sigv4-reference-standalone)=

## Standalone use

`kinetis/aws-sigv4` depends on `kinetis/revolt-http-client`, AsyncAws's
core package, `nyholm/psr7`, and the PSR and Symfony HTTP contracts —
not on `kinetis/framework`. Any PSR-7 request works. Under an API
Gateway stage origin, a relative target is joined to the stage path:

```{code-block} php
use Kinetis\AwsSigV4\SigV4SigningClient;
use Nyholm\Psr7\Request;

$api = new SigV4SigningClient(
    origin: 'https://abc123.execute-api.us-east-1.amazonaws.com/prod',
    region: 'us-east-1',
    service: 'execute-api',
);

// Signed for, and sent to, /prod/orders?status=open on that origin.
$response = $api->sendRequest(new Request('GET', 'orders?status=open'));
```

Construct it once and reuse it, for the same credential and connection
reuse the guide describes.

## See also

- {doc}`aws-sigv4` — configure an origin, credentials and a signed
  OpenSearch client.
- {doc}`revolt-http-client` — the non-blocking HTTP client the transport
  is built on.
