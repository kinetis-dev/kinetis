# Testing

`Kinetis\Testing\TestClient` wraps a `Kernel` and builds requests for you,
so a test doesn't have to construct a `ServerRequest` and call `handle()`
by hand.

```{code-block} php
use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\Router;
use Kinetis\Testing\TestClient;
use App\Controllers\UserController;

$app = new AppScope();
$app->boot();

$router = new Router();
$router->register(UserController::class);

$client = new TestClient(new Kernel($app, $router));

$response = $client->get('/users/42');
$response = $client->post('/users', body: ['name' => 'John Doe', 'email' => 'john@example.com']);
```

`get()`/`post()`/`put()`/`patch()`/`delete()` all return the same PSR-7
`ResponseInterface` a real request would — nothing wrapped, so
`getStatusCode()`/`getBody()`/`getHeaderLine()` work exactly as they
already do in {doc}`getting-started`'s examples.

## Query parameters and bodies

```{code-block} php
$client->get('/users', query: ['page' => 2, 'limit' => 10]);
$client->post('/orders', body: ['sku' => 'ABC123', 'quantity' => 2]);
```

`body` is a plain array, JSON-encoded automatically. `Content-Type:
application/json` is set unless you pass your own:

```{code-block} php
$client->post('/webhooks', body: $payload, headers: ['Content-Type' => 'application/vnd.custom+json']);
```

## The general form

Every verb method calls `request()`, which is available directly for
anything the shorthands don't cover:

```{code-block} php
$client->request('DELETE', '/users/42', headers: ['Authorization' => 'Bearer test-token']);
```

## See also

- {doc}`getting-started` — the `UserController` example these requests
  target.
- {doc}`container` — `AppScope`/`Router` construction, the same wiring
  `TestClient` dispatches through.
