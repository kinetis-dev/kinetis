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
port, and an optional path prefix, and nothing else. A registered name,
a dotted-quad IPv4 address, and a bracketed IPv6 address are all
accepted, `http` included so a LocalStack or other AWS-compatible local
endpoint works. Userinfo, a query string, a fragment, a percent sign or
backslash in the authority, a control character, a malformed percent
escape, a `.` or `..` path segment, a non-numeric or out-of-range port,
and an invalid host are each rejected. Parsing happens once, at
construction, so a misconfigured endpoint fails immediately rather than
on the first request that needs it.

Scheme and host are compared case-insensitively, an IPv6 address is
compared by value (`[0:0:0:0:0:0:0:1]` and `[::1]` are one origin), and
an absent port means 80 for `http` and 443 for `https` — so
`https://api.example.com` and `https://API.Example.com:443` are the same
origin.

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
- `.` and `..` segments are then removed from the path, after decoding,
  so a segment spelled `%2E%2E` counts as one. An empty path becomes
  `/`.
- A fragment is dropped: it never reaches an HTTP request line.

The URI that is signed and sent is then built from the origin's own
canonical scheme, host and port, so the authority a signature covers is
the configured one however the request spelled it. The rule applied to
its own output changes nothing, which is what makes the target the
transport sends byte-identical to the one the signature was computed
over.

The practical consequence: `/prod/../../secrets` under the origin
`https://api.example.com/prod` is rejected rather than signed for
`/prod/../../secrets` and sent to `/secrets`.

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

Both halves of that need owning rather than configuring. Symfony's own
default configurator wraps the pool in an `InterceptedHttpClient`
carrying `RetryRequests(2)`, which replays a request below the PSR-18
boundary where no option and no caller sees it happen; a configurator
can equally install `FollowRedirects`, and AMPHP keeps `Authorization`
across a redirect whose authority matches — an `https` to `http` hop on
the same host included. Above the same boundary, Symfony's
`RetryableHttpClient` with a strategy that treats a 302 as retryable
sends the signed request a second time and answers with what the retry
said, and `ScopingHttpClient` merges its own per-URL defaults over every
request it forwards.

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

Resolved automatically the standard AWS way: `AWS_ACCESS_KEY_ID`/
`AWS_SECRET_ACCESS_KEY`, a shared credentials file, or an IAM role,
whichever is available first. The chain's own ECS, EKS pod-identity, and
IMDS lookups run on that same transport, so a
configured metadata token is sent to the endpoint that was configured
and to nothing a `Location` names.

Pass a `CredentialProvider` directly as the fourth constructor argument
to use something else instead:

```{code-block} php
use AsyncAws\Core\Credentials\Credentials;

$client = new SigV4SigningClient(
    origin: 'https://api.example.com',
    region: 'us-east-1',
    service: 'es',
    credentialProvider: new Credentials('AKIA...', 'secret-key'),
);
```

## Request bodies and what blocks

SigV4 signs over the body's exact bytes, so `sendRequest()` reads the
request's entire body into memory as a plain string, more than once — a
large body is fully buffered, not streamed, and peak memory during a
signed request is a multiple of the body's own size, not bounded by it.
There is no size ceiling on what this client will sign; a ceiling on
what may be uploaded to S3 belongs to {doc}`storage-s3`, where such an
upload is built.

A body's own stream doesn't need to be seekable: a seekable one is
rewound first so the full content is always captured, with its original
cursor position restored once signing finishes (success or failure) —
the same stream object the request was built with is the one this reads
from, so leaving it seeked wherever reading happened to stop would be a
visible side effect on your own object. A non-seekable one (PSR-7
permits these — a chunked body, a pipe) is read from wherever its cursor
already sits instead, since seeking one backward is impossible — supply
a non-seekable body already positioned at its start for it to be signed
and sent correctly.

A request through the transport suspends the calling Fiber rather than
blocking it, and so do the credential chain's ECS/EKS/IMDS lookups:
`SignedTransport` is AMPHP-backed. The rest is synchronous work on the calling thread: the shared credentials
and config files, an SSO cache file, and a web identity token file are
read with blocking filesystem calls, and capturing and hashing the
request body is CPU work.

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
`getPrevious()` walk, `serialize()`. Those causes are discarded rather
than stored: diagnose transport problems through the transport's own
logger, which sees the real failure before this package converts it.

Serializing one of these exceptions drops the stack trace and replaces
the request with a copy carrying its method, scheme, host, port and
path, and nothing else — no headers, no body, no userinfo, no query
string, no fragment. That is the whole of the safe contract, and it is
the same for every exception type above: enough to name which endpoint
failed, never enough to carry a credential.

A configured origin, region, or service name that fails validation
throws `Kinetis\AwsSigV4\Exception\SigningException` from
`new SigV4SigningClient(...)`, not from `sendRequest()`. Its message
names the field and the rule, never the value — a rejected origin can
carry a password in its userinfo or a token in its query string.

## Amazon OpenSearch Service

`OpenSearch\TransportFactory::setHttpClient()` (see
{doc}`search-opensearch`) accepts any PSR-18 client, and
`SigV4SigningClient` is one, so it drops in directly in place of the
plain `Psr18Client` wrapper, replacing Basic auth with IAM-based
signing:

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
