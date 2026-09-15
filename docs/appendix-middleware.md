# Appendix: Middleware

The pipeline mechanics and built-in middleware contracts behind
{doc}`middleware`. That guide covers choosing, declaring and configuring
middleware; this page is the reference it links to.

## Pipeline construction

### Global order

`Kernel` builds one global pipeline when it is constructed, outermost
first:

1. `SecurityHeadersMiddleware`
2. `ExceptionHandlerMiddleware`
3. `RequestBodyMiddleware`
4. every `AppScope::middleware()` registration, in registration order
5. every `#[AsGlobalMiddleware]` class not already registered explicitly

A class present in both lists runs once, at its explicit position:
discovery is for a class nobody registered by hand, not a second copy of
one that was.

The pipeline wraps `Kernel::handle()`'s entire body. Its innermost
handler creates the request's `RequestScope`, routes and dispatches (see
{doc}`core-concepts`), so a `404` or `405` from a failed match passes
through every global middleware, and a global middleware returning its
own response never reaches that handler.

Global middleware is resolved from `AppScope` when `Kernel` is
constructed, so one instance serves every request the worker handles —
the "singleton via the container" pattern {doc}`container` documents for
a plain service. Resolving `RequestScope` through `AppScope`, directly or
as a constructor dependency, throws `DisconnectedRequestScopeException`
rather than building a disconnected scope (see {doc}`container`'s
"Resolving `RequestScope` itself, from the wrong scope").

`AppScope::middleware()` is locked once `AppScope::boot()` runs, as
`bind()` and `instance()` are: the pipeline a request runs through is
fixed at startup. Registration is a flat class-string list at both
levels, so a middleware that needs a threshold or a config value takes it
through constructor injection.

### Discovery and priority

`#[AsGlobalMiddleware]`, `#[AsOpenApiMiddleware]` and
`#[AsMiddlewareGroup]` are found by one project-wide scan, restricted by
`MIDDLEWARE_DISCOVERY_PATHS` — comma-separated sub-paths relative to each
PSR-4 base directory — and compiled into the production cache alongside
the route table (see {doc}`caching`).

`priority` is an integer from `0` to `100`, defaulting to `50`; higher
runs more outer. The default sits at the midpoint so a class can be moved
either side of every unspecified default without knowing the range's
extremes. A value outside `0`-`100` throws `InvalidArgumentException`
when the attribute is constructed. Two classes sharing a priority are
ordered by fully-qualified class name, so the result never depends on
filesystem or scan order.

The priority scheme exists because nothing else establishes an order
between two independently discovered classes. `#[Middleware]` has no
priority: its attributes run in the order they are declared, group
references included.

Kinetis's own `CorsMiddleware`, `RateLimitMiddleware` and
`AuthenticatedRateLimitMiddleware` carry no discovery attribute. Each
needs application-specific constructor configuration no default could
supply.

### Route middleware construction

`Router::register()` reads `#[Middleware]` in the same reflection loop
that reads route attributes. Class-level references run outermost, then
method-level references, each level in declaration order. Route
middleware wraps only `Dispatcher::dispatch()` and is resolved from the
request's own `RequestScope`, the opposite source from global middleware.

A `@name` reference expands in place into the group's members, in the
group's priority order, so declaration order still governs the whole
list. Group references are validated when `Kernel` is constructed: a
reference to a group no class declares throws
`UnknownMiddlewareGroupException`, naming the group and the route, at
startup rather than on the first request to that route. Group membership
alone never runs a middleware.

All of a route's middleware are constructed before the first one runs.
A controller can constructor-inject what a middleware registered, because
the controller is resolved after the whole route pipeline has run in
front of it; a middleware cannot do the same for an earlier middleware's
value, since at construction time none of them has executed. It resolves
that value inside `process()` from an injected `RequestScope`:

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

`RequestScope` registers itself on itself, so the scope a route
middleware injects is the exact one the current request uses.

A middleware referenced through `#[Middleware]`, or discovered as global,
can also carry a `#[RoutePrefix]` that composes into the route's path;
see {ref}`A middleware can own a prefix too
<a-middleware-can-own-a-prefix-too>`. A group reference never contributes
a prefix.

### Endpoint groups

`/openapi.json` and `/openapi` are ordinary routes on the framework's
`DocumentationController`, which references a built-in `openapi` group.
Its members are the `#[AsOpenApiMiddleware]` classes plus every
`AppScope::openApiMiddleware()` registration, merged with the same
explicit-first rule as global middleware. The attribute takes the same
bounded `priority`. Global middleware already wraps these endpoints; the
group exists for middleware that must run for them alone.

