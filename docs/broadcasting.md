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

`ShouldBroadcast` is not wired into `Kinetis\Events\EventDispatcher`
automatically — unlike `Kinetis\Events\ShouldQueue` (checked per
listener, inside a dispatch loop already built for it), whether an event
broadcasts is a per-event concern with no natural hook in that loop.
Call `Broadcaster::event()` explicitly, typically from inside the
`#[Listener]` method that would otherwise dispatch a queued job for the
same event.

## Authorizing private and presence channels

A client subscribing to a `private-*` or `presence-*` channel calls
`POST /broadcasting/auth` automatically — every mainstream Pusher-
protocol client library (pusher-js, Laravel Echo, Soketi's own SDKs)
does this without being told to. Installing this package is the entire
registration: the route is discovered the same way any
`Kinetis\Http\Routing\RouteDiscovery`-found controller is, and every
`#[BroadcastChannel]` method is itself part of the AOT cache — see
{doc}`caching`.

The client sends `socket_id`/`channel_name` as
`application/x-www-form-urlencoded` fields. `RequestBodyMiddleware` (see
{doc}`middleware`) has already bounded the body and parsed those fields
into `getParsedBody()` by the time the endpoint runs, so an oversized
request — with or without an honest `Content-Length` — gets a `413`
before the controller sees anything.

Both fields must also match the Pusher protocol's own grammar —
`socket_id` a pair of digit runs joined by a dot (`1234.5678`),
`channel_name` an optional leading `#` followed by one or more of
`-a-zA-Z0-9_=@,.;` — checked by `Kinetis\Broadcasting\PusherProtocol`
before anything else about the request is looked at, including whether
an authorizer is even registered for the channel: a value that can't
possibly reach a real Pusher/Soketi/Reverb broker never runs application
authorization code at all, and gets a `422` instead.
`PusherBroadcaster`'s own public signing methods enforce the identical
grammar, so a direct call bypassing this controller entirely is held to
the same rule.

Authorize a channel with one attributed method:

```{code-block} php
use Kinetis\Broadcasting\Attributes\BroadcastChannel;
use Kinetis\Http\CurrentUserInterface;

final class OrderChannels
{
    // Your own repository, resolved through the container.
    public function __construct(private OrderRepository $orders) {}

    #[BroadcastChannel('orders.{orderId}')]
    public function authorizeOrder(CurrentUserInterface $user, string $orderId): bool
    {
        return $this->orders->belongsTo($orderId, $user->id());
    }
}
```

`{orderId}` matches one whole channel-name segment and never crosses a
`.` — the pattern names the channel **without** its
`private-`/`presence-` prefix, since the prefix only selects which of
the two auth responses gets built, not which pattern applies. A method's
parameters must be: an optional leading
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

The Pusher protocol **requires** a non-empty string `user_id` (at most
128 bytes) in that array — `user_info` and anything else are optional,
and the whole thing must encode to at most 1024 bytes of JSON. A result
missing this, or exceeding either limit, is never signed: the
subscription is rejected the same way a `false` private-channel result
is, not a server error.

The endpoint authorizes `private-*` and `presence-*` channels only: a
channel name carrying neither prefix is `422`. A channel with no
authorizer registered for it is rejected with `403`.

The leading `CurrentUserInterface` parameter decides whether the channel
requires an identity. Declaring it means the request must carry a
`CurrentUserInterface` on its scope — published by middleware in the
`broadcasting` group, below — and a request without one is rejected with
`401` before the method runs. Omitting it
means the method authorizes from its own context, so an authorizer can
admit an anonymous request, or one identified by something
other than a logged-in user (an invite token, a signed link, a tenant
resolved from the host). A `private-`/`presence-` prefix selects which
auth response gets signed; it does not by itself impose an application
login. What a channel requires is whatever its authorizer checks.

### Securing the endpoint

The endpoint's own middleware is the `broadcasting` middleware group,
which `BroadcastAuthController` references with
`#[Middleware('@broadcasting')]` like any route references a group (see
{doc}`middleware`). It is route middleware, resolved from each request's
own scope, so authentication attached here runs on this one route and
nowhere else — which is what lets `kinetis/auth` and `kinetis/auth-jwt`
stay route-only. Two layers, in the order they run:

1. **`Origin` validation, always on.** `BroadcastOriginMiddleware` is
   this package's permanent member of the group, at priority `100`. A
   request passes it three ways: carrying no `Origin` header at all (any
   non-browser client — a server-side test, curl, a native app),
   carrying the request's own `scheme://authority` (a page this same
   application serves, which needs no configuration), or carrying an
   exact match from `BROADCAST_ALLOWED_ORIGINS` — a comma-separated
   list, empty by default, for a front end served from a different host
   than the endpoint. Any other origin is `403`, settled before the rest
   of the group and the controller run.

   ```{code-block} text
   :caption: .env
   BROADCAST_ALLOWED_ORIGINS=https://app.example,https://admin.example
   ```

   Both sides of the comparison are exact strings: an `Origin` is
   `scheme://host[:port]` with no path, no trailing slash and no default
   port, which is the form the request URI's own scheme and authority
   already carry. `https://app.example` and `http://app.example`, or
   `app.example` and `app.example:8080`, are different origins. Behind a
   TLS-terminating proxy, the scheme half of that comparison is the one
   `TRUSTED_PROXIES` decides (see {doc}`config`) — a deployment that
   does not name its edge sees `http` where the browser sends `https`,
   and has to list the origin explicitly.

   ```{note}
   **This setting admits the origin at this route only; it is not a CORS
   policy.** A browser posting here from another origin also has to be
   admitted by the application's global `CorsMiddleware` — that origin
   on its allow list, with `allowCredentials` and the `Authorization`
   header configured for whatever the client sends — or the browser
   discards the response before the page ever reads it. Listing an
   origin here and nowhere else is not enough. See {doc}`middleware`.
   ```

