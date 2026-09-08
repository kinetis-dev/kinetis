<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/broadcasting</strong>
  <br>
  <strong>Real-time broadcasting for Kinetis over the Pusher Channels protocol</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/broadcasting"><img src="https://img.shields.io/packagist/v/kinetis/broadcasting?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/broadcasting"><img src="https://img.shields.io/packagist/dt/kinetis/broadcasting" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/broadcasting"><img src="https://img.shields.io/packagist/php-v/kinetis/broadcasting" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/broadcasting"><img src="https://img.shields.io/packagist/l/kinetis/broadcasting" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

One driver, speaking the wire protocol Soketi, Laravel Reverb, and
Pusher's own hosted service all implement identically — non-blocking,
over [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).

```php
use Kinetis\Broadcasting\Broadcaster;

final readonly class OrderUpdated implements \Kinetis\Broadcasting\ShouldBroadcast
{
    public function __construct(private string $orderId, private string $status) {}

    public function broadcastOn(): array { return ["private-orders.{$this->orderId}"]; }
    public function broadcastAs(): string { return 'order.updated'; }
    public function broadcastWith(): array { return ['status' => $this->status]; }
}

// From a controller or a #[Listener] method, both constructor-inject Broadcaster:
$broadcaster->event(new OrderUpdated($orderId, 'shipped'));
```

A private or presence channel authorizes through one attribute:

```php
use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class OrderChannels
{
    // OrderRepository is your own; this package resolves the class
    // through the container like any other service.
    public function __construct(private OrderRepository $orders) {}

    #[BroadcastChannel('orders.{orderId}')]
    public function authorize(CurrentUserInterface $user, string $orderId): bool
    {
        return $this->orders->belongsTo($orderId, $user->id());
    }
}
```

`POST /broadcasting/auth` — the endpoint a Pusher-protocol client library
calls automatically before subscribing — is discovered and wired with
zero registration.

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `Kinetis\Broadcasting\BroadcasterInterface`,
  selected by `BROADCAST_DRIVER` (`"null"`, the default — a silent no-op;
  or `"pusher"`, requiring `BROADCAST_APP_ID`/`BROADCAST_KEY`/
  `BROADCAST_SECRET`). Validated and built at worker boot, not lazily —
  a misconfigured `BROADCAST_DRIVER=pusher` fails before the first
  request, not on whichever one happens to broadcast first.
- **`Kinetis\Broadcasting\BroadcastChannelRegistry`**, discovering every
  `#[BroadcastChannel]`-attributed method anywhere under your own PSR-4
  roots.
- **`POST /broadcasting/auth`**, a real Kinetis route
  (`Kinetis\Broadcasting\Http\BroadcastAuthController`), discovered the
  same way any `Kinetis\Http\Routing\RouteDiscovery`-found controller is.
- **The `broadcasting` middleware group** that route references, with
  `Kinetis\Broadcasting\Http\BroadcastOriginMiddleware` as its permanent
  member. Join it with `#[AsMiddlewareGroup('broadcasting')]` — a thin
  subclass of `kinetis/auth`'s `BearerAuthMiddleware` or
  `kinetis/auth-jwt`'s `JwtAuthMiddleware` is enough — to authenticate
  this one route and nothing else, and an authorizer taking
  `CurrentUserInterface` receives the identity it publishes.

Nothing else — `Kinetis\Broadcasting\Broadcaster` (the service your own
code constructor-injects) autowires from the bound
`BroadcasterInterface` with no binding of its own.

## Configuration

```
BROADCAST_DRIVER=pusher
BROADCAST_APP_ID=your-app-id
BROADCAST_KEY=your-key
BROADCAST_SECRET=your-secret
BROADCAST_HOST=soketi.example.com
BROADCAST_PORT=6001
BROADCAST_TLS=false
BROADCAST_ALLOWED_ORIGINS=https://app.example
```

| Key | Default | Purpose |
|---|---|---|
| `BROADCAST_DRIVER` | `null` | `null` or `pusher`. |
| `BROADCAST_APP_ID` | *(required for `pusher`)* | The app id both sides agree on. |
| `BROADCAST_KEY` | *(required for `pusher`)* | The public key. |
| `BROADCAST_SECRET` | *(required for `pusher`)* | Never sent to a browser. |
| `BROADCAST_HOST` | `api.pusherapp.com` | The broker's host. |
| `BROADCAST_PORT` | `443` | The broker's port. |
| `BROADCAST_TLS` | `true` | Whether to connect over TLS. |
| `BROADCAST_ALLOWED_ORIGINS` | *(empty)* | Extra exact `Origin` values this route's own guard admits on `POST /broadcasting/auth`. The request's own origin, and a request sending no `Origin` at all, pass without it. A cross-origin browser request must also be allowed by the app's global `CorsMiddleware`. |
| `BROADCAST_CHANNEL_DISCOVERY_PATHS` | *(unset)* | Comma-separated sub-paths (relative to each PSR-4 base directory) restricting the `#[BroadcastChannel]` scan, for a large application that wants a bounded scan. |

The six Pusher connection keys are scoped — `BROADCAST_KEY` +
`notifications` → `BROADCAST_NOTIFICATIONS_KEY`; `BROADCAST_DRIVER`,
`BROADCAST_ALLOWED_ORIGINS` and `BROADCAST_CHANNEL_DISCOVERY_PATHS` are
read unscoped. Full reference:
[kinetis.dev/docs/config.html](https://kinetis.dev/docs/config.html).

## Installation

```sh
composer require kinetis/broadcasting
```

Requires PHP 8.4+, [`kinetis/framework`](https://github.com/kinetis-dev/framework), and [`kinetis/revolt-http-client`](https://github.com/kinetis-dev/revolt-http-client).
Full documentation:
[kinetis.dev/docs/broadcasting.html](https://kinetis.dev/docs/broadcasting.html).

## License

MIT — see [LICENSE](LICENSE).
