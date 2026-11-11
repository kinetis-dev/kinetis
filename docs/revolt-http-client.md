# HTTP Client

Install the package:

```{code-block} sh
composer require kinetis/revolt-http-client
```

`Kinetis\RevoltHttpClient\Http` calls other services over HTTP. A
request suspends the calling Fiber while it waits on the network, so a
worker keeps serving other requests during the round trip.

## Send a request

`Http` autowires with nothing to register. Inject it and call a verb:

```{code-block} php
use Kinetis\RevoltHttpClient\Http;

final readonly class ExchangeRates
{
    public function __construct(private Http $http) {}

    public function latest(string $currency): array
    {
        return $this->http
            ->get('https://rates.example.com/latest', ['base' => $currency])
            ->throw()
            ->json();
    }
}
```

`get()` takes a query array. `post()`, `put()`, `patch()` and `delete()`
take a body array and send it as JSON; `asForm()` sends it as
`application/x-www-form-urlencoded` instead. `send()` covers any other
method, a raw or streamed body, and a header for one call
({ref}`http-client-reference-send`).

## Register a configured client

An API that needs a credential gets its own client, built once in
`bootstrap.php` from configuration:

```{code-block} php
:caption: bootstrap.php

use App\Shipping\CarrierApi;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\RevoltHttpClient\Http;

return static function (AppScope $app, Config $config): void {
    $app->instance(CarrierApi::class, new CarrierApi(
        new Http()
            ->withBaseUrl('https://api.carrier.example/v2')
            ->withToken($config->required('CARRIER_API_TOKEN'))
            ->withTimeout(10),
    ));
};
```

```{code-block} php
namespace App\Shipping;

use Kinetis\RevoltHttpClient\Http;

final readonly class CarrierApi
{
    public function __construct(private Http $http) {}

    public function track(string $number): array
    {
        return $this->http->get('/shipments', ['number' => $number])->throw()->json();
    }

    public function createLabel(string $orderId, array $address): array
    {
        return $this->http
            ->post('/labels', ['order' => $orderId, 'address' => $address])
            ->throw()
            ->json();
    }
}
```

The base URL's path is a prefix: `get('/shipments')` above requests
`https://api.carrier.example/v2/shipments`.

Every `with*` method returns a new client and leaves the original
unchanged, so a shared client can be adjusted for one call —
`$this->http->withTimeout(30)->get('/reports')` — without affecting
other callers. `Http` holds no request state, so an `AppScope` instance
is safe in a persistent worker, and it keeps its connection pool and
keep-alive connections across requests. An autowired `Http` is built
per request with a new pool. Under PHP-FPM both choices start a new
pool for every request.

## Read the response

```{code-block} php
$response = $this->http->get('/shipments/42');

$response->status();              // 200
$response->successful();          // true for any 2xx
$response->json();                // the decoded body, as an array
$response->jsonPath('eta.date');  // one value by dot path, or null
$response->body();                // the raw string
$response->header('X-Request-Id');
$response->attempts();            // wire attempts sent, retries included
```

An error status is a response, not an exception. Branch on `failed()`,
`clientError()`, `serverError()` or `redirect()`, or call `throw()` to
raise `HttpRequestException` for any status outside 2xx. `throw()`
returns the response when it succeeded, so it chains:

```{code-block} php
$response = $this->http->get("/shipments/{$number}");

if ($response->clientError()) {
    return null;
}

return $response->throw()->json();
```

**Redirects are not followed.** A 3xx is returned as the response, with
its `Location` header to read. To follow one, decide whether the new
origin should receive the request, then send it with a client
configured for that origin.

{ref}`http-client-reference-response` has the JSON decoding rules and
how to release a response you will not read.

## Credentials stay on one origin

A client carrying an `Authorization` or `Cookie` header — from
`withToken()`, `withBasicAuth()` or `withHeaders()` — requires a base
URL, and every request URL is then a path relative to it:

```{code-block} php
// Refused: nothing says which origin may see the token.
$http->withToken($token)->get('https://api.carrier.example/v2/shipments');

// Sent to api.carrier.example and nowhere else.
$http->withBaseUrl('https://api.carrier.example/v2')->withToken($token)->get('/shipments');
```

Such a client refuses an absolute request URL and never follows a
redirect, so the credential reaches only the base URL's origin. Reach a
second API with a second client and that API's own credential. Take the
base URL from configuration, never from request input.
{ref}`http-client-reference-urls` has the URL, path and header rules.

## Timeouts, response size and retries

```{code-block} php
$reports = $http
    ->withBaseUrl('https://reports.example.com')
    ->withTimeout(60)
    ->withMaxResponseBytes(64 * 1024 * 1024)
    ->withRetries(2);
```

- `withTimeout()` is the total budget for one call, in seconds: every
  attempt, every wait between retries, and reading the response. The
  default is 30. Running out throws with the `Timeout` category. Set it
  below the deadline of whatever is waiting on the call.
- `withMaxResponseBytes()` caps the response body. The default, 8 MiB,
  keeps one large reply from exhausting worker memory. A larger body
  throws with the `ResponseTooLarge` category; raise the cap for an
  endpoint known to return more.
- `withRetries()` resends only `GET`, `HEAD`, `OPTIONS`, `TRACE`, `PUT`
  and `DELETE`, after a transport failure or a 429, 500, 502, 503 or 504
  status, waiting between attempts inside the same timeout and at least
  as long as a `Retry-After` header asks. When the server's wait does
  not fit in what is left of the timeout, its response is returned
  instead of being retried early. It never resends `POST` or `PATCH`.
  HTTP defines `PUT` and `DELETE` as idempotent; enable retries only for
  an API whose `PUT` and `DELETE` really are. A client without
  `withRetries()` sends every request once.

