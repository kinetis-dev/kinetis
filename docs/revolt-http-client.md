# Appendix: Revolt HTTP Client

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/revolt-http-client
```
````

A Revolt-native, Fiber-suspending implementation of
`Symfony\Contracts\HttpClient\HttpClientInterface` — a thin factory around
`Symfony\Component\HttpClient\AmpHttpClient`, backed by the current,
genuinely Revolt-based `amphp/http-client` generation rather than
Symfony's default `curl` transport.

```{code-block} php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create();

$response = $client->request('GET', 'https://example.com/');
$response->getContent();
```

A request made through this client runs without blocking the rest of
your application while it waits on the network.

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
