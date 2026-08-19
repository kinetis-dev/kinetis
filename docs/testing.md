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
replaces the Soketi publisher and the queue, which leaves a real MySQL
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
`Content-Type: application/json` set unless you pass your own. Every verb
method calls `request()`, which is available directly for anything the
shorthands don't cover:

```{code-block} php
$this->client->request('PATCH', '/users/42', body: $payload, headers: $headers);
```

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

## See also

- {doc}`routing-validation` — the routes and DTOs these requests target.
- {doc}`container` — `AppScope` and the binding rules a booted
  application follows.
- {doc}`persistence` — driver selection, and why a test run gets the PDO
  drivers.