{ref}`http-client-reference-deadline` has the exact deadline and retry
behavior, and {ref}`http-client-reference-ceiling` the byte ceiling.

(http-client-pacing)=

### Pace requests to a host

`withPacer()` spaces the attempts a client sends, for example to keep
every worker process together under a third-party API's rate limit. It
takes an implementation of `Kinetis\RevoltHttpClient\RequestPacer`.
Kinetis ships none; {ref}`recipes-pacing` has implementations over
PostgreSQL, MySQL and Redis.

```{code-block} php
$carrier = new Http()
    ->withBaseUrl('https://api.carrier.example/v2')
    ->withRetries(3)
    ->withPacer($pacer);
```

Before every wire attempt, retries included, the client calls
`$pacer->reserve($origin, $maxWaitSeconds)` with the request's origin
(`https://api.carrier.example` above) and what is left of the timeout,
then waits the seconds it returns inside that timeout. A negative wait
means now. A client without retries paces its one attempt too.

- **First attempt.** `null`, or a wait that does not fit in what is left
  of the timeout, throws `HttpRequestException` with the `Timeout`
  category, and nothing is sent.
- **Retry.** The slot is reserved only once the backoff and
  `Retry-After` rules above have decided to retry, and the retry waits
  for the later of its backoff and its slot. When the pacer returns
  `null`, or that wait does not fit in what is left of the timeout, the
  retries end as they do for a `Retry-After` that does not fit: the last
  response is returned, or the transport failure is thrown when no
  response arrived.
- **Unused slots.** A slot reserved for a retry that then does not fit is
  not given back, so the next caller waits behind it.
- **Failures.** An exception from `reserve()` passes through unchanged,
  after the client releases any response it holds.

`reserve()` runs on the calling Fiber, so its I/O must suspend rather
than block. One pacer serves every request for the worker's lifetime and
holds no request state.

## When a request fails

`HttpRequestException` is the only exception the client throws; an
exception from a pacer passes through unchanged. Branch on its
`category`:

| Category | Meaning |
|---|---|
| `InvalidRequest` | The request was refused before it was sent. A repeat is refused the same way. |
| `Transport` | No complete response arrived. The server may have received and applied the request. |
| `Timeout` | The total budget ran out. The server may have received and applied the request. |
| `ResponseTooLarge` | A response arrived with a body past the byte ceiling. |
| `ErrorStatus` | `throw()` met a status outside 2xx; `$e->status` holds it. |
| `Conversion` | `json()` or `jsonPath()` met a body that is not a JSON object or array. |
| `Discarded` | The response was read after `discard()`. |

**A failed write can already have happened.** A connection can drop
after the server acted but before its response arrived, so `Transport`
and `Timeout` do not mean the request was not applied. Before sending a
`POST` or `PATCH` again, find out whether the first one took effect, or
use the API's own idempotency mechanism if it has one:

```{code-block} php
use Kinetis\RevoltHttpClient\Exception\HttpFailure;
use Kinetis\RevoltHttpClient\Exception\HttpRequestException;

try {
    return $this->http->post('/labels', ['order' => $orderId])->throw()->json();
} catch (HttpRequestException $e) {
    if ($e->category !== HttpFailure::Transport && $e->category !== HttpFailure::Timeout) {
        throw $e;
    }

    // The label may exist already: look it up instead of posting again.
    return $this->http->get('/labels', ['order' => $orderId])->throw()->json();
}
```

An exception names the method, the origin and the status, never the
path, query string, headers, credentials or bodies. An upstream's own
error payload is on the response: read it with `body()` before calling
`throw()` when you need it ({ref}`http-client-reference-failures`).

## Several requests at once

```{code-block} php
use function Kinetis\Async\concurrently;

[$shipment, $events] = concurrently([
    fn (): array => $this->http->get("/shipments/{$number}")->throw()->json(),
    fn (): array => $this->http->get("/shipments/{$number}/events")->throw()->json(),
]);
```

The two requests overlap instead of running one after the other.
`concurrently()` lets every task finish, then rethrows the first
failure in task order; see {doc}`concurrency`.

## Testing

Construct `Http` over Symfony's `MockHttpClient` to test code that uses
it without a network. The mock answers with its `MockResponse` list in
order, and `getRequestsCount()` reports how many requests were sent:

```{code-block} php
use App\Shipping\CarrierApi;
use Kinetis\RevoltHttpClient\Http;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CarrierApiTest extends TestCase
{
    public function test_a_503_is_retried(): void
    {
        $mock = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('{"status": "delivered"}'),
        ]);

        $carrier = new CarrierApi(
            new Http($mock)
                ->withBaseUrl('https://api.carrier.example/v2')
                ->withToken('test-token')
                ->withRetries(2),
        );

        self::assertSame(['status' => 'delivered'], $carrier->track('1Z999'));
        self::assertSame(2, $mock->getRequestsCount());
    }
}
```

Build the client under test the way the application does: a client
carrying a token needs `withBaseUrl()` there too, and the mock receives
the same absolute URLs and headers the real transport would. A request
past the end of the list fails with the `InvalidRequest` category.
{ref}`http-client-reference-transports` states what any injected
transport must uphold.

## See also

- {doc}`appendix-http-client` — URL and header rules, deadline, retry
  and byte-ceiling mechanics, failures, transports and standalone use.
- {doc}`concurrency` — running tasks concurrently.
- {doc}`telemetry` — a span for every outgoing request.
- {doc}`aws-sigv4` — signing requests to AWS endpoints.
