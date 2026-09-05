# Testing

Kinetis tests your application through the application — the real
container, the real discovery, the real middleware pipeline — rather than
against a hand-assembled approximation of it. A test that passes tells
you the request would have worked.

## Testing a route

Extend `Kinetis\Testing\ApplicationTestCase` and point it at your project
root. It boots the application before each test and gives you a client:

```{code-block} php
:caption: tests/OrderControllerTest.php

use Kinetis\Testing\ApplicationTestCase;

final class OrderControllerTest extends ApplicationTestCase
{
    protected function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public function test_it_lists_orders(): void
    {
        $this->client->get('/orders')
            ->assertOk()
            ->assertJsonPath('0.sku', 'A1');
    }

    public function test_it_rejects_an_invalid_order(): void
    {
        $this->client->post('/orders', ['sku' => '', 'quantity' => 2])
            ->assertValidationError('sku');
    }
}
```

Nothing is registered by hand: routes, middleware, event listeners, and
package bootstraps are discovered exactly as they are at runtime, and
`bootstrap.php` runs. A route you just wrote is testable without touching
the test's setup.

Three properties are available on the test case: `$this->client` for
requests, `$this->app` for the booted container, and `$this->application`
for the `TestApplication` itself.

## Overriding configuration

Return whatever a test run should differ on — a test database, a fake
endpoint. These win over both the real environment and `.env`:

```{code-block} php
protected function configOverrides(): array
{
    return ['DB_NAME' => 'app_test', 'MAILER_DSN' => 'null://null'];
}
```

## Replacing what a test should not reach

Configuration only goes so far: some things a request touches are
services, not settings — a payment gateway, a WebSocket server, a queue
you would rather hold a job than run it. Register a replacement in
`registerTestDoubles()`, which runs after your own `bootstrap.php` and
before the container locks, so a binding made here replaces the one the
application made:

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

protected function registerTestDoubles(AppScope $app, Config $config): void
{
    $app->instance(PaymentGateway::class, new FakeGateway());
}
```

That window is the only one there is: `AppScope` refuses new bindings
once `boot()` has run, so a double registered from a test body would be
too late.

`kinetis/pingpong`'s own controller test is this in practice — it
replaces the broadcaster and the queue, which leaves a real MySQL
as the only thing it needs, and the suite runs against a bare database
rather than only inside the full compose stack.

## Making requests

```{code-block} php
$this->client->get('/users', query: ['page' => 2, 'limit' => 10]);
$this->client->post('/orders', body: ['sku' => 'ABC123', 'quantity' => 2]);
$this->client->put('/users/42', body: ['name' => 'Ada']);
$this->client->delete('/users/42', headers: ['Authorization' => 'Bearer test-token']);
```

`body` is a plain array, JSON-encoded automatically, with
`Content-Type: application/json` set unless you pass your own — and that
override must itself be JSON-shaped (`application/json`, or a `+json`
structured suffix for a vendor media type; a `; charset=...` parameter is
fine, since only the bare media type is checked); anything else throws,
rather than silently sending JSON bytes under a Content-Type that claims
otherwise. The header is resolved case-insensitively (`content-type`
works exactly like `Content-Type`): two differently-cased keys naming the
same header with two different values throw rather than silently picking
one, and two agreeing on the same value are collapsed into exactly one
outgoing header rather than left as two (which a real HTTP client would
otherwise combine into one comma-joined, invalid Content-Type value).
This runs even on a request with no body at all — `get()`/`delete()`
still catch a conflicting Content-Type in `headers`. Every verb method
calls `request()`, which is available directly for anything the
shorthands don't cover:

```{code-block} php
$this->client->request('PATCH', '/users/42', body: $payload, headers: $headers);
```

Query parameters, wherever you pass them (`get()`'s own `query:`, or
`request()`'s), are encoded into the request URI's actual query string —
`getQueryParams()` is parsed back out of that same string, so the two
always agree, the same relationship a real incoming request has.

**A JSON array is not the only body a route needs to see.** Four more
methods send something genuinely different, never routed through JSON
encoding at all:

```{code-block} php
// application/x-www-form-urlencoded — the raw bytes are exactly
// http_build_query($form), and getParsedBody() is that same string
// parsed back with parse_str() — not $form itself. A wire-format body
// can't carry $form's original PHP types: every scalar comes back as a
// string, a null value is omitted entirely, and a nested array is
// re-encoded/re-parsed — the same lossy shape a real form post produces.
$this->client->postForm('/login', ['email' => 'ada@example.com', 'password' => 'secret']);
$this->client->putForm('/settings', ['theme' => 'dark']);
$this->client->patchForm('/settings', ['theme' => 'dark']);

