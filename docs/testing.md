# Testing

Kinetis tests an application through the application — the real
container, the real discovery, the real middleware pipeline — rather than
against a hand-assembled approximation of it. A test that passes tells
you the request would have worked.

## Set up PHPUnit

`Kinetis\Testing` ships with `kinetis/framework`. PHPUnit is a
development dependency of the application:

```{code-block} bash
composer require --dev phpunit/phpunit
```

```{code-block} json
:caption: composer.json

"autoload-dev": {
    "psr-4": {
        "App\\Tests\\": "tests/"
    }
}
```

```{code-block} xml
:caption: phpunit.xml

<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="App">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`kinetis/skeleton` ships this configuration and
`tests/Http/WelcomeControllerTest.php`. Run the suite with
`vendor/bin/phpunit`.

## Testing a route

Extend `Kinetis\Testing\ApplicationTestCase` and point it at the project
root. It boots the application before each test and gives you a client:

```{code-block} php
:caption: tests/OrderControllerTest.php

<?php

declare(strict_types=1);

namespace App\Tests;

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
$this->client->request('PATCH', '/users/42', body: $payload, headers: $headers);
```

`body` is a plain array, JSON-encoded, with `Content-Type:
application/json` unless you pass a JSON-shaped one of your own. A
`Content-Type` that is not JSON, or two differently-cased `Content-Type`
headers that disagree, throws rather than sending a request whose header
contradicts its bytes.

A `Cookie` header, under any letter-case, is sent verbatim and parsed
into `getCookieParams()`, so a test drives anything that reads cookies
(`SessionMiddleware`, see {doc}`session`) the way a runtime adapter does:

```{code-block} php
$this->client->get('/dashboard', headers: ['Cookie' => 'kinetis_session=' . $id]);
```

### Forms and raw bodies

`post()`, `put()` and `patch()` always send JSON. A route that reads a
form field — the `_token` field CSRF protection checks, for one — needs a
form-encoded request:

```{code-block} php
$this->client->postForm('/login', ['email' => 'ada@example.com', 'password' => 'secret']);
$this->client->putForm('/settings', ['theme' => 'dark']);
$this->client->patchForm('/settings', ['theme' => 'dark']);

// A raw string body, sent exactly as given — a webhook payload or binary content.
$this->client->raw('POST', '/webhooks/stripe', $rawPayload, ['Content-Type' => 'application/json']);
```

A form request goes through the same encoding a browser's does, so every
field arrives as a string. {ref}`testing-reference-client-requests` gives
the exact encoding and `Content-Type` rules for every method.

### Uploads

`send()` dispatches a PSR-7 request you build yourself, for a multipart
upload or anything the methods above do not cover. Send the multipart
bytes, not a parsed body: `RequestBodyMiddleware` parses the body every
request carries, and a request that declares `multipart/form-data`
without matching bytes is refused with `400` before the handler runs.

```{code-block} php
use Nyholm\Psr7\ServerRequest;

$boundary = '----KinetisTestBoundary';
$body = "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
    . "Ada\r\n"
    . "--{$boundary}\r\n"
    . "Content-Disposition: form-data; name=\"avatar\"; filename=\"avatar.png\"\r\n"
    . "Content-Type: image/png\r\n\r\n"
    . $fileContents . "\r\n"
    . "--{$boundary}--\r\n";

$request = new ServerRequest(
    'POST',
    '/avatars',
    ['Content-Type' => "multipart/form-data; boundary={$boundary}"],
    $body,
);

$this->client->send($request)->assertOk();
```

Every line ending is a literal CRLF and the closing delimiter carries its
trailing `--`: that is the only spelling the multipart parser reads as a
delimiter (see {ref}`runtime-reference-multipart`). `send()` completes
nothing for you — a request that needs cookies read sets
`withCookieParams()` alongside its `Cookie` header.

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
| `assertJsonPathMissing(path)` | nothing, or JSON `null`, is at that path |
| `assertValidationError(...$path)` | the response is 422 and carries a violation at exactly that segmented path — `('items', 0, 'sku')`, or no arguments for a violation against the payload as a whole |
| `assertBodyContains(string)` | the raw body contains the text |

A failed status, JSON or validation assertion prints the response body
alongside the mismatch, since an unexpected status is usually explained
by what the body says. `json()` and `body()` are there for anything the
assertions don't cover, and both can be called repeatedly.

## Testing against a database

A test that writes rows has to leave the database as it found it, or the
next test inherits its data. `kinetis/persistence` ships one strategy for
that, a PHPUnit trait that asks the test which connection to isolate.

### Emptying tables: `DatabaseTruncation`

Deletes the rows in the tables you name, before each test. It holds no
transaction of its own, so it works for code that manages its own
transactions, and on every driver.

```{code-block} php
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTruncation;
use Kinetis\Testing\ApplicationTestCase;

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
Schema creation belongs outside the trait — in a migration run once
before the suite, not in a test.
```

(testing-orm)=
### Testing ORM code

A test runs `kinetis/orm` code under the same unit-of-work lifecycle the
application does (see {doc}`orm`):

- **Each unit of work gets its own manager.** `PackageBootstrap::bindOrm()`
  binds `EntityManager` lazily to each request scope, so a request through
  `$this->client` that resolves it gets its own manager, and that manager
  closes when the request's scope disposes at request end. Setup and
  assertions in the test body open their own from `OrmFactory` and close
  it. A manager kept across steps answers later loads from its identity
  map instead of the database.
- **Only `flush()` persists.** A change still unflushed when its manager
  closes — at `close()`, or when its request ends — is discarded without
  a write.
- **Assertions read through a new manager**, so they see what the
  database holds.

```{code-block} php
:caption: tests/PublishArticleTest.php

