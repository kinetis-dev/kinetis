# Middleware

Kinetis's middleware is plain [PSR-15](https://www.php-fig.org/psr/psr-15/)
— `Psr\Http\Server\MiddlewareInterface` and `RequestHandlerInterface` —
not an Kinetis-specific contract. Any existing PSR-15 middleware package
works against Kinetis unmodified, and middleware you write yourself isn't
learning a framework-specific shape.

```{code-block} php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestTimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);
        $response = $handler->handle($request);
        $elapsedMs = (microtime(true) - $start) * 1000;

        return $response->withHeader('X-Response-Time', sprintf('%.2fms', $elapsedMs));
    }
}
```

`process()` decides whether to call `$handler->handle($request)` at all —
call it and you're "before and after" middleware (like the timing example
above); return your own response without calling it and you've
short-circuited the pipeline before anything further down ever runs.

## Two pipelines, not one

Middleware register in two different places, for two different reasons.

(global-middleware-every-request-including-ones-that-never-match-a-route)=
### Global middleware — every request, including ones that never match a route

```{code-block} php
use Kinetis\Container\AppScope;

$app = new AppScope();
$app->middleware(RequestTimingMiddleware::class);
$app->middleware(CorsMiddleware::class);
$app->boot();
```

Registered on `AppScope` (locked after `boot()`, the same discipline as
`bind()`/`instance()` — see {doc}`container`), in registration order,
outermost first. This wraps `Kernel::handle()`'s *entire* body — the
OpenAPI/MCP short-circuits, routing itself, and a `404`/`405` from a
failed route match — not just a successfully dispatched request. That's
why logging or CORS belongs here: you want it to see every request, not
only the ones that happened to match something.

Global middleware is resolved from `AppScope`, not a per-request scope —
it has to wrap the request *before* any `RequestScope` exists (the
OpenAPI/MCP branches deliberately never create one at all; see
{doc}`core-concepts`), so it can't depend on one at construction time.
Practically, this makes a global middleware instance a worker-lifetime
singleton by default — the same "singleton via the container" pattern
{doc}`container` documents for a plain service. If your middleware holds
no per-request state as an instance property, that's exactly as safe as
any other `AppScope`-resolved service; if it needs something that varies
per request, reach for route middleware instead.

(discoverable-global-middleware)=
### Discoverable global middleware — no `AppScope::middleware()` call needed

`#[AsGlobalMiddleware]` registers a global middleware class by attribute
instead — the opposite direction from `#[Middleware]` above, which lives
on a *controller* referencing another class; this one lives on the
middleware class itself:

```{code-block} php
use Kinetis\Http\Attributes\AsGlobalMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[AsGlobalMiddleware]
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Request-Id', bin2hex(random_bytes(8)));
    }
}
```

Any class anywhere under one of your own PSR-4 roots carrying this
attribute joins the global pipeline automatically, with no
`$app->middleware(...)` call at all — and so does a class an installed
package offers through its `extra.kinetis` scan roots (see {doc}`cli`). It runs *inward* of every explicitly
registered middleware, as a group — explicit registration always wins.

**Ordering among multiple discovered classes** is `priority`, an integer
from `0` to `100` defaulting to `50` — higher runs more outer (closer to
`ExceptionHandlerMiddleware`, further from the controller). The default
sits at the midpoint specifically so a class can be nudged either more
outer or more inner than every unspecified default without needing to
know the range's extremes; a value outside `0`-`100` throws
`InvalidArgumentException` immediately, when the attribute is
constructed:

```{code-block} php
#[AsGlobalMiddleware(priority: 90)]
final readonly class RequestIdMiddleware implements MiddlewareInterface { /* ... */ }

#[AsGlobalMiddleware(priority: 10)]
final readonly class ResponseTimingMiddleware implements MiddlewareInterface { /* ... */ }
```

Two classes sharing a priority are ordered alphabetically by their own
fully-qualified class name instead, so the result never depends on
filesystem/scan order.