`/mcp` is a route on `kinetis/mcp`'s controller, which references
`#[Middleware('@mcp')]`. A member of that group is route middleware, so
it can inject `RequestScope` and publish `CurrentUserInterface` for the
tool to read:

```{code-block} php
use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\AsMiddlewareGroup;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[AsMiddlewareGroup('mcp')]
final readonly class McpAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestScope $scope,
        private Config $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = 'Bearer ' . $this->config->required('MCP_TOKEN');

        if (!hash_equals($expected, $request->getHeaderLine('Authorization'))) {
            return ErrorResponse::create(401, 'Unauthenticated.');
        }

        $this->scope->instance(CurrentUserInterface::class, new class implements CurrentUserInterface {
            public function id(): string
            {
                return 'mcp-client';
            }
        });

        return $handler->handle($request);
    }
}
```

`kinetis/mcp` contributes two permanent members around yours:
`McpOriginMiddleware` at priority 100, so the spec-required `Origin`
validation runs first, and `McpIdentityGuardMiddleware` at priority 0,
which closes the endpoint when no `CurrentUserInterface` was registered.
{doc}`mcp`'s "Securing the HTTP transport" states that contract and the
`MCP_HTTP_PUBLIC` opt-in.

`POST /broadcasting/auth` follows the same shape with a `broadcasting`
group, which `kinetis/broadcasting`'s controller references. Joining it
with `#[AsMiddlewareGroup('broadcasting')]` — a thin subclass of either
auth package's middleware is enough — makes a channel authorizer taking
`CurrentUserInterface` reachable on that route alone. The package
contributes `BroadcastOriginMiddleware` at priority 100; see
{doc}`broadcasting`'s "Securing the endpoint".

For a request to any of these endpoints, global middleware runs first,
then the group, then the endpoint.

## `ExceptionHandlerMiddleware`

### Always on

Without this boundary an uncaught exception from anywhere inside the
pipeline would propagate out of `Kernel::handle()` with nothing turning
it into a response. For a persistent worker that is a worse failure than
one request degrading to a `500`, so the middleware is registered on
every `Kernel` rather than offered as an option.

### Logging is best-effort

An uncaught exception is logged through the bound
`Psr\Log\LoggerInterface` — in development an `error_log()`-backed logger
by default, so the trail exists where the response body is not visible.
A registered logger that itself throws cannot prevent the `500`, and an
exception message that is not valid UTF-8 still produces a valid JSON
body: observability never defeats this boundary.

### Declared statuses

An `HttpStatusExceptionInterface` is answered with its declared status
and its `getMessage()` in `{"error": ...}`, unlogged — a well-formed
declared HTTP error, not a framework fault. That holds for a declared
`4xx` and a declared `5xx` alike.

`httpStatus()` must return a value from 400 to 599 inclusive and must not
throw. Both rules are enforced: a status outside that range, or
`httpStatus()` throwing, is treated as a broken implementation — logged
with the original exception, plus the mapping failure as context where
there is one, and answered with the generic `500`, never a
`1xx`/`2xx`/`3xx` response and never an exception escaping this
middleware.

### Validation renderers

A `ValidationException` is recognized before any other exception and
handed to the `ValidationExceptionRendererInterface` the container
supplies:

```{code-block} php
interface ValidationExceptionRendererInterface
{
    public function render(
        ValidationException $exception,
        ServerRequestInterface $request,
    ): ResponseInterface;
}
```

The default, `ProblemDetailsValidationExceptionRenderer`, is an ordinary
constructor default rather than an `AppScope` registration, so binding
the interface before `AppScope::boot()` replaces it everywhere and
nothing is registered otherwise. No status range is imposed on a
renderer's response. `$exception->grouped()` projects the segmented
violation paths onto dotted keys with their messages; it is lossy, and
`$exception->violations` keeps the full structure.

A renderer that throws cannot defeat the boundary: the original
validation failure is logged with the rendering failure as context, and
the request gets the generic `500`. A validation failure rendered
normally is not logged — it reports a client mistake, not a framework
fault.

### A disposal failure never masks the real outcome

`Kernel` disposes each request's `RequestScope` after
`ExceptionHandlerMiddleware`'s boundary has already decided the outcome —
a route or controller `Throwable` already propagating, or a response
that has not left the process yet — with an explicit precedence for what
happens if that disposal itself fails (see {doc}`container` for why this
matters):