2. **Your own authentication, via `#[AsMiddlewareGroup('broadcasting')]`.**
   Declare membership on the middleware class and it joins the
   endpoint's pipeline at the attribute's default priority `50` — after
   the origin check at `100`. Because the group resolves from the
   request's scope, a thin subclass is the whole integration, and the
   `CurrentUserInterface` it publishes is what an authorizer declaring
   that parameter receives:

   ```{code-block} php
   use Kinetis\Auth\BearerAuthMiddleware;
   use Kinetis\Http\Attributes\AsMiddlewareGroup;

   #[AsMiddlewareGroup('broadcasting')]
   final readonly class BroadcastAuthMiddleware extends BearerAuthMiddleware {}
   ```

   `kinetis/auth-jwt`'s middleware takes the same shape — its keys live
   on the `JwtAuthenticator` registered at boot, so the subclass is
   empty here too; see {doc}`auth-jwt`:

   ```{code-block} php
   use Kinetis\AuthJwt\JwtAuthMiddleware;
   use Kinetis\Http\Attributes\AsMiddlewareGroup;

   #[AsMiddlewareGroup('broadcasting')]
   final class BroadcastJwtAuthMiddleware extends JwtAuthMiddleware {}
   ```

An application whose channel authorizers are all anonymous adds nothing:
the group already exists wherever this package is installed, and a
request carrying no identity reaches an authorizer that declares no
`CurrentUserInterface`. There is no identity guard on this endpoint —
what a channel requires is whatever its own authorizer checks.

### Pattern grammar and conflicts

A pattern is dot-separated segments. Each segment is either one literal
or exactly one whole `{name}` placeholder — `orders`, `{orderId}`,
`orders.{orderId}.items`. A placeholder sharing its segment with literal
text (`order-{id}`), two placeholders in one segment (`{a}-{b}`), a
stray brace, an empty segment, and a placeholder name used twice in one
pattern (`orders.{id}.{id}`) are all rejected where the pattern is
parsed, so a malformed pattern fails at registration or when a compiled
cache is hydrated, never at match time.

Two patterns conflict when some channel name could match both: they have
the same segment count, and at every position either both hold the same
literal or at least one holds a placeholder. Registering the second one
throws `InvalidChannelAuthorizerException` (or the classified
cache-artifact exception when hydrating a compiled cache), whichever
order the two arrive in. So each of these pairs is rejected:

- `orders.admin` and `orders.{orderId}` — the literal segment satisfies
  the placeholder.
- `orders.{orderId}` and `orders.{id}` — the same template under a
  different placeholder name.
- `orders.{orderId}` and `{scope}.admin` — they cross at
  `orders.admin`.

Patterns that no channel name can share coexist. `orders.{orderId}` and
`team.{teamId}` hold unequal literals in their first segment;
`orders.{orderId}` and `lobby` have different segment counts.

There is no precedence between two authorizers, and no fallthrough from
a denial to a broader pattern: at most one pattern claims any channel
name, and its authorizer's answer is the answer. A channel that needs a
special case for one name handles it inside a single authorizer:

```{code-block} php
#[BroadcastChannel('orders.{orderId}')]
public function authorizeOrder(CurrentUserInterface $user, string $orderId): bool
{
    if ($orderId === 'admin') {
        return $this->users->isAdmin($user->id());
    }

    return $this->orders->belongsTo($orderId, $user->id());
}
```

The alternative is to give the two cases channel names that cannot
collide — `orders.{orderId}` and `orders-admin.{view}` — which the same
rule then keeps apart on its own.

## Configuring

```{code-block} text
BROADCAST_DRIVER=pusher
BROADCAST_APP_ID=your-app-id
BROADCAST_KEY=your-key
BROADCAST_SECRET=your-secret
BROADCAST_HOST=soketi.example.com
BROADCAST_PORT=6001
BROADCAST_TLS=false
BROADCAST_ALLOWED_ORIGINS=https://app.example
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

`BROADCAST_ALLOWED_ORIGINS` is the one key the driver never reads: it
belongs to the auth endpoint's own origin check above, so it applies
whichever driver is configured and takes no connection scope.

`BROADCAST_HOST` defaults to `api.pusherapp.com`, with port `443` and
TLS on. There is no cluster selector: a Pusher account outside the
default cluster reaches its own hostname only when `BROADCAST_HOST` is
set to it explicitly — `api-eu.pusher.com` for the `eu` cluster, and so
on for the cluster the account's dashboard names.
