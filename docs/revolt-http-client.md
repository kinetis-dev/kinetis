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
            ->withToken($this->apiKey)
            ->get('https://api.carrier.test/shipments', ['number' => $number])
            ->throw()
            ->json();
    }
}
```

`get()`, `post()`, `put()`, `patch()`, and `delete()` take arrays — a
query array for `get()`, a body array for the rest, JSON-encoded by
default. `send()` is the general form for anything they don't cover, and
takes Symfony HttpClient options directly.

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

$api->get('/orders');                        // uses the shared config
$api->withTimeout(30)->get('/reports/large'); // just this call
```

| Method | Effect |
|---|---|
| `withBaseUrl(string)` | prefixes relative URLs |
| `withToken(string, string $scheme = 'Bearer')` | sets `Authorization` |
| `withBasicAuth(string, string)` | HTTP basic credentials |
| `withHeaders(array)` | adds headers, keeping ones already set |
| `withQuery(array)` | query parameters added to every request |
| `withTimeout(float)` | seconds to wait, total |
| `withRetries(int $times = 3)` | retries 5xx, 429, and connection failures with backoff |
| `asForm()` | sends bodies as `application/x-www-form-urlencoded` |

Retries use Symfony's own retry strategy rather than a hand-rolled loop,
and apply to the client's transport — build a retrying client once and
reuse it rather than adding `withRetries()` per call.

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

**An error status is not an exception.** A 404 from an API you are
probing is information, and whether it should stop your code is your
decision, not the client's — so `failed()`, `clientError()`, and
`serverError()` are answers you can branch on:

```{code-block} php
$response = $http->get("https://api.example.com/users/{$id}");

if ($response->clientError()) {
    return null;
}
```

`throw()` opts into the other behavior, and returns the response when it
succeeded so it chains:

```{code-block} php
$order = $http->get('/orders/42')->throw()->json();
```

The exception carries the status and includes the response body, since an
API's own error payload is usually the only thing that explains the
status.

A transport failure — DNS, a refused connection, a timeout hit — has no
status to return, so it throws the same `HttpRequestException` (with
status `0`) from whichever read method first needs the response. One
exception type covers everything the client throws:

```{code-block} php
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;

try {
    $order = $http->withTimeout(5)->get('/orders/42')->throw()->json();
} catch (HttpRequestException $e) {
    // $e->status is the HTTP status, or 0 when nothing answered at all.
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

self::assertSame(42, $http->get('/orders/42')->jsonPath('id'));
```

## The transport on its own

`AmpHttpClientFactory::create()` returns the underlying
`Symfony\Contracts\HttpClient\HttpClientInterface` — a thin factory
around `Symfony\Component\HttpClient\AmpHttpClient`, backed by the
current, genuinely Revolt-based `amphp/http-client` generation rather
than Symfony's default `curl` transport. Use it where a library wants to
be handed a client of its own:

```{code-block} php
use Kinetis\RevoltHttpClient\AmpHttpClientFactory;

$client = AmpHttpClientFactory::create();
$response = $client->request('GET', 'https://example.com/');
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