- **A route or controller failure was already in flight** — a declared
  `HttpStatusExceptionInterface`, or any other uncaught exception — its
  exact status, message, and identity are unaffected by a disposal
  failure on top of it. The disposal failure is logged separately,
  through `AppScope`'s own logger (the request's own scope is already
  disposed, so it cannot safely resolve one), and never appears as a
  second response.
- **The handler succeeded, and nothing has been returned to the client
  yet** — a disposal failure has nothing to compete with, so it becomes
  the ordinary generic `500`, logged exactly once, with the same
  development-versus-production detail rules as any other failure.
- **The response streams its own body** — its scope is disposed after the
  last byte instead of before `handle()` returns (see {doc}`container`),
  by which point the status, the headers and part of the body are on the
  wire. A disposal failure there is logged through `AppScope`'s own
  logger and goes no further; a failure raised by the emitter itself is
  the one that propagates.

`RequestScope::dispose()`'s own contract still holds underneath: every
registered dispose callback runs, even if an earlier one throws.

## `SecurityHeadersMiddleware`

### Construction

The middleware is the outermost global middleware, outside
`ExceptionHandlerMiddleware`, so its headers reach the `500` that handler
produces. It cannot throw at request time: configuration is read once at
construction, and `process()` only sets headers. A header the response
already carries is never replaced.

`X-Content-Type-Options`, `X-Frame-Options` and `Referrer-Policy` are
sent by default because nothing legitimate depends on content sniffing,
on being framed, or on leaking a full referrer to another origin.
`SECURITY_FRAME_OPTIONS` and `SECURITY_REFERRER_POLICY` take any value,
or `off` to send nothing. The opt-in policies have no default because a
guessed one would break applications it did not describe.

### HSTS

HSTS is sent whenever a max-age is configured, without checking the
request's scheme: a browser ignores it when it did not arrive over a
secure transport, and a scheme check would suppress it behind a proxy
that terminates TLS.

Leaving `SECURITY_HSTS_MAX_AGE` unset sends no header, so a policy a
browser already cached stays as it is. Setting it to `0` sends
`Strict-Transport-Security: max-age=0` — RFC 6797's withdrawal. A
withdrawal is sent on its own; `includeSubDomains` and `preload` qualify
only a positive max-age. `SECURITY_HSTS_INCLUDE_SUBDOMAINS` defaults to
`true` and `SECURITY_HSTS_PRELOAD` to `false`. A negative max-age throws
at construction.

### Cross-origin policies

The three cross-origin policies each sever something the web allows by
default.

`SECURITY_COOP` cuts the `window.opener` link between your pages and the
windows around them. `same-origin-allow-popups` keeps popups your own
pages open — which is how an OAuth or payment popup reports back — while
`same-origin` also severs the link when one of your pages *is* the popup.

`SECURITY_CORP` set to `same-origin` stops other origins embedding your
responses, including images and fonts they embed today. It does not
apply to a CORS request, so an API consumed through `CorsMiddleware` is
unaffected.

`SECURITY_COEP` set to `require-corp` demands that every cross-origin
subresource opt in, and blocks each one that has not. It is what
`crossOriginIsolated` needs, and the most disruptive of the three.

### The Swagger UI page

The Swagger UI page at `/openapi` loads `swagger-ui-dist` from a CDN,
which a `script-src` of `'self'` would block, so it sends its own
policy — narrower than a typical application-wide one, with a
per-response nonce for its inline script and `connect-src 'self'` so it
can fetch its own document and nothing else. Because a header already on
the response is never replaced, the application's policy governs every
other route.

## `RequestBodyMiddleware`

### Staging and parsing

The middleware is the one place a request body becomes something a
handler can use, whichever runtime delivered it: an adapter turns its
transport into a raw PSR-7 request and stops there. Three things happen,
in order.

**The declared `Content-Length` is checked first**, so a request that
labels itself oversized is refused without being read.

**Then the body is staged** — read once, incrementally, counted, into a
seekable `php://memory` stream, and rewound. This is what bounds a
request with no `Content-Length`, or one that under-reports its size. It
happens for every request, not only for forms. Staging and size
enforcement finish before the handler runs, so no later read re-runs
either. `read()` and `getContents()` answer from wherever the cursor
stands, so code that needs the whole body uses a `(string)` cast, which
rewinds first, or rewinds explicitly. A raw or binary body reaches the
handler untouched apart from being staged.