```{note}
This priority/alphabetical-tiebreak scheme belongs to *discovered*
middleware — this attribute, and `#[AsMiddlewareGroup]`
[below](#naming-a-stack-middleware-groups) for ordering within a group.
It exists because nothing else establishes a relative order between two
independently-discovered classes. `#[Middleware]` (class/method-level
route middleware, above) has no priority concept at all: multiple
`#[Middleware(...)]` attributes always run in the exact order they're
declared in your source — group references included — since a
controller's own attribute order is already an explicit, unambiguous
ordering with nothing left to break a tie on.
```

```{note}
Kinetis's own built-in middleware (`CorsMiddleware`, `RateLimitMiddleware`,
`AuthenticatedRateLimitMiddleware`) is never `#[AsGlobalMiddleware]`-attributed
— each needs app-specific constructor config (allowed origins, a limit) no
default could supply, so they stay opt-in via `$app->middleware(...)` only,
exactly as described below. This attribute is for *your* middleware.
```

Restrict the scan for a large application the same way as
{doc}`cli`'s route/command/tool discovery: `MIDDLEWARE_DISCOVERY_PATHS`,
comma-separated sub-paths relative to each PSR-4 base directory,
committed in `.env`. See {doc}`caching` for how this is compiled ahead of
time in production, alongside the route table itself.

### Scoping middleware to `/mcp` or `/openapi.json`/`/openapi` specifically

Global middleware already wraps `/mcp` and `/openapi.json`/`/openapi` — the
global pipeline covers `Kernel::handle()`'s entire body, these endpoints
included. `#[AsMcpMiddleware]` and `#[AsOpenApiMiddleware]` exist for the
narrower need: middleware that should run for *only* one of these
endpoints, not every other route too.

```{code-block} php
use Kinetis\Http\Attributes\AsMcpMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[AsMcpMiddleware]
final readonly class McpAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getHeaderLine('Authorization') !== 'Bearer ' . getenv('MCP_TOKEN')) {
            return new \Nyholm\Psr7\Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthenticated.']));
        }

        return $handler->handle($request);
    }
}
```

`#[AsOpenApiMiddleware]` is the identical attribute, shared by both
`/openapi.json` and `/openapi` — the same "expose the API's own shape"
concern, not two independently protectable surfaces. It reaches them by
a different route, though: those two are ordinary discovered routes on a
controller the framework ships, so the attribute publishes its classes
as a built-in `openapi` middleware group that the controller references
like any other route middleware. Nothing about using it changes; it is
worth knowing only because `routes:list` shows the expansion. Both attributes take
the exact same `priority` (bounded `0`-`100`, default `50`,
alphabetical tiebreak) that `#[AsGlobalMiddleware]` does, discovered by
the same project-wide scan, and both have an explicit-registration
counterpart — `AppScope::mcpMiddleware(SomeClass::class)`/
`AppScope::openApiMiddleware(SomeClass::class)` — mirroring
`AppScope::middleware()`.

```{note}
**Order matters**: a scoped pipeline runs *inside* the global one, not
instead of it. For a request to `/mcp`, that means global middleware
runs first (outermost), then any `#[AsMcpMiddleware]`/
`AppScope::mcpMiddleware()` classes, then the MCP request itself.
```

`/mcp` also gets one more, narrower knob: `Kernel`'s `$mcpAllowedOrigins`
constructor parameter — an exact list of `Origin` header values allowed
to reach it, checked before anything else on that endpoint runs (per the
MCP Streamable HTTP specification's requirement to validate `Origin` on
every incoming connection, to prevent DNS-rebinding attacks). Empty by
default — deny by default, the same posture `CorsMiddleware` takes — so
any request carrying an `Origin` header at all is rejected with `403`
until you explicitly list which origins may reach it. A request with no
`Origin` header (any non-browser client — `bin/kinetis mcp:serve`'s own
stdio transport, a server-to-server call, `curl`) is unaffected either
way, since DNS rebinding is specifically a browser attack that always
carries an `Origin`.

### Route middleware — attribute-driven, per endpoint

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\RateLimitMiddleware;

#[Middleware(AuthMiddleware::class)]
final readonly class OrderController
{
    #[Get('/orders')]
    #[Middleware(RateLimitMiddleware::class)]
    public function index(): array { /* ... */ }
}
```