// A raw string body, sent exactly as given — a webhook payload, binary
// content, anything none of the above already cover. getParsedBody()
// stays null.
$this->client->raw('POST', '/webhooks/stripe', $rawPayload, ['Content-Type' => 'application/json']);
```

`postForm()`/`putForm()`/`patchForm()` exist specifically because
`post()`/`put()`/`patch()` always send JSON — setting
`Content-Type: application/x-www-form-urlencoded` on an array `$body`
handed to those would create a request whose declared Content-Type
disagreed with its actual bytes, which is exactly what these methods
avoid: a route reading `getParsedBody()` (a form-encoded `_token` field
CSRF protection checks, for one) needs a request built this way to be
reachable in a test at all. Their own Content-Type default
(`application/x-www-form-urlencoded`) can be overridden too — a
`; charset=...` parameter is fine — but the override must itself be
form-urlencoded-shaped; anything else (a stray `application/json`, say)
throws, for the identical reason `request()`'s own override validation
does.

**`send()` is the direct escape hatch** — for a multipart/uploaded-file
request, or anything else none of the methods above cover. This class
deliberately never guesses a multipart boundary from a plain array; build
the real PSR-7 request yourself and dispatch it through the same Kernel
every other method here uses:

```{code-block} php
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;

$avatar = new UploadedFile(Stream::create($fileContents), \strlen($fileContents), \UPLOAD_ERR_OK, 'avatar.png', 'image/png');
$request = new ServerRequest('POST', '/avatars')
    ->withHeader('Content-Type', 'multipart/form-data; boundary=----WebKitFormBoundary')
    ->withParsedBody(['name' => 'Ada'])
    ->withUploadedFiles(['avatar' => $avatar]);

$this->client->send($request)->assertOk();
```

Every method above is, underneath, just a convenience for building one of
these requests and calling `send()`.

## Asserting on the response

Responses come back as `Kinetis\Testing\TestResponse` — a PSR-7 response
with assertions attached, so `getStatusCode()`/`getBody()` still work and
the response can still be passed to anything expecting plain PSR-7.

```{code-block} php
$response = $this->client->post('/orders', ['sku' => 'A1', 'quantity' => 2]);

$response->assertCreated()
    ->assertHeader('Content-Type', 'application/json')
    ->assertJsonPath('order.sku', 'A1')
    ->assertJsonPathMissing('order.internal_cost');
```

| Assertion | Passes when |
|---|---|
| `assertStatus(int)` | the status matches exactly |
| `assertOk()` / `assertCreated()` / `assertNotFound()` | 200 / 201 / 404 |
| `assertSuccessful()` | any 2xx |
| `assertHeader(name, ?value)` | the header is present, and equals `value` when given |
| `assertJson(array)` | the whole decoded body matches exactly |
| `assertJsonPath(path, value)` | the value at a dot path — `order.items.0.sku` |
| `assertJsonPathMissing(path)` | nothing is at that path |
| `assertValidationError(field)` | the response is 422 and names that field |
| `assertBodyContains(string)` | the raw body contains the text |

A failed assertion prints the response body alongside the mismatch, since
an unexpected status is usually explained by what the body says. `json()`
and `body()` are there for anything the assertions don't cover, and both
can be called repeatedly — reading the body doesn't consume it.

## Testing against a database

A test that writes rows has to leave the database as it found it, or the
next test inherits its data. `kinetis/persistence` ships two strategies;
both are PHPUnit traits, and both ask the test which connection to
isolate.

### Rolling back: `DatabaseTransactions`

Opens a transaction before each test and rolls it back after. Fast —
nothing is ever written — and it covers writes the application makes
through the container's own client, not just ones the test issues
directly.

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTransactions;
use Kinetis\Testing\ApplicationTestCase;

final class OrderRepositoryTest extends ApplicationTestCase
{
    use DatabaseTransactions;

    protected function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    protected function databaseLink(): SqlLink
    {
        return $this->app->get(MysqlLink::class);
    }

    public function test_it_stores_an_order(): void
    {
        $this->client->post('/orders', ['sku' => 'A1', 'quantity' => 2])->assertCreated();

        self::assertSame(1, $this->orderCount());
    }
}
```

