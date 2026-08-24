# Broadcasting

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/broadcasting
```
````

Real-time updates over the Pusher Channels wire protocol — Soketi,
[Laravel Reverb](https://reverb.laravel.com/), and Pusher's own hosted
service all implement it identically, so one driver covers all three;
only the host, port, and TLS setting differ between them.
`Kinetis\Broadcasting\Driver\PusherBroadcaster` sends over
`kinetis/revolt-http-client`, so triggering an event suspends the
calling Fiber rather than blocking the worker while the broker accepts
it.

Nothing here runs a WebSocket server — Kinetis stays an HTTP framework.
Point `BROADCAST_HOST`/`BROADCAST_PORT` at Soketi, Reverb, or Pusher, and
this package handles the two things an HTTP process actually does in a
broadcasting setup: signing the trigger request that pushes an event,
and signing the subscription request a client makes before it can join
a private or presence channel.

## Sending an event

Two shapes, both reaching `Kinetis\Broadcasting\BroadcasterInterface`
underneath — a controller or a `#[Listener]` method constructor-injects
`Kinetis\Broadcasting\Broadcaster`:

```{code-block} php
use Kinetis\Broadcasting\Broadcaster;

final readonly class TrackShipment
{
    public function __construct(private Broadcaster $broadcaster) {}

    public function markShipped(string $orderId): void
    {
        // ... update the order ...

        $this->broadcaster->broadcast('private-orders.' . $orderId, 'order.shipped', [
            'status' => 'shipped',
        ]);
    }
}
```

Or describe an event once and broadcast it by value — implement
`Kinetis\Broadcasting\ShouldBroadcast` on the DTO your own code
already dispatches:

```{code-block} php
use Kinetis\Broadcasting\ShouldBroadcast;

final readonly class OrderUpdated implements ShouldBroadcast
{
    public function __construct(
        private string $orderId,
        private string $status,
    ) {}

    public function broadcastOn(): array
    {
        return ["private-orders.{$this->orderId}"];
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }

    public function broadcastWith(): array
    {
        return ['status' => $this->status];
    }
}

$broadcaster->event(new OrderUpdated($orderId, 'shipped'));
```

`Broadcaster::event()` calls
`Kinetis\Broadcasting\BroadcasterInterface`'s `broadcast()` once
per channel `broadcastOn()` names, with `broadcastAs()`'s event name and
`broadcastWith()`'s payload.

`ShouldBroadcast` is deliberately not wired into
`Kinetis\Events\EventDispatcher` automatically — unlike
`Kinetis\Events\ShouldQueue` (checked per listener, inside a dispatch
loop already built for it), whether an event broadcasts is a per-event
concern with no natural hook in that loop. Call `Broadcaster::event()`
explicitly, typically from inside the `#[Listener]` method that would
otherwise dispatch a queued job for the same event.

## Authorizing private and presence channels

A client subscribing to a `private-*` or `presence-*` channel calls
`POST /broadcasting/auth` automatically — every mainstream Pusher-
protocol client library (pusher-js, Laravel Echo, `laravel-echo` on the
JS side, Soketi's own SDKs) does this without being told to. Installing
this package is the entire registration: the route is discovered the
same way any `Kinetis\Http\Routing\RouteDiscovery`-found
controller is.

Authorize a channel with one attributed method:

```{code-block} php
use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class OrderChannels
{
    public function __construct(private OrderRepository $orders) {}

    #[BroadcastChannel('orders.{orderId}')]
    public function authorizeOrder(CurrentUserInterface $user, string $orderId): bool
    {
        return $this->orders->belongsTo($orderId, $user->id());
    }
}
```

`{orderId}` matches a channel name segment the same way a route
placeholder does, but never crosses a `.` — the pattern names the
channel **without** its `private-`/`presence-` prefix, since the prefix
only selects which of the two auth responses gets built, not which
pattern applies. A method's parameters must be: an optional leading
`CurrentUserInterface`, then exactly one `string` parameter per
placeholder, named to match, in order — a mismatch throws
`InvalidChannelAuthorizerException` at registration, not the first time
a client happens to hit it.

Returning `bool` authorizes (or rejects) a **private** channel.
Returning an `array` authorizes a **presence** channel, and the array
becomes that subscriber's `channel_data` — every other subscriber sees
it in their own `pusher:subscription_succeeded` payload, so it's the
right place for a display name or avatar, not sensitive data:

```{code-block} php
#[BroadcastChannel('team.{teamId}')]
public function authorizeTeam(CurrentUserInterface $user, string $teamId): array
{
    return ['user_id' => $user->id(), 'user_info' => ['name' => $this->users->nameFor($user->id())]];
}
```

A channel with no authorizer registered for it, or a request with no
`CurrentUserInterface` on the request scope (register one from your own
auth middleware first — see {doc}`auth` or {doc}`auth-jwt`), is rejected
with `401`/`403` — never a silent subscribe.

## Configuring

```{code-block} text
BROADCAST_DRIVER=pusher
BROADCAST_APP_ID=your-app-id
BROADCAST_KEY=your-key
BROADCAST_SECRET=your-secret
BROADCAST_HOST=soketi.example.com
BROADCAST_PORT=6001
BROADCAST_TLS=false
```

`BROADCAST_DRIVER` defaults to `null` — `Kinetis\Broadcasting\NullBroadcaster`,
a silent no-op, the same "sensible default nobody has to opt into"
pattern `LoggerInterface` → `NullLogger` already establishes. Setting it
to `pusher` additionally requires `BROADCAST_APP_ID`/`BROADCAST_KEY`/
`BROADCAST_SECRET` — validated and the driver built at worker boot, not
lazily, so a misconfiguration fails before the first request rather than
on whichever one happens to broadcast first. Every key is
`Config::scopedKey()`-scoped for named connections:
`BROADCAST_KEY` + `notifications` → `BROADCAST_NOTIFICATIONS_KEY`.

Pointed at a real Pusher account, drop `BROADCAST_HOST`/`BROADCAST_PORT`/
`BROADCAST_TLS` and use the account's own cluster — `api-{cluster}.pusher.com`,
port `443`, TLS on (the defaults).

## Verified

`PusherBroadcaster`'s signing algorithm is checked against
`pusher/pusher-php-server`'s own real source, not reconstructed from
documentation, and every private/presence channel authorization and
trigger request in this package's own test suite is checked against an
independently computed HMAC-SHA256 signature. Beyond that, the full
chain — attribute discovery, `CurrentUserInterface` resolution,
authorization, and both channel-auth response shapes — has been run
end to end against a real Soketi broker and a real WebSocket client: a
public-channel broadcast delivered to a subscriber, a private-channel
subscription signed by this package's own driver accepted by Soketi
with the triggered event delivered to it, and a presence-channel
subscription signed by the real `BroadcastAuthController` accepted with
the correct `channel_data` reflected back.