`#[Middleware(SomeMiddleware::class)]` is repeatable and works at both
levels: class-level applies to every route on the controller and runs
outermost; method-level appends, closer to the controller. Stack as many
as you need at either level — in the example above, a request to
`GET /orders` runs `AuthMiddleware` first, then `RateLimitMiddleware`,
then the controller.

`Router::register()` discovers these the same way it discovers
`#[Get]`/`#[Post]`/etc. — one more `getAttributes()` call inside the
reflection loop it already runs, not a second pass over your controllers.

Unlike global middleware, route middleware is resolved from the request's
own `RequestScope`, wrapping only `Dispatcher::dispatch()` — deliberately
the opposite resolution source from global middleware, since this is
exactly the kind likely to need a per-request dependency.

(naming-a-stack-middleware-groups)=
### Naming a stack: middleware groups

When several routes need the same few middleware in the same order,
`#[AsMiddlewareGroup]` names that stack once, on the middleware classes
themselves:

```{code-block} php
use Kinetis\Http\Attributes\AsMiddlewareGroup;

#[AsMiddlewareGroup('auth')]
#[AsMiddlewareGroup('admin', priority: 90)]
final class AuthMiddleware implements MiddlewareInterface { /* ... */ }
```

```{code-block} php
#[AsMiddlewareGroup('admin', priority: 50)]
final class RequireAdminMiddleware implements MiddlewareInterface { /* ... */ }
```

A route or controller then references the whole group with a `@`-prefixed
name instead of listing every class:

```{code-block} php
final readonly class OrderController
{
    #[Get('/orders')]
    #[Middleware('@auth')]
    public function index(): array { /* ... */ }

    #[Get('/orders/{id}/refund')]
    #[Middleware('@admin')]
    public function refund(int $id): array { /* ... */ }
}
```

`GET /orders/{id}/refund` runs `AuthMiddleware` then
`RequireAdminMiddleware` — the `admin` group's own order, from the
priorities declared above: higher runs more outer, `0`-`100`, defaulting
to `50`, with members sharing a priority ordered alphabetically by class
name. The attribute is repeatable, so one class can belong to several
groups and hold a different position in each.