That works because the PDO drivers hold a single connection, and a
transaction opened on it encloses every later statement on it. Two cases
fall outside that, and both are loud rather than silent:

- **Code that opens its own transaction.** The drivers reject nested
  transactions deliberately, so `TransactionGuard::transaction()` or an
  explicit `beginTransaction()` in the code under test throws while this
  trait holds one open.
- **`DB_DRIVER=native`.** The async drivers pool several connections, so a
  transaction on one isolates nothing the others do. The trait skips the
  test rather than reporting isolation it isn't providing. Test suites run
  under the CLI, where `auto` already selects PDO, so this only comes up
  if a suite forces `native`.

Use the other strategy for either.

### Emptying tables: `DatabaseTruncation`

Deletes the rows in the tables you name, before each test. Slower, and it
holds no transaction of its own — so it works for code that manages its
own transactions, and for any driver.

```{code-block} php
use Kinetis\Persistence\Testing\DatabaseTruncation;

final class CheckoutTest extends ApplicationTestCase
{
    use DatabaseTruncation;

    protected function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    protected function databaseLink(): SqlLink
    {
        return $this->app->get(MysqlLink::class);
    }

    /** @return list<string> */
    protected function tablesToTruncate(): array
    {
        return ['order_items', 'orders'];
    }
}
```

Tables are listed explicitly rather than discovered: a suite that empties
every table it can find eventually empties one holding reference data the
application needs, and that failure looks like a bug in the code under
test. List child tables before their parents where a foreign key would
otherwise block the delete.

Truncation happens *before* each test rather than after, so a failing
test leaves its rows behind to inspect.

```{note}
Schema creation belongs outside both traits — in a migration run once
before the suite, not in a test. On MySQL, `CREATE TABLE` commits the
surrounding transaction implicitly, which would silently end
`DatabaseTransactions`' isolation for the rest of that test.
```

## Without the base class

`ApplicationTestCase` is thin wiring over `Kinetis\Testing\TestApplication`,
which has no PHPUnit dependency at all. Use it directly to share one
application across a whole test class, or from a different runner:

```{code-block} php
$application = TestApplication::boot(__DIR__ . '/..', ['DB_NAME' => 'app_test']);

$application->client()->get('/orders')->assertOk();
$repository = $application->get(OrderRepository::class);
```

`TestApplication::withRouter()` builds one from an explicit route table
instead of discovery, for a test that wants a fixed set of routes rather
than whatever the project contains:

```{code-block} php
$router = new Router();
$router->register(OrderController::class);

$client = TestApplication::withRouter($router)->client();
```

## Conformance-testing a runtime adapter

A runtime adapter turns whatever its environment delivers — superglobals
and `php://input`, an API Gateway event, a Goridge frame — into a PSR-7
request, and turns the PSR-7 response back. Adapters built through
entirely different code have to agree on what that conversion means:
which header a repeated header becomes, where cookies end up, what the
URI's scheme, authority and request target are and that they agree with
the `Host` header, that a `PUT` or `PATCH` form body parses the same as
a `POST` one (url-encoded and multipart alike), that nested and repeated
field and file names nest identically, that the declared
`Content-Length` and a large body both arrive intact, that a binary body
arrives byte for byte, that two `Set-Cookie` headers leave as two
cookies, what happens to a body the environment cannot parse and to one
past a form-complexity ceiling. `Kinetis\Testing\Runtime` expresses each
of those once, as a PHPUnit base class, and runs the whole list against
any adapter that provides a driver:

