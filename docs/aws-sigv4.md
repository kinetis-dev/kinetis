# AWS request signing (SigV4)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/aws-sigv4
```
````

A PSR-18 HTTP client that signs every outgoing request with AWS
Signature Version 4 and sends it to one configured origin — for talking
to an AWS-signed endpoint (Amazon OpenSearch Service, API Gateway, and
others) directly over HTTP rather than through a dedicated SDK client.

```{code-block} php
use Kinetis\AwsSigV4\SigV4SigningClient;

$signedClient = new SigV4SigningClient(
    origin: 'https://search-my-domain.us-east-1.es.amazonaws.com',
    region: 'us-east-1',
    service: 'es', // Amazon OpenSearch Service's signing name
);

$response = $signedClient->sendRequest($request);
```

`$service` is the AWS signing service name — `"es"` for Amazon
OpenSearch Service, `"execute-api"` for API Gateway. There's no default;
guessing wrong produces a signature that fails verification rather than
an obvious error. `$region` and `$service` are each 1 to 64 ASCII
characters: a letter or digit, then letters, digits, `.`, `-` or `_`.

## The trusted origin

`$origin` is mandatory, and it is the whole of what this client will
sign for. There is no mode that signs whatever host a request happens to
name.

It must be an absolute `http`/`https` URI: a scheme, a host, an optional
port, and an optional path prefix, and nothing else. The host is a
registered name or a dotted-quad IPv4 address, `http` included so a
LocalStack or other AWS-compatible local endpoint works; an IPv6 origin
is out of scope. Userinfo, a query string, a fragment, a percent sign or
backslash in the authority, a control character, a malformed percent
escape, a `.` or `..` path segment, a non-numeric or out-of-range port,
and an invalid host are each rejected. Parsing happens once, at
construction, so a misconfigured endpoint fails immediately rather than
on the first request that needs it.

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
other header you set is signed and sent as you wrote it — `Content-Type`
included, since it decides how a service reads the body — apart from the
payload-hash header below.

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

## Redirects and retries

One `sendRequest()` is one network attempt, and a 3xx response is
returned as the response — nothing is re-signed and no second request is
made, so a `Location` cannot carry an `Authorization` or
`X-Amz-Security-Token` header off the configured origin. Follow one
deliberately, if you want to, by checking the status and sending a new
request of your own.

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
constructor is private, and `create()` takes default options — a
timeout, headers an endpoint always needs — so no client and no
configurator of yours goes underneath a signature. Put a wrapping client
above `SigV4SigningClient` instead, where a replay costs a fresh
signature and is visible as one. A `max_redirects` in those options is
overridden.

```{code-block} php
use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;

$signedClient = new SigV4SigningClient(
    origin: 'https://api.example.com/prod',
    region: 'us-east-1',
    service: 'execute-api',
    transport: SignedTransport::create(['timeout' => 5.0]),
);
```

In a test, `SignedTransport::answeredInProcess()` answers from a
function of your own on the calling thread and opens no connection:

```{code-block} php
use Symfony\Component\HttpClient\Response\MockResponse;

$transport = SignedTransport::answeredInProcess(
    static fn (string $method, string $url, array $options): MockResponse
        => new MockResponse('{"acknowledged":true}', ['http_code' => 200]),
);
```

## Credentials

Resolved through AsyncAws's five providers, in AsyncAws's order:
environment variables (including the STS assume-role that `AWS_ROLE_ARN`
selects), web identity, the shared credentials and config files, ECS or
EKS pod identity, then IMDS. Every provider in it that calls AWS uses
the same `SignedTransport` the signed request travels on, so a
configured metadata token is sent to the endpoint that was configured
and to nothing a `Location` names.

The first unexpired credentials a lookup resolves are held and reused
until they expire; credentials with no expiry are held for the life of
the client. A provider that answers with nothing, or with credentials
that have already expired, is passed over and the same lookup continues
down the chain. A lookup that reaches the end of the chain without an
answer holds nothing, so a transient ECS, IMDS or token-file failure
costs one lookup rather than the worker's remaining lifetime.

Pass a `CredentialProvider` directly as the fourth constructor argument
to use something else instead. A provider passed that way replaces the
chain entirely and stays yours: the client holds nothing it returns.

```{code-block} php
use AsyncAws\Core\Credentials\Credentials;
use Kinetis\AwsSigV4\SigV4SigningClient;

$client = new SigV4SigningClient(
    origin: 'https://api.example.com',
    region: 'us-east-1',
    service: 'es',
    credentialProvider: new Credentials('AKIA...', 'secret-key'),
);
```

## Request bodies and what blocks

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

## Errors

`SigV4SigningClient` implements `Psr\Http\Client\ClientInterface`, and
every failure it raises implements `ClientExceptionInterface`, so one
`catch` around `sendRequest()` covers all of them:

| Exception | PSR-18 category | Raised when |
| --- | --- | --- |
| `UntrustedOriginException` | `RequestExceptionInterface` | the request target does not resolve to the configured origin |
| `UnsignableRequestException` | `RequestExceptionInterface` | credentials could not be resolved, the credential provider failed, the body could not be captured, or signing failed |
| `TransportFailureException` | `RequestExceptionInterface` | the transport rejected the signed request — an option or a URL it would not accept |
| `NetworkFailureException` | `NetworkExceptionInterface` | the connection could not be made, was lost, or timed out |

PSR-18 requires the last of those to be distinguishable from the rest,
because retrying is meaningful for a connection that never answered and
pointless for a request the transport will not accept. Catch
`NetworkExceptionInterface` for the connection failures and
`RequestExceptionInterface` for everything else. Retrying is yours to
decide and costs a fresh signature — this client signs one request per
`sendRequest()` call and never replays one.

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

These exceptions are not serializable. A stack trace holds the arguments
each frame was called with, this package's own are marked
`#[SensitiveParameter]`, and PHP refuses to serialize a
`SensitiveParameterValue` — so `serialize()` on one throws rather than
producing a payload that would need auditing for what it carries. Log
the message and the endpoint you were reaching.

A configured origin, region, or service name that fails validation
throws `Kinetis\AwsSigV4\Exception\SigningException` from
`new SigV4SigningClient(...)`, not from `sendRequest()`. Its message
names the field and the rule, never the value — a rejected origin can
carry a password in its userinfo or a token in its query string.

## Amazon OpenSearch Service

`OpenSearch\TransportFactory::setHttpClient()` (see
{doc}`search-opensearch`) accepts any PSR-18 client, and
`SigV4SigningClient` is one, so it drops in directly in place of
`kinetis/search-opensearch`'s own adapter, replacing Basic auth with
IAM-based signing:

```{code-block} php
use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;

$signedClient = new SigV4SigningClient(
    origin: 'https://search-my-domain.us-east-1.es.amazonaws.com',
    region: 'us-east-1',
    service: 'es',
    // OpenSearch requires an explicit JSON Content-Type on every request.
    transport: SignedTransport::create([
        'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
    ]),
);

$transport = (new TransportFactory())->setHttpClient($signedClient)->create();
$client = new Client($transport, new EndpointFactory());
```

The OpenSearch client builds requests carrying only a path, which is
what `origin` supplies the scheme and host for. Give the domain's own
URL, not a wrapper's base URL.

`OpenSearchClientFactory::fromConfig()` itself only ever builds the plain
Basic-auth path — construct the client directly, as above, to use IAM/
SigV4 authentication instead.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  example above runs on.
- {doc}`search-opensearch` — building an `OpenSearch\Client` the rest of
  the way, and what it can do once you have one.