**Then a form is parsed.** For `application/x-www-form-urlencoded` and
`multipart/form-data` on a method that carries a body, the staged bytes
are read into `getParsedBody()`/`getUploadedFiles()` under
`Kinetis\Http\Form\FormLimits` — the byte ceiling plus six ceilings a
byte count cannot express: input variables, file parts, nesting depth,
multipart parts, header lines per part, and bytes per header line. The
body stays readable afterwards. Nothing is truncated: a form past any
ceiling is refused whole with `413`.

The `400` for an unparseable body carries a fixed message, never the
parser's own text, which is assembled from the input that failed.

### Ceilings outside PHP

Some ceilings apply before Kinetis has the bytes. Under
`kinetis/roadrunner-adapter`, the required `http.max_request_size`
setting bounds a body whose length was never declared, since RoadRunner
reads the whole body into memory before the PHP worker runs. Under
`kinetis/bref-adapter`, API Gateway has already accepted and materialized
the body, up to Lambda's 6 MB invocation payload limit. Under FrankenPHP
and PHP-FPM, `enable_post_data_reading=0` is what makes the body
Kinetis's to bound — PHP's own `post_max_size`/`max_input_vars` never see
it. See {doc}`runtime-adapters`.

## `CorsMiddleware`

### Preflights and pass-through

A CORS preflight (`OPTIONS` with `Access-Control-Request-Method`) to a
path with no registered `OPTIONS` route never reaches route middleware,
which runs only after a route has matched. Registered globally,
`CorsMiddleware` answers the preflight before routing runs.

A request with no `Origin` header, or an `Origin` not on the allow list,
passes through untouched — no CORS headers added, no error status. The
browser's same-origin policy blocks a disallowed cross-origin response
once it does not see an `Access-Control-Allow-Origin` naming it; nothing
server-side needs to reject the request.

`allowedHeaders: ['*']` reflects the preflight's
`Access-Control-Request-Headers` instead of checking a fixed list. With
`allowCredentials: true`, the response always echoes the specific
requesting origin rather than a static value.

(cors-caching)=
### Response caching and `Vary`

`CorsMiddleware` marks every response it produces with the `Vary` tokens
a shared cache needs, since the same method and URI can answer with
several representations depending on the request's CORS-relevant
headers.

`Vary: Origin` is added whenever the middleware is configured to allow
anything at all (`allowedOrigins` or `allowedOriginPatterns` non-empty) —
including the disallowed or absent-`Origin` pass-through response, and
including a literal `allowedOrigins: ['*']` allow-list. A wildcard
allow-list always answers with the literal `*` value once an `Origin` is
present, but a request with no `Origin` takes a different branch (no
`Access-Control-Allow-Origin` header at all) — two response shapes a
cache keyed only on method and URI cannot otherwise tell apart. Only a
completely unconfigured `CorsMiddleware`, where every request takes the
same pass-through branch, adds no `Vary` token.

An `OPTIONS` request from an allowed origin adds `Vary:
Access-Control-Request-Method` on both sides of the preflight boundary:
a preflight is answered directly by `CorsMiddleware`, while an ordinary
`OPTIONS` request (the header absent) falls through to routing — most
commonly a `405` if the path has other methods registered, or whatever
an application's own `OPTIONS` route returns.

`Vary: Access-Control-Request-Headers` is added on a preflight only when
`allowedHeaders: ['*']`, the one configuration where
`Access-Control-Allow-Headers` reflects what was requested.

Every token goes through one canonical merge: existing tokens (across one
comma-separated value or several header lines) are parsed, compared
case-insensitively, and deduplicated against themselves and the tokens
being added, folded into a single header line. An application response
that already carries its own `Vary` dimension (`Vary: Accept-Encoding`)
keeps it; an existing `Vary: *` is left untouched, since it already
covers anything CORS could add.

### Why patterns match the whole `Origin`

`allowedOriginPatterns` is consulted when the `Origin` matches none of
`allowedOrigins` exactly. A pattern has to match the `Origin` in full,
so an unanchored `example\.com` does not allow
`https://evil-example.com.attacker.net`. Anchors remain worth writing
for clarity, but leaving them out cannot widen what a pattern allows.

The whole-`Origin` rule is what enforces this, rather than a check that
the pattern carries `^` and `$`. Such a check cannot be trusted: an
alternation like `#^https://good\.com$|evil\.com$#` carries both anchors
and is still unanchored on its second branch, so it would pass
inspection while allowing any origin ending in `evil.com`. Nothing
generic can tell an unescaped dot from an intended wildcard, which is
why escaping stays the application's responsibility.