Nothing needs registering. Any class anywhere under one of your own PSR-4
roots carrying `#[AsMiddlewareGroup]` is found automatically, the same
scan that finds `#[AsGlobalMiddleware]` classes (see
[above](#discoverable-global-middleware),
including how to restrict it on a large application).

A group expands where its reference sits, so declaration order still
governs the whole list — mix group references and plain class-strings
freely:

```{code-block} php
#[Get('/orders/export')]
#[Middleware(RateLimitMiddleware::class)]
#[Middleware('@admin')]
public function export(): array { /* ... */ }
```

That runs `RateLimitMiddleware`, then the `admin` group's two members, then
the controller.

Group membership alone never makes a middleware run anywhere — a group
only runs where a route or controller references it. Referencing a group
no class declares fails when the application starts, naming the group and
the route that referenced it, rather than at the moment someone hits that
endpoint.

`kinetis routes:list` prints each route's group references already
expanded into the classes that actually run, annotated with the group they
came from — see {doc}`cli`.

## Registering a value the controller reads later

`RequestScope` registers itself on itself, so a middleware can
constructor-inject the exact scope the current request is using — not a
disconnected new one — and write something onto it for a controller to
read afterward:

```{code-block} php
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
        private CurrentUserResolver $users,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->users->resolve($request);

        if ($user === null) {
            return new \Nyholm\Psr7\Response(401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthenticated.']));
        }

        $this->scope->instance(CurrentUserInterface::class, $user);

        return $handler->handle($request);
    }
}
```

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\CurrentUserInterface;

final readonly class OrderController
{
    public function __construct(
        private CurrentUserInterface $user,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return ['userId' => $this->user->id()];
    }
}
```

`CurrentUserInterface` (`Kinetis\Http\CurrentUserInterface`) is one method
— `id(): string|int` — deliberately minimal so any auth strategy can
implement it. Nothing implements or registers it by default: a controller
constructor-injecting it without an auth middleware having run first gets
a plain `NotFoundException`, not a null to check.

The controller above can constructor-inject what the middleware
registered because a controller is resolved *after* every middleware in
front of it has run. A middleware cannot do the same for something an
earlier middleware registers: **all of a route's middleware are
constructed before the first one runs**, so at construction time none of
them has executed yet. A middleware that depends on an earlier one's
work resolves it inside `process()` instead, from an injected
`RequestScope`:

```{code-block} php
final readonly class RequireVerifiedEmailMiddleware implements MiddlewareInterface
{
    // Injecting CurrentUserInterface here would fail: the auth
    // middleware in front of this one has not run yet.
    public function __construct(private RequestScope $scope) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->scope->get(CurrentUserInterface::class);

        // ...
    }
}
```

## Built in: `ExceptionHandlerMiddleware`

Registered automatically on every `Kernel`, immediately inside
`SecurityHeadersMiddleware` — not something you opt into:

```{code-block} text
Kernel's global pipeline, outermost to innermost:
  SecurityHeadersMiddleware    ← always first, unconditionally
  ExceptionHandlerMiddleware   ← always second, unconditionally
  MaxBodySizeMiddleware        ← always third, unconditionally
  ...your own $app->middleware() registrations, in order...
  (routing, then a matched route's own middleware, then the controller)
```

Without it, an uncaught exception from anywhere in the pipeline — a
controller, a route middleware, application code in general — would
propagate all the way out of `Kernel::handle()` with nothing converting
it into a response. For a persistent worker, that's a materially worse
failure mode than one request degrading to a `500`, which is why it's
always on rather than something you opt into.

```{code-block} json
:caption: What a controller throwing an uncaught exception produces
{
    "error": "Internal server error."
}
```

In development (`APP_ENV=development`), the same `500` carries the
exception's class, message, and location, so a mistake is diagnosable
straight from the response:

```{code-block} json
:caption: The same failure, in development
{
    "error": "Internal server error.",
    "exception": "RuntimeException",
    "message": "boom",
    "location": "/app/src/Http/OrderController.php:24"
}
```

```{note}
Either way, the exception is also logged through whatever
`Psr\Log\LoggerInterface` is bound — in development that's an
`error_log()`-backed logger by default, so the trail exists even where
the response body isn't visible. See {doc}`logging`.
```

Middleware registration is a flat class-string list at both levels — a
middleware needing a threshold or a config value takes it through the
container via constructor injection, like anything else.

## Built in: `SecurityHeadersMiddleware`

Registered unconditionally as the **outermost** global middleware —
outside `ExceptionHandlerMiddleware`, so its headers reach the `500`
that handler produces as well as every ordinary response. It cannot
throw at request time: configuration is read once at construction, so
`process()` does nothing but set headers.

Three headers are sent by default, with no configuration at all:

```{code-block} text
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
```

Nothing legitimate depends on content sniffing, on being framed, or on
leaking a full referrer to another origin, so these cost a working
application nothing and protect one that never thought about them.
`X-Frame-Options` and `Referrer-Policy` take any value, or `off` to
send nothing; `X-Content-Type-Options` is not configurable, because
there is no reason to turn sniffing back on.

```{code-block} text
:caption: .env
SECURITY_FRAME_OPTIONS=SAMEORIGIN
SECURITY_REFERRER_POLICY=no-referrer
```

A Content-Security-Policy, a Permissions-Policy, HSTS, and the three
cross-origin policies are sent **only when you configure them**. Each
breaks a working application when it is wrong — a policy that omits a
real dependency blocks it, and HSTS on the wrong host is not quickly
reversible — so a guessed default would do more harm than sending
nothing:

```{code-block} text
:caption: .env
SECURITY_CSP=default-src 'self'; object-src 'none'; frame-ancestors 'none'
SECURITY_PERMISSIONS_POLICY=geolocation=(), microphone=(), camera=()
SECURITY_HSTS_MAX_AGE=31536000
SECURITY_HSTS_INCLUDE_SUBDOMAINS=true
SECURITY_HSTS_PRELOAD=false
SECURITY_COOP=same-origin-allow-popups
SECURITY_CORP=same-origin
SECURITY_COEP=require-corp
```

HSTS is sent whenever a max-age is configured, without checking the
request's own scheme: a browser is required to ignore it when it did
not arrive over a secure transport, and a scheme check would suppress
it behind a proxy that terminates TLS — where it is exactly what you
want.

The three cross-origin policies each sever something the web allows by
default, which is the point of them and the reason to reach for one
deliberately:

`SECURITY_COOP` cuts the `window.opener` link between your pages and
the windows around them. `same-origin-allow-popups` keeps popups your
own pages open — which is how an OAuth or payment popup reports back —
while `same-origin` also severs the link when one of your pages *is*
the popup, so choose it only if you are not the one being opened.

`SECURITY_CORP` set to `same-origin` stops other origins embedding your
responses, including images and fonts they embed today. It does not
apply to a CORS request, so an API consumed through `CorsMiddleware` is
unaffected either way.

`SECURITY_COEP` set to `require-corp` demands that every cross-origin
subresource opt in, and blocks each one that has not. It is what
`crossOriginIsolated` needs, and the most disruptive of the three —
introduce it last, after the other two are in place.

```{note}
Your policy does not have to accommodate the Swagger UI page Kinetis
serves at `/openapi`. That page loads `swagger-ui-dist` from a CDN, which a
`script-src` of `'self'` would block, so it sends its own policy —
narrower than a typical application-wide one, with a per-response nonce
for its inline script and `connect-src 'self'` so it can fetch its own
document and nothing else. Because a header already on the response is
never replaced, yours still governs every other route.
```

A header the response already carries is never replaced, so one route
can set its own policy and keep it:

```{code-block} php
#[Get('/embed/widget')]
public function widget(): ResponseInterface
{
    // Kept as-is; the global DENY does not overwrite it.
    return HtmlResponse::create($markup)
        ->withHeader('X-Frame-Options', 'SAMEORIGIN');
}
```

## Built in: `MaxBodySizeMiddleware`

Registered unconditionally, right after `ExceptionHandlerMiddleware` —
also not something you opt into. Without it, nothing checks how large a
request body is before `#[Body]` reads the whole thing into memory and
`json_decode()`s it.

```{code-block} text
:caption: .env
MAX_BODY_SIZE=2097152
```

Bytes, not a `"2M"`-style string. Defaults to `2097152` (2 MiB) when
unset.

```{code-block} json
:caption: What an oversized request produces (413)
{
    "error": "Request body exceeds the maximum allowed size of 2097152 bytes."
}
```

Two checks, not one. A declared `Content-Length` over the limit is
rejected immediately, before the body is touched at all. Underneath
that, the body itself is capped as it's actually read — so a request
with no `Content-Length` header, or one that under-reports its real
size, is still rejected once a `#[Body]` route actually reads past the
limit. A route that never reads the body (a `GET`, or one using only
`#[Query]`/path parameters) is unaffected either way, since nothing tries
to read past the limit in the first place.

Only the raw JSON `#[Body]` path is capped this way — a
`multipart/form-data` or `application/x-www-form-urlencoded` body is
parsed before Kinetis code reads it, bounded by PHP's own
`upload_max_filesize`/`post_max_size` instead.

## Built in: `CorsMiddleware`

`Kinetis\Http\Middleware\CorsMiddleware` — Cross-Origin Resource Sharing.
**Global only** — it's the one built-in middleware that can't be used as
route middleware at all:

```{code-block} php
use Kinetis\Http\Middleware\CorsMiddleware;

$app->middleware(CorsMiddleware::class);
```

A CORS preflight (`OPTIONS` with `Access-Control-Request-Method`) to a
path with no registered `OPTIONS` route would never reach route
middleware at all, since that only runs after a route has already
matched successfully. Registering `CorsMiddleware` globally is what lets
it see and answer the preflight before routing even runs.

```{code-block} php
new CorsMiddleware(
    allowedOrigins: ['https://app.example.com'],
    allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    exposedHeaders: [],
    allowCredentials: false,
    maxAge: 86400,
);
```

Defaults to `allowedOrigins: []` — deny by default. Nothing is
cross-origin-accessible until you explicitly list allowed origins (or
opt into `allowedOrigins: ['*']` yourself, with `allowCredentials: false`).
A request with no `Origin` header, or an `Origin` not on the allow list,
passes through completely untouched — no CORS headers added, no error
status returned. That's deliberate: it's the browser's own same-origin
policy that blocks a disallowed cross-origin response once it doesn't see
an `Access-Control-Allow-Origin` header naming it; nothing server-side
needs to reject the request itself.

`allowedHeaders: ['*']` reflects whatever the preflight actually requested
(`Access-Control-Request-Headers`) instead of checking against a fixed
list — maintaining an exhaustive static allow-list is brittle against a
client sending one custom header more than expected.

```{warning}
**Wildcard origins and credentials never combine, per spec.** Browsers
reject `Access-Control-Allow-Origin: *` outright when credentials are
involved, so `CorsMiddleware` refuses to construct at all with
`allowedOrigins: ['*']` and `allowCredentials: true` together — that
combination has no safe fallback to silently apply, it's a
misconfiguration to catch before it ships. Use a real allow-list (or
`allowedOriginPatterns`) instead if you need credentialed cross-origin
requests; with one configured, a credentialed response always echoes
back the specific requesting origin rather than a static value, which
also adds `Vary: Origin`, since the response then varies by request
origin.
```

### Matching a pattern of origins, not just a fixed list

`allowedOriginPatterns` checks the `Origin` header against full, delimited
PCRE patterns when it matches none of `allowedOrigins` exactly — for "any
subdomain of `example.com`", not expressible as a fixed list:

```{code-block} php
new CorsMiddleware(
    allowedOrigins: [],
    allowedOriginPatterns: ['#^https://[a-z0-9-]+\.example\.com$#'],
);
```

A pattern has to match the `Origin` in full. A partial match is not
enough, so an unanchored `example\.com` does not allow
`https://evil-example.com.attacker.net` — the recurring class of CORS
misconfiguration this parameter would otherwise invite. Anchors are
still worth writing for clarity, but leaving them out cannot widen what
a pattern allows.

The whole-`Origin` rule is what enforces this, rather than a check that
the pattern carries `^` and `$`. Such a check cannot be trusted: an
alternation like `#^https://good\.com$|evil\.com$#` carries both
anchors and is still unanchored on its second branch, so it would pass
inspection while allowing any origin ending in `evil.com`.

```{danger}
**Escaping literal dots is still yours to get right.** `.+example\.com`
matches `https://evilexample.com` in full, and nothing generic can tell
that from an intended pattern. Write `\.` for a literal dot.
```

Patterns are compiled when the middleware is constructed, and one that
cannot compile raises `InvalidArgumentException` there — it would
otherwise match nothing and quietly deny every origin it was written to
allow.

For anything beyond pattern matching against the `Origin` header itself —
a per-tenant allow-list, for example — write your own middleware using
`CorsMiddleware` as a starting point.

## Built in: `RateLimitMiddleware`

`Kinetis\Http\Middleware\RateLimitMiddleware` — a fixed-window request
counter backed by `Psr\SimpleCache\CacheInterface`. It needs a real
cache: configure Redis (`REDIS_URL` or `REDIS_HOST` — see
{doc}`persistence`) so `AppScope::boot()` binds `RedisSimpleCache`, or
pass any other real PSR-16 implementation. Construction over
`NullSimpleCache` — the default binding when no Redis is configured —
throws, since a counter that never stores anything enforces no limit at
all while still emitting healthy-looking `X-RateLimit-*` headers. Not
registered by default — opt in as global or route middleware, whichever
fits:

```{code-block} php
use Kinetis\Http\Middleware\RateLimitMiddleware;

$app->middleware(RateLimitMiddleware::class); // every request
```

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\RateLimitMiddleware;

final readonly class LoginController
{
    #[Get('/login')]
    #[Middleware(RateLimitMiddleware::class)] // just this route
    public function attempt(): array { /* ... */ }
}
```

Either way, `CacheInterface` autowires from whatever `AppScope::boot()`
registered — no extra wiring needed. It's safe as global middleware
specifically because it holds no per-request state as instance properties,
the same criterion [above](#global-middleware-every-request-including-ones-that-never-match-a-route)
already establishes for any global middleware.

Defaults to 60 attempts per 60-second window, keyed by client IP
(`REMOTE_ADDR`), sha256-hashed before use — not for concealment, but because
PSR-16 forbids `{}()/\@:` in a key, and a bare IPv6 address is full of
colons. A request past the limit gets:

```{code-block} json
:caption: 429, once the limit is reached
{
    "error": "Too many requests."
}
```

with `Retry-After` (seconds until the current window resets) and
`X-RateLimit-Limit`/`X-RateLimit-Remaining` headers — the latter two are
also set on every successful response, not just the rejection, so a client
can see its remaining quota before actually hitting it.

### Behind a reverse proxy or load balancer

`REMOTE_ADDR` is the address of whatever connected directly — behind a
real reverse proxy or load balancer, that's the proxy's own address on
every request, not the real client's, so every distinct client collapses
into one shared bucket. `trustedProxies` opts into reading
`X-Forwarded-For` instead, but only for a request that actually came
through one of the given CIDR ranges — never unconditionally, since a
client can set that header to anything it likes:

```{code-block} php
new RateLimitMiddleware($cache, trustedProxies: ['10.0.0.0/8']);
```

```{code-block} text
:caption: .env
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12
```

Read this yourself in your own bootstrap code and pass it through — the
middleware doesn't read `Config` itself, the same convention
`allowedOrigins` on `CorsMiddleware` already follows:

```{code-block} php
$app->bind(RateLimitMiddleware::class, function ($c) {
    $trustedProxies = $c->get(Config::class)->string('TRUSTED_PROXIES', '');

    return new RateLimitMiddleware(
        $c->get(CacheInterface::class),
        // Trimmed, so a space after a comma in .env is not read as
        // part of the next range.
        trustedProxies: $trustedProxies === '' ? [] : array_map(trim(...), explode(',', $trustedProxies)),
    );
});
```

When a request comes through more than one trusted hop, the
`X-Forwarded-For` chain is walked from the end backward, skipping every
entry that's itself a trusted proxy — the first untrusted entry is the
real client.

Each range is parsed when the middleware is constructed. One that cannot
be — a prefix length outside 0-32 for IPv4 or 0-128 for IPv6, or an
address that isn't one — raises
`Exception\InvalidRateLimitConfigException` there rather than on the
first request to reach it, since the list decides who is allowed to set
`X-Forwarded-For`. `maxAttempts` and `windowSeconds` are checked the same
way and must both be at least 1: a window of zero has no length to divide
the clock into, and a negative one stores the counter already expired, so
nothing is ever counted while the `X-RateLimit-*` headers keep looking
healthy.

### A different limit for a different route

`#[Middleware(...)]` only ever carries a class-string, no arguments (see
above) — a login endpoint wanting 5/minute while the rest of the API gets
60/minute is a thin subclass fixing its own constructor defaults:

```{code-block} php
use Kinetis\Http\Middleware\RateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class LoginRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(CacheInterface $cache)
    {
        parent::__construct($cache, maxAttempts: 5, windowSeconds: 60);
    }
}
```

Overriding the global default instead — every route, one new limit — is a
single `AppScope::bind()` closure rather than a subclass:

```{code-block} php
$app->bind(RateLimitMiddleware::class, fn ($c) => new RateLimitMiddleware(
    $c->get(Psr\SimpleCache\CacheInterface::class),
    maxAttempts: 100,
    windowSeconds: 60,
));
```

```{note}
The `get()`-then-`set()` pair behind this isn't atomic — under concurrent
requests hitting the same window, two requests can both read the same
count and both write the same incremented value, silently losing an
increment. This is a real, accepted limitation of building against the
plain PSR-16 interface rather than a backend-specific atomic `INCR`, which
would make this Redis-only and defeat the point of depending on
`CacheInterface` rather than `Amp\Redis\RedisClient` directly. For most
rate-limiting use cases this is a rounding error, not a correctness bug —
an exact limiter needs a backend offering an atomic increment primitive,
which PSR-16 doesn't expose.
```

### Keying by the authenticated user instead of IP

`Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware` extends
`RateLimitMiddleware`: it keys by `CurrentUserInterface::id()` when one has
already been resolved onto the current request (see
["Registering a value the controller reads later"](#registering-a-value-the-controller-reads-later)
above), falling back to the same IP-based identifier otherwise.

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware;

final readonly class OrderController
{
    #[Get('/orders')]
    #[Middleware(AuthMiddleware::class)]                     // resolves CurrentUserInterface first
    #[Middleware(AuthenticatedRateLimitMiddleware::class)]    // then keys by it
    public function index(): array { /* ... */ }
}
```

Ordering matters — the middleware that resolves `CurrentUserInterface`
must run first, so it's already registered on the scope by the time this
one reads it.

```{warning}
Route middleware only — never register this globally, and never bind
`AuthenticatedRateLimitMiddleware::class` directly on `AppScope` with a
factory that also resolves `RequestScope`. `AppScope::resolve()` falls
back to autowiring any real class it has no explicit binding for (unlike
`AppScope::has()`, which is explicit-only), so a factory calling
`$c->get(RequestScope::class)` where `$c` is `AppScope` would silently
construct a brand-new, disconnected `RequestScope` instead of reaching the
real per-request one. It's always safe as route middleware, resolved
fresh per request the normal way — no binding needed at all, the same as
any other constructor with only class-typed parameters.
```

Also not atomic, for the same reason as the base class — and also
deliberately not `final`, so a stricter per-route limit still works via
the same subclass pattern.

## See also

- {doc}`container` — `AppScope`/`RequestScope`, and the "singleton via the
  container" pattern global middleware relies on.
- {doc}`logging` — registering your own logger, and the other two places
  Kinetis logs on its own.
- {doc}`core-concepts` — the request lifecycle both pipelines sit inside.
- {doc}`auth` — a ready-made bearer-token implementation of the
  `AuthMiddleware` pattern shown above.
- {doc}`caching` — how route middleware, and `#[AsGlobalMiddleware]`-discovered
  classes, are stored in the production cache.
- {doc}`cli` — restricting namespace-based discovery for a large application,
  the same mechanism `MIDDLEWARE_DISCOVERY_PATHS` follows.
