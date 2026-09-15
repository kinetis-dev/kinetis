# AWS request signing (SigV4)

Install the package:

```{code-block} sh
composer require kinetis/aws-sigv4
```

`Kinetis\AwsSigV4\SigV4SigningClient` is a PSR-18 client that signs
every request with AWS Signature Version 4 and sends it to one
configured origin. Use it with a library that accepts a PSR-18 client
when the AWS endpoint authenticates with IAM, such as Amazon OpenSearch
Service or an API Gateway API with IAM authorization. A request suspends
the calling Fiber while it waits on AWS.

## Sign requests for Amazon OpenSearch Service

With `kinetis/search-opensearch` installed ({doc}`search-opensearch`),
configure the domain endpoint and its region:

```{code-block} sh
composer require kinetis/search-opensearch
```

```{code-block} text
:caption: .env

OPENSEARCH_DOMAIN_URL=https://search-orders-abc123.eu-west-1.es.amazonaws.com
AWS_REGION=eu-west-1
```

Then bind a signed `OpenSearch\Client` in `bootstrap.php`:

```{code-block} php
:caption: bootstrap.php

use Kinetis\AwsSigV4\SignedTransport;
use Kinetis\AwsSigV4\SigV4SigningClient;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use OpenSearch\Client;
use OpenSearch\EndpointFactory;
use OpenSearch\TransportFactory;

return static function (AppScope $app, Config $config): void {
    $signed = new SigV4SigningClient(
        origin: $config->required('OPENSEARCH_DOMAIN_URL'),
        region: $config->required('AWS_REGION'),
        service: 'es',
        transport: SignedTransport::create([
            'timeout' => 5.0,
            'max_duration' => 10.0,
            // opensearch-php sets no Content-Type, and OpenSearch requires JSON.
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
        ]),
    );

    $app->instance(Client::class, new Client(
        new TransportFactory()->setHttpClient($signed)->create(),
        new EndpointFactory(),
    ));
};
```

Inject `OpenSearch\Client` wherever application code needs the engine
client. `OPENSEARCH_DOMAIN_URL` is this example's own key; `AWS_REGION`
is also the variable AWS tooling reads.

- **Leave `SEARCH_OPENSEARCH_HOST` unset.** The package then binds
  nothing, and this binding is the only `OpenSearch\Client`.
  `Kinetis\Search\SearchClient` is not bound on this path: its failure
  contract ({ref}`search-reference-failures`) belongs to the package's
  own transport.
- **`origin` is the domain endpoint, with no path.** opensearch-php
  sends a path and a query string only; the client adds the origin's
  scheme and host.
- **`service: 'es'`** is Amazon OpenSearch Service's signing name, and
  `execute-api` is API Gateway's. A wrong service or region still signs,
  and AWS rejects the signature rather than this client reporting an
  error.
- **Grant access in the domain's access policy** to the IAM role or user
  the application runs as.
- **Build the client once, at boot.** It holds resolved credentials
  until they expire and reuses its connections, and it keeps no request
  state, so the `AppScope` instance is safe in a persistent worker.

An origin, region or service that fails validation throws
`Kinetis\AwsSigV4\Exception\SigningException` from the constructor, so
a misconfigured endpoint fails at boot.

## The trusted origin

`origin` is the only scheme, host and port the client signs for, with an
optional path prefix. For each request:

- a relative target such as `/orders/_search` resolves against the
  origin, under its path prefix;
- an absolute target must name exactly that origin and stay under the
  prefix;
- anything else — another host or port, `http` under an `https` origin,
  a path that climbs out of the prefix — throws `UntrustedOriginException`
  before credentials are resolved, the body is read, or anything is
  sent.

A redirect is returned as the response and never followed, and nothing
in the client or its transport resends a request, so the signed
`Authorization` and `X-Amz-Security-Token` headers reach only this
origin. Set the origin from deployment configuration, never from request
input: it decides where signed credentials may go.
{ref}`aws-sigv4-reference-origin` has the origin grammar and target
normalization, and {ref}`aws-sigv4-reference-canonical` the exact
signed form.