```{code-block} php
use Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase;
use Kinetis\Testing\Runtime\RuntimeAdapterDriver;

final class SwooleConformanceTest extends RuntimeAdapterConformanceTestCase
{
    protected function driver(): RuntimeAdapterDriver
    {
        return new SwooleDriver();
    }
}
```

The driver is the only adapter-specific code. It pushes one
`WireRequest` (method, path, query string, headers as repeatable pairs,
cookies, raw body) through the adapter, has the handler answer with the
given `ResponseSpec`, and reports an `Outcome`: the `ObservedRequest`
the handler saw (`null` if the adapter never reached it) and the
`WireResponse` the environment received — or an `AdapterRejection`, when
the adapter refused outright.

```{code-block} php
interface RuntimeAdapterDriver
{
    public function dispatch(WireRequest $request, ResponseSpec $response): Outcome;
    public function expectedClientIp(): string;
    public function supportsStreaming(): bool;
    public function unparseableFormRequest(): WireRequest;
    public function expectedScheme(): string;
    public function preservesNumericHeaderNames(): bool;
    public function preservesCookieOrder(): bool;
    public function trustsTheConnectingClient(): bool;
}
```

Everything after `dispatch()` is a fact the environment decides, not the
test: the address it reports as `REMOTE_ADDR` (a real socket's peer for
a SAPI, whatever the driver injects as `sourceIp` for Lambda), the
scheme it serves over when nothing forwards one, whether a
`StreamedResponse` can reach the client incrementally, what a form body
it cannot parse looks like, whether a purely-numeric header name and the
client's cookie order survive its own request decoding, and whether the
peer the driver connects from is a trusted edge whose
`X-Forwarded-Proto` may decide the request's scheme.

A parsed form body's raw bytes are not among them. Every adapter holds
the whole body and parses a copy, so `getBody()` after
`getParsedBody()` is the request byte for byte on all of them, and the
suite asserts that rather than asking.

**The suite asserts both directions of every declaration**, which is
what keeps a declaration from becoming a skip. A streaming environment
must deliver every chunk in order; a non-streaming one must refuse the
response rather than buffer it. An environment that keeps a numeric
header name must deliver its value unchanged; one that cannot must drop
the header outright, never deliver it under another name or with another
value. An environment that treats this client as an edge must honor a
forwarded scheme; one
that does not must ignore it completely, in both directions — it can
neither be promoted to `https` nor downgraded from it. Every method runs
on every adapter. Nothing is skipped.

Over-limit input needs no declaration: the ceilings are
`Kinetis\Http\Form\FormLimits`' own and identical everywhere, so the
suite builds those requests itself — one field, one file, one nesting
level, one part past each limit, with a security-significant field
(`csrf_token`, a signature upload) placed beyond the edge — and requires
a `413` with the handler never reached. That is the case a truncating
parser passes by handing on a form that looks complete with exactly that
field missing.

Three of those cases exist because they are invisible to a limit checked
after parsing, and every runtime has to meet them the same way: a body
repeating **one** name past the ceiling (a thousand pairs on the wire,
one leaf in the result), a body of **unnamed** multipart parts (which
build nothing and still cost a parser everything), and a part repeating
**one header line** past the ceiling (one entry in any header map). The
empty file control is the fourth: submitted by a file input the user left
alone, and reported as `UPLOAD_ERR_NO_FILE` on every adapter, so upload
validation written against PHP behaves identically everywhere.

The multipart contract is asserted the same way, as raw wire bodies
rather than through the suite's own part builder — what is being checked
is exactly what a well-formed builder would never produce. A line whose
boundary token is only a prefix stays payload, byte for byte; a root
`Content-Type` naming the boundary twice or trailing syntax after it, a
padded delimiter, a boundary after a bare LF, a decoding
`Content-Transfer-Encoding`, an RFC 2047 encoded word, an RFC 5987
extended parameter, a nested `multipart/*` part and a repeated
`Content-Disposition` are each a `400`; and a file part declaring no
`Content-Type` reports no client media type at all. Every one of those is
a place two real parsers read one body differently — core's own scan and
the satellites' `riverline/multipart-parser` — so running them on every
adapter is what turns "one contract" into something a change can break
loudly. See "Form bodies: one contract under every runtime" in
{doc}`runtime-adapters` for the rules themselves.