use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTruncation;
use Kinetis\Testing\ApplicationTestCase;

final class PublishArticleTest extends ApplicationTestCase
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
        return ['articles'];
    }

    public function test_publishing_stores_the_published_article(): void
    {
        $this->unitOfWork(static function (EntityManager $entities): void {
            $entities->persist(new Article(42, 'Launch', 7));
            $entities->flush();
        });

        $this->client->post('/articles/42/publish')->assertOk();

        $this->unitOfWork(static function (EntityManager $entities): void {
            self::assertSame(
                ArticleStatus::Published,
                $entities->repository(Article::class)->findOrFail(42)->status(),
            );
        });
    }

    /** @param callable(EntityManager): void $work */
    private function unitOfWork(callable $work): void
    {
        $entities = $this->app->get(OrmFactory::class)->open();

        try {
            $work($entities);
        } finally {
            $entities->close();
        }
    }
}
```

`Article` is the [package README](https://github.com/kinetis-dev/orm#readme)'s
entity with a `status()` accessor added, and the route is
`ArticleController::publish()` from {doc}`orm`. `unitOfWork()` uses the
factory bound by `kinetis/orm`.

A returned `flush()` does not always mean COMMIT was acknowledged, and a
test's assertion has to match which one ran. A standalone `flush()` with
writes commits on return, so a test may assert its work through a new
manager once it returns. A no-op `flush()` — nothing pending — sends no
transaction at all. A `flush()` on a manager bound to an enclosing
`OrmFactory::transaction()` writes provisionally: only the transaction's
own return acknowledges COMMIT, so a test asserting mid-transaction work
has to wait for that return, not the inner `flush()`.
`UnknownFlushOutcomeException` and `CommitNotAcknowledgedException` leave
the outcome unknown: neither the code under test nor the test assumes the
work failed and replays it; each establishes what the database holds
through a new manager or on the link. The README's "When a flush fails"
and "When a session fails" define both outcomes.

Under PHPUnit every request runs in the test process, not in a persistent
worker. The integration workflow's `orm-runtime` job sends a request
sequence through a FrankenPHP worker and through PHP-FPM (see
{doc}`appendix-ci`).

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

(loop-liveness)=
## Proving a path keeps the loop responsive

`Kinetis\Testing\LoopLiveness::turnedDuring()` answers whether one
operation lets the event loop turn while it waits — the check static
analysis cannot make (see {ref}`non-blocking-application-io`). Use it
when a path must not block a persistent worker: an outbound call, a
queue push, a native database query.

```{code-block} php
use Kinetis\Testing\LoopLiveness;

self::assertTrue(LoopLiveness::turnedDuring(
    fn () => $this->app->get(CarrierClient::class)->track('1Z999'),
));
```

The operation needs something slow to wait on, such as a local upstream
that answers after a delay; an operation that finishes faster than the
sentinel is inconclusive rather than a pass. A database path needs the
native driver to be observable: PHPUnit runs outside a persistent
worker, so `DB_DRIVER=auto` selects blocking PDO — return
`'DB_DRIVER' => 'native'` from `configOverrides()` (see
{doc}`persistence`).

{ref}`testing-reference-loop-liveness` gives the complete procedure with
a slow upstream fixture, the three outcomes, and the PHPStan exemption
for the fixture's setup calls.

## Conformance-testing a runtime adapter

A custom runtime adapter extends
`Kinetis\Testing\Runtime\RuntimeAdapterConformanceTestCase` with a driver
for its environment, and the shared suite holds it to the contract the
built-in adapters meet. {ref}`testing-reference-conformance` describes
the driver interface, what the suite asserts, and what each built-in
adapter's run proves.

## See also

- {doc}`routing-validation` — the routes and DTOs these requests target.
- {doc}`container` — `AppScope` and the binding rules a booted
  application follows.
- {doc}`persistence` — driver selection, and why a test run gets the PDO
  drivers.
- {doc}`appendix-testing` — request construction rules, the loop-liveness
  procedure and the runtime conformance suite.