## Credentials

With no `credentialProvider` argument, the client resolves credentials
through AsyncAws's default chain, in order: environment variables
(assuming the role in `AWS_ROLE_ARN` through STS when it is set), a web
identity token, the shared credentials and config files, ECS or EKS pod
identity, then EC2 instance metadata.

- **In production, attach an IAM role to the workload** — an ECS task
  role, EKS pod identity, a web identity role, or an EC2 instance
  profile. Credentials then come from AWS, expire and refresh, and never
  appear in configuration.
- **For local development**, set `AWS_ACCESS_KEY_ID` and
  `AWS_SECRET_ACCESS_KEY`, plus `AWS_SESSION_TOKEN` for temporary
  credentials, in the environment or an uncommitted `.env`, or select a
  profile from `~/.aws/credentials` with `AWS_PROFILE`.

Resolved credentials are held and reused until they expire. A lookup
that finds nothing holds nothing, so a transient metadata failure costs
one request, which fails with `UnsignableRequestException` before
anything is sent. Pass `credentialProvider:` to replace the chain
({ref}`aws-sigv4-reference-credentials`).

## Deadlines

`SignedTransport::create()` bounds every request at 30 seconds idle —
the longest wait between bytes — and 30 seconds in total. Set both for a
caller that cannot wait that long, as the example above does; setting
one leaves the other at 30.

`sendRequest()` returns once the response headers arrive, and the body
is read after that. opensearch-php reads it within the same call; a body
read that fails or outlives the deadline throws a `RuntimeException`
from the response stream rather than a PSR-18 exception
({ref}`aws-sigv4-reference-deadlines`).

## When a request fails

Every exception `sendRequest()` throws implements PSR-18's
`ClientExceptionInterface`:

- **`Psr\Http\Client\NetworkExceptionInterface`** —
  `NetworkFailureException`: the connection could not be made, was lost,
  or timed out. **The request may have reached AWS and been applied.**
  An index or bulk write can already be stored: check before sending it
  again, or write with a document ID so a repeat replaces the document
  instead of adding a second one. Nothing on this path resends a
  request — not the signing client, not its transport, and not
  opensearch-php's `HttpTransport`.
- **`Psr\Http\Client\RequestExceptionInterface`** — the request could
  not be signed or sent: `UntrustedOriginException` for a target outside
  the origin, `UnsignableRequestException` for missing credentials, a
  failing credential provider or an unreadable body, and
  `TransportFailureException` when the transport rejects the signed
  request. The first two are raised before anything is sent.

An HTTP error status is a response, not an exception, at this layer;
opensearch-php raises its own exception for a status of 400 or more.
Exception messages are fixed text with no cause chained, and
`getRequest()` returns the request that was passed in, never the signed
one ({ref}`aws-sigv4-reference-failures`).

## What blocks a worker

Network work suspends the calling Fiber: the signed request and every
credential lookup that calls AWS (STS, web identity, ECS, EKS pod
identity, instance metadata). Local work blocks the calling thread: the
shared credentials and config files, an SSO cache file and a web
identity token file are read with blocking filesystem calls when
credentials are first resolved and on each refresh, and the request body
is buffered in memory and hashed.

In a persistent worker, blocking work pauses every request on that
worker while it runs; under PHP-FPM it delays only its own request. A
client built once at boot reads credential files only when it has no
unexpired credentials. {ref}`aws-sigv4-reference-body` has the body
buffering rules.

## See also

- {doc}`appendix-aws-sigv4` — origin grammar, canonical request,
  transport, credential, body and failure contracts.
- {doc}`search-opensearch` — what an `OpenSearch\Client` can do once
  bound.
- {doc}`revolt-http-client` — the non-blocking HTTP client the transport
  is built on.