All four adapters run this suite themselves; how each one is driven, and
what that does and doesn't prove, is spelled out below. Read a driver
for a worked example — `Kinetis\Tests\Runtime\Conformance\SuperglobalsDriver`
in the framework package, `Kinetis\BrefAdapter\Tests\Conformance\LambdaDriver`
in kinetis/bref-adapter, `Kinetis\RoadRunnerAdapter\Tests\Conformance\RoadRunnerDriver`
in kinetis/roadrunner-adapter.

Only behavior every environment can exhibit belongs in the shared suite.
An input one environment alone can produce — a base64-flagged event
body, a multipart part's header count under an adapter that parses the
body itself — is that adapter's own test to write, alongside the
conformance run; the suite's public assertion helpers
(`assertMalformedBodyResponse()`, `assertOverLimitFormResponse()`) hold
that input to the same contract the shared cases use, so the *outcome*
stays unified even where the *trigger* can't be. The byte cap on a raw
request body is not the adapter's to test — it is
`MaxBodySizeMiddleware`'s, in the Kernel, identical under every adapter
and tested there. `Kinetis\Testing\FreePort::reserve()` hands a fixture
server a port nothing is listening on, so two suites spawning servers in
one checkout don't collide on a hard-coded number.

What each run proves, precisely, and what it doesn't.

- **In-process**, with no wire and no SAPI: the Lambda conversion.
  `LambdaDriver` calls `BrefLambdaAdapter::handleEvent()` with an event
  built the way API Gateway builds one. That proves the conversion; it
  cannot prove anything about the Runtime API poll and response POST
  around it, which the bref-adapter package's own end-to-end tests cover
  against a real fake server.
- **Under a spawned server, over a real socket**: the committed
  framework suite spawns `php -S -d enable_post_data_reading=0` and,
  through `RuntimeDetector`, runs `FpmAdapter` under the CLI server's
  superglobal population — the only way `php://input` sees a genuine
  request. The CLI server is not a production SAPI, so what this proves
  is the bridge's own behavior, not FPM's or FrankenPHP's. It also
  spawns servers configured the *wrong* way, on purpose: one with
  `enable_post_data_reading` left on, to prove the refusal; one with no
  trusted-proxy policy, to prove a forwarded scheme from a
  directly-reachable client is ignored; and one with `max_input_vars`
  set below the contract, to prove a form that runtime's own
  `parse_str()` would have shortened is refused instead.
  `kinetis/roadrunner-adapter` runs the same suite this way against a
  real, spawned `rr serve` process, which *is* the production path: a
  RoadRunner request only ever exists as the real Goridge wire protocol
  between `rr` and a real PHP worker.
- **Under the real SAPIs**, in CI (`integration.yml`'s
  `runtime-conformance` job): a FrankenPHP worker loop behind Caddy, and
  PHP-FPM behind nginx, each in its own container with the same driver
  pointed at it instead of at a spawned process. That is the only place
  each production SAPI's own population of headers, client address and
  body, its own form parsing, and its own streaming path are exercised.
  The streaming case times the body as it arrives, so a proxy holding a
  stream back until the end fails it — which is what nginx does with
  `fastcgi_buffering` at its default `on`, and why the FPM fixture sets
  it `off` (`X-Accel-Buffering: no` on the response is the other way to
  get the same result in a real deployment).


The RoadRunner run has its own CI job (`integration.yml`'s
`roadrunner-conformance`), which needs `ext-sockets` and a fetched `rr`
binary — see {doc}`runtime-adapters`. It runs the suite unfiltered: the
two behaviors that environment cannot deliver are declared by its driver
and asserted in both directions rather than skipped.

## See also

- {doc}`runtime-adapters` — the adapters this suite holds to one
  contract, and how to write your own.
- {doc}`routing-validation` — the routes and DTOs these requests target.
- {doc}`container` — `AppScope` and the binding rules a booted
  application follows.
- {doc}`persistence` — driver selection, and why a test run gets the PDO
  drivers.
