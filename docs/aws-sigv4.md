# AWS request signing (SigV4)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/aws-sigv4
```
````

Wraps any PSR-18 HTTP client and signs every outgoing request with AWS
Signature Version 4 before delegating to it — for talking to an
AWS-signed endpoint (Amazon OpenSearch Service, API Gateway, and others)
directly over HTTP rather than through a dedicated SDK client.

```{code-block} php
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use Symfony\Component\HttpClient\Psr18Client;

$signedClient = new SigV4SigningClient(
    client: new Psr18Client(AmpHttpClientFactory::create()),
    region: 'us-east-1',
    service: 'es', // Amazon OpenSearch Service's signing name
);

$response = $signedClient->sendRequest($request);
```

`$service` is the AWS signing service name — `"es"` for Amazon
OpenSearch Service, `"execute-api"` for API Gateway. There's no default;
guessing wrong produces a signature that fails verification rather than
an obvious error.

## Credentials

Resolved automatically the standard AWS way: `AWS_ACCESS_KEY_ID`/
`AWS_SECRET_ACCESS_KEY`, a shared credentials file, or an IAM role,
whichever is available first. Pass a `CredentialProvider` directly as
the fourth constructor argument to use something else instead:

```{code-block} php
use AsyncAws\Core\Credentials\Credentials;

$client = new SigV4SigningClient(
    client: new Psr18Client(AmpHttpClientFactory::create()),
    region: 'us-east-1',
    service: 'es',
    credentialProvider: new Credentials('AKIA...', 'secret-key'),
);
```

## Request bodies

SigV4 signs over the body's exact bytes, so `sendRequest()` always reads
the request's entire body into memory as a plain string, more than once
— a large body is fully buffered, not streamed, and peak memory during a
signed request is a real multiple of the body's own size, not bounded by
it. A body's own stream doesn't need to be seekable: a seekable one is
rewound first so the full content is always captured, with its original
cursor position restored once signing finishes (success or failure) — the
same stream object the request was built with is the one this reads from,
so leaving it seeked wherever reading happened to stop would be a real,
visible side effect on your own object. A non-seekable one (PSR-7 permits
these — a chunked body, a pipe) is read from wherever its cursor already
sits instead, since seeking one backward is impossible — supply a
non-seekable body already positioned at its start for it to be signed and
sent correctly.

## Amazon OpenSearch Service

`OpenSearch\TransportFactory::setHttpClient()` (see {doc}`search-opensearch`)
accepts any PSR-18 client — `SigV4SigningClient` is one, so it drops in
directly in place of the plain `Psr18Client` wrapper, replacing Basic
auth with IAM-based signing:

```{code-block} php
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;
use Symfony\Component\HttpClient\Psr18Client;

// OpenSearch requires an explicit JSON Content-Type on every request.
$httpClient = new Psr18Client(AmpHttpClientFactory::create([
    'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
]));
$signedClient = new SigV4SigningClient(
    client: $httpClient,
    region: 'us-east-1',
    service: 'es',
    baseUri: 'https://search-my-domain.us-east-1.es.amazonaws.com',
);

$transport = (new TransportFactory())->setHttpClient($signedClient)->create();
$client = new Client($transport, new EndpointFactory());
```

Set `baseUri` on `SigV4SigningClient` itself, not on the wrapped
`Psr18Client` — the OpenSearch client builds requests carrying only a
path, and `baseUri` is what supplies the scheme and host for those.
Leave it unset when the request you're signing already carries a full
URI of its own (e.g. a plain `RequestInterface` you built directly for
API Gateway).

`OpenSearchClientFactory::fromConfig()` itself only ever builds the plain
Basic-auth path — construct the client directly, as above, to use IAM/
SigV4 authentication instead.

## See also

- {doc}`revolt-http-client` — the non-blocking HTTP client every
  example above wraps.
- {doc}`search-opensearch` — building an `OpenSearch\Client` the rest of
  the way, and what it can do once you have one.
