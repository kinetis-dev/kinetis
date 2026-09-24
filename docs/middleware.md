# Middleware

Kinetis middleware is plain [PSR-15](https://www.php-fig.org/psr/psr-15/):
a class implementing `Psr\Http\Server\MiddlewareInterface`. Existing PSR-15
middleware works unmodified.

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

`process()` either calls `$handler->handle($request)` and works with the
response it gets back, as above, or returns its own response without
calling it, which ends the request there.

This page covers choosing and declaring middleware and configuring the
built-in middleware. {doc}`appendix-middleware` holds the pipeline
mechanics and the built-in middleware contracts.

## Global or route middleware

| | Global middleware | Route middleware |
|---|---|---|
| Runs for | every request, including `404`s, `405`s and CORS preflights | the routes that reference it |
| Declared with | `#[AsGlobalMiddleware]`, or `$app->middleware()` in `bootstrap.php` | `#[Middleware]` on a controller or method |
| Built | once per worker, from `AppScope` | per request, from that request's `RequestScope` |
| May depend on | worker-lifetime services | anything, `RequestScope` included |

Use global middleware for what every request needs, including requests
that match no route: request IDs, logging, CORS, an API-wide rate limit.
Use route middleware for what particular routes need: authentication,
authorization, a stricter limit on a login route.

```{warning}
A global middleware is built once and serves every request its worker
handles. Keep no request data in its properties, and do not
constructor-inject `RequestScope` or a service built per request: global
middleware exists before any request scope does, and resolving
`RequestScope` from `AppScope` throws. Anything that needs the current
request's scope is route middleware.
```

## Global middleware

(discoverable-global-middleware)=
### Discoverable global middleware

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

Any class under one of your project's production `autoload.psr-4` roots
that carries `#[AsGlobalMiddleware]` joins the global pipeline, and so
does a class an installed package offers through its `extra.kinetis`
scan roots (see {doc}`cli`). Nothing else registers it.
`MIDDLEWARE_DISCOVERY_PATHS` restricts the scan in a large application,
as {doc}`cli` describes for routes.

When discovered middleware must run in a particular order, give each a
`priority` from `0` to `100`, such as `#[AsGlobalMiddleware(priority: 90)]`.
The default is `50`; higher runs further out, and equal priorities run in
class-name order.

### Middleware that needs constructor arguments

An attribute cannot supply constructor arguments. A global middleware
that needs them — `CorsMiddleware`'s allowed origins, for example — is
bound and registered on `AppScope` in your project's `bootstrap.php`, which
runs once at startup, before `AppScope::boot()` locks the container.
[Registering global middleware](bootstrapping.md#registering-global-middleware)
shows the file.

`$app->middleware()` belongs in `bootstrap.php` and nowhere else. Called
after boot — from a controller, a route middleware, or any other code
running during a request — it throws `ContainerException` instead of
registering anything.

Explicitly registered middleware runs outside every discovered class, in
registration order. A class that is both registered and discovered runs
once, at its registered position. A registered `CorsMiddleware` is the
exception: it moves outside error handling, as [CORS](#cors) explains.

(route-middleware)=
## Route middleware

```{code-block} php
use Kinetis\Http\Attributes\{Get, Middleware, Post};

#[Middleware(AuthMiddleware::class)]
final readonly class OrderController
{
    #[Get('/orders')]
    public function index(): array { /* ... */ }

    #[Post('/orders/{id}/refund')]
    #[Middleware(RequireAdminMiddleware::class)]
    public function refund(int $id): array { /* ... */ }
}
```

`#[Middleware]` is repeatable on a controller class and on its methods.
Class-level middleware applies to every route of the controller and runs
first; method-level middleware runs next, closer to the controller; each
level runs in declaration order. `POST /orders/{id}/refund` runs
`AuthMiddleware`, then `RequireAdminMiddleware`, then the controller.

Route middleware runs inside the global pipeline, only once a route has
matched. It is built per request from that request's `RequestScope`, so
it can depend on per-request services and on the scope itself.

### Registering a value the controller reads later

A route middleware can constructor-inject the current `RequestScope` and
register a value on it for the controller:

```{code-block} php
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
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
            return ErrorResponse::create(401, 'Unauthenticated.');
        }

        $this->scope->instance(CurrentUserInterface::class, $user);

        return $handler->handle($request);
    }
}
```

```{code-block} php
use Kinetis\Http\Attributes\{Get, Middleware};
use Kinetis\Http\CurrentUserInterface;

#[Middleware(AuthMiddleware::class)]
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

`CurrentUserResolver` stands for your own lookup. `CurrentUserInterface`
has one method, `id(): string|int`, and nothing registers it by default:
a controller that asks for it on a route without an authentication
middleware fails instead of receiving `null`. {doc}`auth` ships a
bearer-token implementation of this pattern.

A controller can inject what a middleware registered because the
controller is built after the middleware runs. Middleware cannot inject
each other's values this way: all of a route's middleware are built
before the first one runs, so a middleware that needs an earlier one's
value reads it from an injected `RequestScope` inside `process()`.

## Middleware groups

When several routes need the same middleware in the same order, name the
stack once, on the middleware classes:

```{code-block} php
use Kinetis\Http\Attributes\AsMiddlewareGroup;

#[AsMiddlewareGroup('admin', priority: 90)]
final class AuthMiddleware implements MiddlewareInterface { /* ... */ }

#[AsMiddlewareGroup('admin')]
final class RequireAdminMiddleware implements MiddlewareInterface { /* ... */ }
```

A route or controller references the group with an `@` prefix:

```{code-block} php
#[Post('/orders/{id}/refund')]
#[Middleware('@admin')]
public function refund(int $id): array { /* ... */ }
```

Members run by priority, highest first — `AuthMiddleware` at `90`, then
`RequireAdminMiddleware` at the default `50` — with equal priorities in
class-name order. The attribute is repeatable, so a class can belong to
several groups at a different priority in each. A group reference expands
where it is declared, so it mixes freely with plain class references.

Groups are discovered by the same scan as `#[AsGlobalMiddleware]`. A group
runs only where a route references it, and a reference to a group no class
declares stops the application at startup. `kinetis routes:list` prints
each route's groups expanded into the classes that run (see {doc}`cli`).

A member that describes its own OpenAPI security documents every route
referencing the group, and a thin subclass carrying
`#[AsMiddlewareGroup]` inherits that description from the middleware it
extends — which is how {doc}`auth` and {doc}`auth-jwt` reach a grouped
endpoint. See {ref}`openapi-security`.

### Groups for framework and package endpoints

Endpoints that Kinetis and its packages ship reference named groups, so
middleware can be added to them alone:

- **`/openapi.json` and `/openapi`**: mark a middleware
  `#[AsOpenApiMiddleware]`, or register it with
  `$app->openApiMiddleware()` in `bootstrap.php`.
- **`/mcp`**: join the `mcp` group with `#[AsMiddlewareGroup('mcp')]`, and
  register the caller as `CurrentUserInterface`. `kinetis/mcp` validates
  `Origin` before your middleware and closes the endpoint when no
  `CurrentUserInterface` was registered; see {doc}`mcp`'s "Securing the
  HTTP transport".
- **`POST /broadcasting/auth`**: join the `broadcasting` group; see
  {doc}`broadcasting`'s "Securing the endpoint".

Global middleware still runs first for these endpoints. An example `mcp`
member is in [Endpoint groups](appendix-middleware.md#endpoint-groups).

## Built-in middleware

Every request passes through this pipeline:

```{code-block} text
SecurityHeadersMiddleware          always
CorsMiddleware                     when registered with $app->middleware()
ExceptionHandlerMiddleware         always
RequestBodyMiddleware              always
other $app->middleware() entries   in registration order
#[AsGlobalMiddleware] classes      by priority
  routing
    route middleware               class-level, then method-level
      controller
```

`CorsMiddleware`, `RateLimitMiddleware` and
`AuthenticatedRateLimitMiddleware` are opt-in, each configured with your
own policy.

## Error responses

`ExceptionHandlerMiddleware` turns an uncaught exception from routing, a
route middleware or a controller into a response, so one failure costs
one request a `500` instead of escaping the worker's request handling:

```{code-block} json
:caption: A controller throwing an uncaught exception
{
    "error": "Internal server error."
}
```

With `APP_ENV=development`, the same `500` also carries the exception's
class, message and location:

```{code-block} json
:caption: The same failure in development
{
    "error": "Internal server error.",
    "exception": "RuntimeException",
    "message": "boom",
    "location": "/app/src/Http/OrderController.php:24"
}
```

Either way the exception is logged through the bound
`Psr\Log\LoggerInterface`; see {doc}`logging`.

### Mapping your own exceptions to a status

An exception declares its own status by implementing
`Kinetis\Http\Exception\HttpStatusExceptionInterface`:

```{code-block} php
use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;

final class OutOfStockException extends RuntimeException implements HttpStatusExceptionInterface
{
    public function httpStatus(): int
    {
        return 409;
    }
}
```

The response carries that status and `{"error": "<message>"}`, and is not
logged as a failure. The exception message goes to the client, so write it
for the client. `httpStatus()` must return a value from 400 to 599 and must
not throw; an implementation that breaks either rule is logged and answered
with the generic `500`.

### Rendering validation failures

A failed validation reaches `ExceptionHandlerMiddleware` as a
`ValidationException` and is rendered as the `422` problem document shown
in {doc}`routing-validation`. To answer differently — a `303` back to an
HTML form, a re-rendered page — implement
`Kinetis\Http\ValidationExceptionRendererInterface` and bind it in
`bootstrap.php`, as [Binding an application
service](bootstrapping.md#binding-an-application-service) shows:

```{code-block} php
:caption: bootstrap.php

$app->bind(ValidationExceptionRendererInterface::class, FormRedirectRenderer::class);
```

`render(ValidationException $exception, ServerRequestInterface $request):
ResponseInterface` decides the whole response, any status included. It
reads `$exception->violations` — each a `Kinetis\Validation\Violation`
with `path`, `code`, `message` and `parameters` — or
`$exception->grouped()`, which maps dotted field names to messages for an
HTML form.

```{warning}
The renderer is built once from `AppScope` and shared by every request.
Give it constructor dependencies only, and keep neither the request nor
the exception after `render()` returns. The request scope is already
disposed when it runs, so a request-scoped service such as the session is
gone: to store flash errors before redirecting, catch
`ValidationException` in a route middleware instead.
```

## Security headers

`SecurityHeadersMiddleware` runs outermost, so its headers reach every
response, the `500` included. With no configuration it sends:

```{code-block} text
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
```

`SECURITY_FRAME_OPTIONS` and `SECURITY_REFERRER_POLICY` replace the last
two, or send nothing when set to `off`. `X-Content-Type-Options` is not
configurable.

The remaining policies are sent only when you configure them:

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

Each of these breaks a working application when it is wrong, so none has
a default:

- **CSP and Permissions-Policy** block every source or feature they do
  not list. List everything the application loads.
- **HSTS** is sent whenever `SECURITY_HSTS_MAX_AGE` is set, including
  behind a proxy that terminates TLS, and browsers keep it for that long.
  `includeSubDomains` is added unless `SECURITY_HSTS_INCLUDE_SUBDOMAINS=false`,
  so set that to `false` unless every subdomain serves HTTPS.
  `SECURITY_HSTS_MAX_AGE=0` tells browsers to drop a cached policy.
- **COOP** `same-origin-allow-popups` keeps OAuth and payment popups
  working; `same-origin` also cuts the link when one of your pages is the
  popup.
- **CORP** `same-origin` stops other origins embedding your responses,
  images and fonts included. CORS requests are unaffected.
- **COEP** `require-corp` blocks every cross-origin subresource that has
  not opted in. Introduce it last.

A header the response already carries is never replaced, so one route
can set its own value:

```{code-block} php
#[Get('/embed/widget')]
public function widget(): ResponseInterface
{
    return HtmlResponse::create($markup)
        ->withHeader('X-Frame-Options', 'SAMEORIGIN');
}
```

The Swagger UI page at `/openapi` sends its own Content-Security-Policy,
so your policy does not need to allow its CDN. The appendix's
{doc}`security headers section <appendix-middleware>` covers HSTS
withdrawal and each cross-origin policy.

## Request body limits

`RequestBodyMiddleware` reads each request body once, into memory, before
routing, and parses form bodies. `MAX_BODY_SIZE` caps the body in bytes:

```{code-block} text
:caption: .env
MAX_BODY_SIZE=2097152
```

The default is `2097152` (2 MiB). A larger body, or a form past one of
the `FormLimits` ceilings, is refused before routing:

```{code-block} json
:caption: 413
{
    "error": "Request body exceeds the maximum allowed size of 2097152 bytes."
}
```

For a `multipart/form-data` request, "the body" is the complete encoded
request: every field and file, plus the multipart framing between them,
including the boundary the client chose. It is not just the uploaded
bytes. A per-file `#[FileSize]` ceiling (see [File
uploads](routing-validation.md#file-uploads)) must leave headroom under
`MAX_BODY_SIZE` for that framing.

A form body that cannot be parsed is a `400` with the fixed message
`The request body could not be parsed.`

```{warning}
Every request in progress holds its body in memory, and parsing a form
holds more than the body at its peak. Choose `MAX_BODY_SIZE` together
with PHP's `memory_limit` and the number of requests a worker serves at
once.
```

Some runtimes enforce their own ceiling before PHP receives the body —
RoadRunner's `http.max_request_size`, Lambda's payload limit — and the
SAPI adapters require `enable_post_data_reading=0`. See
{doc}`runtime-adapters`.

The body stays readable after parsing: `(string) $request->getBody()`
returns all of it, even after another middleware has read it.

## CORS

`CorsMiddleware` must be global middleware: a preflight `OPTIONS` request
usually matches no route, and route middleware runs only after a match.
Bind it with your configuration and register it in `bootstrap.php`, as
[Registering global middleware](bootstrapping.md#registering-global-middleware)
shows. Wherever you register it, it runs directly inside
`SecurityHeadersMiddleware`, so an allowed origin can also read the
framework's error responses and the `400` or `413` for a rejected
request body. Its constructor, with every default:

```{code-block} php
new CorsMiddleware(
    allowedOrigins: [],
    allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    exposedHeaders: [],
    allowCredentials: false,
    maxAge: 86400,
    allowedOriginPatterns: [],
);
```

- **Nothing is allowed by default.** A request without an `Origin`, or
  from an origin not allowed, passes through without CORS headers, and
  the browser withholds the response from the calling page.
- **`allowedOrigins: ['*']` cannot be combined with
  `allowCredentials: true`**; construction fails. For credentialed
  requests list the origins, and the response names the requesting origin.
- **`allowedHeaders: ['*']`** allows whatever headers the preflight asks
  for.
- **`allowedOriginPatterns`** matches origins against PCRE patterns, such
  as `['#^https://[a-z0-9-]+\.example\.com$#']` for every subdomain. A
  pattern must match the whole `Origin`, and one that does not compile
  fails construction.
- **`allowedMethods`, `allowedHeaders` and `exposedHeaders` must be valid
  header values**; one containing CR, LF or another character a header
  value cannot carry fails construction.

```{danger}
**Escape literal dots in origin patterns.** `.+example\.com` matches
`https://evilexample.com` in full. Write `\.` for every dot.
```

Every CORS response carries the `Vary` tokens a shared cache needs; see
{ref}`Response caching and Vary <cors-caching>`.

## Rate limiting

`RateLimitMiddleware` counts requests per client in fixed windows. It
needs a cache that counts atomically — `RedisSimpleCache`, bound when
`REDIS_URL` or `REDIS_HOST` is configured (see {doc}`redis`) — and throws
at construction otherwise, rather than running without enforcing a limit.

A policy is a subclass that fixes a **policy ID** and its limits. The ID
alone identifies the counter: two policies share a budget only when they
share an ID.

```{code-block} php
use Kinetis\Http\Middleware\RateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class LoginRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(CacheInterface $cache)
    {
        parent::__construct($cache, 'login', maxAttempts: 5, windowSeconds: 60);
    }
}
```

```{code-block} php
use Kinetis\Http\Attributes\{Middleware, Post};

final readonly class LoginController
{
    #[Post('/login')]
    #[Middleware(LoginRateLimitMiddleware::class)]
    public function attempt(): array { /* ... */ }
}
```

The same subclass works as global middleware, registered with
`$app->middleware()` in `bootstrap.php`. Limits default to 60 requests per
60 seconds, keyed by the connecting IP address. A request over the limit
receives:

```{code-block} json
:caption: 429
{
    "error": "Too many requests."
}
```

with `Retry-After`, plus `X-RateLimit-Limit` and `X-RateLimit-Remaining`,
which also appear on every allowed response.

### Behind a reverse proxy or load balancer

```{warning}
Behind a proxy or load balancer, the connecting address is the proxy's,
so every client shares one counter until you pass `trustedProxies`. The
middleware then reads `X-Forwarded-For` only from requests that arrived
through one of those ranges; a client can set that header to anything.
```

The middleware does not read `TRUSTED_PROXIES` itself. A policy passes
the ranges through its constructor:

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Http\Middleware\RateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class ApiRateLimitMiddleware extends RateLimitMiddleware
{
    public function __construct(CacheInterface $cache, Config $config)
    {
        $ranges = array_map(trim(...), explode(',', $config->string('TRUSTED_PROXIES', '')));

        parent::__construct(
            $cache,
            'api',
            maxAttempts: 100,
            windowSeconds: 60,
            trustedProxies: array_values(array_filter($ranges, static fn (string $range): bool => $range !== '')),
        );
    }
}
```

A range that does not parse fails construction. How the forwarded chain is
walked is in [Forwarded client
identity](appendix-middleware.md#forwarded-client-identity).

### Composing policies

A generous global policy and a strict route policy can guard the same
request, each with its own ID and counter. The same policy registered
twice for one request — globally and on the route — counts once.

```{warning}
Changing a policy's ID starts new counters. During a rolling deploy, old
and new workers count the same clients under different keys until the
old workers are gone. Changing limits, trusted proxies or the subclass
leaves the counters alone.
```

### Keying by the authenticated user instead of IP

`AuthenticatedRateLimitMiddleware` keys by `CurrentUserInterface::id()`
when the request has a current user, and by IP address otherwise:

```{code-block} php
use Kinetis\Container\RequestScope;
use Kinetis\Http\Middleware\AuthenticatedRateLimitMiddleware;
use Psr\SimpleCache\CacheInterface;

final class OrderRateLimitMiddleware extends AuthenticatedRateLimitMiddleware
{
    public function __construct(CacheInterface $cache, RequestScope $scope)
    {
        parent::__construct($cache, 'orders', $scope, maxAttempts: 30, windowSeconds: 60);
    }
}
```

```{code-block} php
#[Get('/orders')]
#[Middleware(AuthMiddleware::class)]           // registers CurrentUserInterface
#[Middleware(OrderRateLimitMiddleware::class)] // then keys by it
public function index(): array { /* ... */ }
```

The middleware that registers `CurrentUserInterface` must run first.

```{warning}
Use this only as route middleware. It needs the request's `RequestScope`,
which global middleware cannot have, and an `AppScope` factory that
resolves `RequestScope` throws `DisconnectedRequestScopeException`. The
subclass above needs no binding.
```

## See also

- {doc}`bootstrapping` — where global middleware and application
  services are registered.
- {doc}`appendix-middleware` — pipeline mechanics and the built-in
  middleware contracts.
- {doc}`routing-validation` — the routes and validation this pipeline
  wraps.
- {doc}`auth` — ready-made authentication middleware.
- {doc}`container` — `AppScope`, `RequestScope` and worker-lifetime
  services.
- {doc}`logging` — the logger `ExceptionHandlerMiddleware` writes to.