Patterns are compiled at construction, and one that cannot compile
raises `InvalidArgumentException` there — at request time it would match
nothing and deny every origin it was written to allow. A policy beyond
pattern matching, such as a per-tenant allow-list, is an application
middleware.

## `RateLimitMiddleware`

### Counters and keys

Every policy is constructed with a non-empty policy ID naming the
counter it owns. That ID is the whole identity: two instances built with
the same ID count one client against one budget, and raising a limit,
adding a trusted proxy, or moving the policy into a subclass leaves the
counters a running deployment holds where they are.

The window is fixed. Both the policy ID and the client identifier are
sha256-hashed before they reach the cache — not for concealment, but
because PSR-16 forbids `{}()/\@:` in a key, and a bare IPv6 address is
full of colons.

The middleware holds no per-request state in its properties, which is
what makes it safe as global middleware. A policy can also be bound on
`AppScope` with a factory in `bootstrap.php`; a subclass fixing its own
arguments needs no binding.

### Why the cache must count atomically

The given cache must implement `Kinetis\SimpleCache\AtomicCounterInterface`
— `RedisSimpleCache` does — and construction throws
`Exception\RateLimitUnavailableException` for any cache that does not,
`NullSimpleCache` included.

PSR-16 alone can only count by reading a value and writing it back,
which is not safe across processes: every request in flight reads the
same number before any of them writes, so each believes it is the
first, and the limiter stops applying under the concurrency it exists to
resist. A null cache would store nothing and enforce no limit while
still emitting healthy `X-RateLimit-*` headers. Both fail at boot rather
than behind a flag the application has to check.

Implementing the interface for another backend is two methods,
`increment()` and `count()`.

### Configuration checked at construction

Each trusted-proxy range is parsed when the middleware is constructed.
One that cannot be — a prefix length outside 0-32 for IPv4 or 0-128 for
IPv6, or an address that isn't one — raises
`Exception\InvalidRateLimitConfigException` there rather than on the
first request, since the list decides who may set `X-Forwarded-For`. A
blank policy ID raises the same exception, and `maxAttempts` and
`windowSeconds` must both be at least 1: a window of zero has no length
to divide the clock into, and a negative one stores the counter already
expired, so nothing is ever counted while the headers keep looking
healthy.

### Forwarded client identity

When a request comes through more than one trusted hop, the
`X-Forwarded-For` chain is walked from the end backward, skipping every
entry that is itself a trusted proxy — the first untrusted entry is the
client. That walk is `Kinetis\Http\TrustedProxies`': the same
implementation and range grammar the runtime adapters apply before
letting a forwarded header decide a request's scheme (see
{doc}`runtime-adapters`).

The *policy* it walks is this middleware's own, built from the list given
to its constructor. It is a separate instance from the one the adapters
were handed, and it may name a narrower set of edges — a rate limiter
can be told to believe fewer hops than the application trusts for a
request's scheme. `REMOTE_ADDR` itself is never rewritten: the transport
peer stays what connected, and the client behind an edge is derived from
it when a bucket is keyed.

### One check per policy per request

`process()` records its decision as a request attribute, so the same
policy ID reached twice for one request — globally and again on the
matched route — reads that decision back instead of incrementing again.

`X-RateLimit-Limit` and `X-RateLimit-Remaining` follow the same rule from
the other direction: the policy that ran closest to the controller is the
one whose numbers reach the client, on success and on `429` alike. An
outer policy that is within budget never overwrites them.

Changing a policy's ID changes its cache key, so during a rolling deploy
old and new workers count the same request against different keys until
the old workers are gone and the old key's TTL expires.

`AuthenticatedRateLimitMiddleware` keys by `user:<id>` when the request
scope holds a `CurrentUserInterface`, and falls back to the base class's
IP identifier otherwise. It counts through the same atomic primitive.

## See also

- {doc}`middleware` — the task guide this page supports.
- {doc}`bootstrapping` — registering global middleware and replacing
  default bindings.
- {doc}`container` — `AppScope`, `RequestScope`, disposal, and the
  initializers every request scope runs.
- {doc}`core-concepts` — the request lifecycle both pipelines sit inside.
- {doc}`runtime-adapters` — request bodies and forwarded headers under
  each runtime.
- {doc}`appendix-routing-validation` — binding and validation behind the
  route pipeline.
