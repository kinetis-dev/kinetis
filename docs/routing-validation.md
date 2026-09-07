# Routing & Validation

Kinetis's routing and validation are both attribute-driven — routes,
parameter binding, and constraint validation are declared directly on your
controller classes and DTOs, with no separate YAML/XML/array configuration
file to keep in sync.

## Route attributes

```{code-block} php
use Kinetis\Http\Attributes\{Get, Post, Put, Patch, Delete};

final readonly class UserController
{
    #[Get('/users')]
    public function index(): array { /* ... */ }

    #[Post('/users', status: 201)]
    public function store(): array { /* ... */ }

    #[Put('/users/{id}')]
    public function replace(int $id): array { /* ... */ }

    #[Patch('/users/{id}/status')]
    public function updateStatus(int $id): array { /* ... */ }

    #[Delete('/users/{id}')]
    public function destroy(int $id): array { /* ... */ }
}
```

All five implement a shared `RouteAttribute` interface (`httpMethod()`,
`path()`, `status()`) — so adding a sixth verb, if you ever needed one,
is a matter of implementing that interface, not touching `Router` itself.

Routes are discovered automatically: any class anywhere under one of your
own PSR-4 roots is registered the moment a route attribute appears on one
of its methods, with no required directory or namespace convention and
nothing to register by hand — see {doc}`cli`
(including how to restrict the scan for a large application, and how
installed packages contribute discovered classes through their own
`extra.kinetis` scan roots). Methods
without a route attribute are silently skipped, so a controller can
freely mix routed actions with plain helper methods. Each `{placeholder}`
in a path template is compiled to a named regex capture group once, when
the route is registered — not on every request.

Matching follows a stable, content-only specificity order — never
registration or discovery-scan order, so live discovery and a compiled
cache always agree on which route wins for the same set of routes. Each
real `/`-delimited path segment ranks into one of three tiers, most to
least specific: fully static (`self`, `report-2026.pdf`); a placeholder
mixed with literal text in the same segment (`report-{id}.pdf`); a
placeholder occupying the whole segment (`{id}`). The tier always wins
first — a mixed segment beats a pure placeholder. Once every shared
segment ties, the route with more segments is treated as the deeper,
more specific match. `/users/self` alongside `/users/{id}`, or
`/files/report-{id}.pdf` alongside `/files/report-2026.pdf`, can
therefore both be registered, in either order, and the more specific one
always wins for a path it also matches. A second route claiming
*exactly* the same requests (the same method and path shape — placeholder
names don't count, so `/users/{id}` and `/users/{userId}` collide) is
rejected at registration with a `DuplicateRouteException`, since it could
never run at all.

A method may carry more than one route attribute — `#[Get('/x')]` and
`#[Post('/x')]` on the same method register two independent routes
sharing that controller method and its middleware. Registering a
controller is all-or-nothing: every one of its routes is reflected and
checked for conflicts before any of them are committed, so a later
method's bad path, or a conflict against an earlier one, leaves none of
that controller's routes registered rather than just the ones reflected
before the failure. Registering the same class a second time is a safe
no-op *only* when the global-middleware context is identical to the one
already used — the reason a class discovered through more than one scan
(a project's own scan overlapping a package's `extra.kinetis` root, for
instance) never ends up with its routes registered twice, since every
such scan shares the one project-wide global-middleware list. A second
registration under a genuinely different context is rejected instead of
silently kept under the first one.

### What a placeholder matches

A path template describes URL structure and nothing else. `{id}` occupies
one whole segment and matches any run of characters up to the next `/`;
there is no inline syntax for narrowing that. What a captured value may
actually hold is described where the value is consumed — by the
controller parameter's own type and its validation attributes:

```{code-block} php
#[Get('/orders/{id}')]
public function show(#[GreaterThan(0)] int $id): array { /* ... */ }

#[Get('/files/{hash}')]
public function download(#[Regex('#^[0-9a-f]{40}$#')] string $hash): array { /* ... */ }
```

`GET /orders/abc` therefore reaches the route and fails binding with a
`422` naming `id`, rather than falling through to a 404. That single
place is also what the generated OpenAPI document reads: the path key is
the plain template, and the parameter's declared type and constraints
become its `schema`, minus any constraint with no JSON Schema keyword to
map onto (see [Validation constraints](#validation-constraints)).

A `{...}` expression that isn't a plain placeholder name — `{id:\d+}`,
`{not a name}`, or an unclosed `{id` — is a mistake in the template, not
literal text, and is rejected at registration with an
`InvalidRoutePathException` naming the expression. Placeholder names
follow PHP's identifier grammar restricted to ASCII, and the same name
may appear only once in one template. Two placeholders may not sit
directly against each other either — `{first}{second}` gives nothing to
split a segment on, so it is rejected the same way; separate them with
literal text (`{first}-{second}`) or capture the segment as one
placeholder.

## Sharing routes across controllers

`#[RoutePrefix]` prepends a path segment to every route on a controller.
Combined with a trait, that lets one set of route methods be mounted at a
different path by each controller that uses it:

```{code-block} php
trait CrudRoutes
{
    #[Get('/')]
    public function index(): array { ... }

    #[Get('/{id}')]
    public function show(int $id): array { ... }
}

#[RoutePrefix('/users')]
final class UserController
{
    use CrudRoutes;
}

#[RoutePrefix('/orders')]
final class OrderController
{
    use CrudRoutes;
}
```

That registers `/users`, `/users/{id}`, `/orders` and `/orders/{id}`. A
route declaring `/` sits at the prefix itself, which is what
`UserController::index()` above does.

**Every declared path must start with `/`** — a route path is absolute,
so `#[Get('users')]` is a typo rather than a shorthand and is rejected at
registration, as is `#[RoutePrefix('users')]`. The empty string is
rejected for the same reason: it would resolve to `/` and quietly claim
the root route, which is almost never what someone leaving a path blank
meant.

Trailing slashes, by contrast, are normalised away, so every path is
stored in one canonical form. `#[Get('/users')]` and `#[Get('/users/')]`
are the same route, and declaring both is a duplicate rather than two
routes each answering half the requests you'd expect. `/` itself is
unchanged.

The request path goes through the same rule, so a request for `/users/`
reaches a route registered as `/users` and binds path parameters exactly
as it would without the slash. Both URLs serve the response directly
rather than redirecting; if you'd rather have a `301` to the canonical
form — for search engines, say — that belongs in front of the
application.

The prefix is resolved when the route is registered, so everything
downstream sees the finished path: duplicate detection, the compiled
cache, the OpenAPI document and `kinetis routes:list`. Two controllers
sharing one trait under different prefixes therefore don't collide, while
two under the *same* prefix are rejected as duplicates, exactly as if the
paths had been written out by hand.

A trait is the way to share route methods — not a base class. An
attribute is only ever read from the class it is written on, so a routed
method inherited from a parent is rejected at registration; see
[Where attributes are read from](cli.md#where-attributes-are-read-from).

(a-middleware-can-own-a-prefix-too)=
### A middleware can own a prefix too

`#[RoutePrefix]` also reads from a *middleware* class, not just a
controller — a real use for API versioning, where the prefix and a piece
of version-related behavior naturally belong to the same class:

```{code-block} php
#[RoutePrefix('/v1')]
final class VersionMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

#[Middleware(VersionMiddleware::class)]
final class UserController
{
    #[Get('/users')]
    public function index(): array { ... }
}
```

`/users` becomes `/v1/users`, with no change to `UserController` itself —
every controller referencing `VersionMiddleware` moves together the next
time its own `#[RoutePrefix]` changes. The same is true of a middleware
discovered as {doc}`global <middleware>` (`#[AsGlobalMiddleware]`): its
prefix applies to every route in the project, ahead of everything else,
which is the shape a whole API living under one version prefix actually
takes.

A route's final path composes outer to inner, in the same order the
middleware itself would run in: the global-middleware chain first
(priority order), then the route's own `#[Middleware(...)]` chain
(class-level before method-level — the same order
{ref}`described above <route-middleware>`), then the controller's own
`#[RoutePrefix]`, then the route's own declared path. Since declaration
order decides both what runs first *and* which segment lands leftmost,
two controllers referencing the same prefixed middlewares in a different
order end up with genuinely different URLs — not a bug, just the same
fact about declaration order already being visible in one more place.

A middleware referenced only through a `#[Middleware('@name')]` group
never contributes a prefix this way: group membership isn't resolved
until `Kernel` is constructed, well after routing has already produced
the final path.

## Parameter binding

A controller method's parameters are resolved from six possible sources,
checked in this order:

### `#[Body]`

A parameter attributed `#[Body]` is bound to the decoded JSON request
body. Its declared type must be a class — that class is the DTO
`Hydrator` builds and validates (see [Validation](#validation-constraints)
below) *before the controller method ever runs*.

```{code-block} php
#[Post('/users')]
public function store(#[Body] CreateUserRequest $data): UserResponse
```

### `#[Query]`

A parameter attributed `#[Query]` is bound to a query-string value of the
same name, cast to the parameter's declared scalar type. A missing value
falls back to the parameter's default; without one, a nullable parameter
receives `null`, and a non-nullable one is a `422` (`is required.`),
joining the route's other binding errors in the same response. A value
whose shape doesn't match the declared type (an array where a scalar is
expected, a non-numeric or fractional string for `int`) is also a `422`,
not a silently wrong cast — see [Scalar type checking](#scalar-type-checking)
below.

Constraint attributes (`#[GreaterThan]`, `#[In]`, ...) work here too, the
same as on a `#[Body]` DTO field:

```{code-block} php
#[Get('/users')]
public function index(
    #[Query] #[GreaterThan(0)] int $page = 1,
    #[Query] #[In(['asc', 'desc'])] string $sort = 'asc',
)
```

### Path parameters

A parameter with no attribute at all is matched by name against a
`{placeholder}` in the route's path template, if one exists with the same
name, and cast to the parameter's scalar type — with the identical
type-mismatch check and Constraint-attribute support `#[Query]` above
describes; a path segment that doesn't match the declared type or fails
its own constraint is a `422`, not a value silently coerced to something
like `0`.

```{code-block} php
#[Get('/users/{id}')]
public function show(int $id)
```

### `ServerRequestInterface`

A parameter typed `ServerRequestInterface` receives the raw PSR-7 request
directly — no attribute needed, checked ahead of the others. Bypasses
`#[Body]`'s decoding assumptions entirely, for anything that needs the
request itself: a raw body stream, headers, a different content type.

```{code-block} php
use Psr\Http\Message\ServerRequestInterface;

#[Post('/webhooks')]
public function receive(ServerRequestInterface $request): array
```

### `UploadedFileInterface`

A parameter typed `UploadedFileInterface` — no attribute needed, checked
alongside `ServerRequestInterface` — is resolved directly from the
request's uploaded-files bag by parameter name. See
[Multipart/form-data & file uploads](#multipart-form-data-file-uploads)
below.

```{code-block} php
use Psr\Http\Message\UploadedFileInterface;

#[Post('/files')]
public function receiveFile(UploadedFileInterface $file): array
```

A request without the expected file resolves like a missing `#[Query]`
value: the parameter's default if it has one, `null` if its type allows
null, and a `422` (`is required.`) otherwise.

### Class-typed parameters: services and request context

A class-typed parameter matching none of the above is resolved from the
request container — checked last, so it can never shadow `#[Body]`,
`#[Query]`, or a path placeholder.

This is what lets one controller serve both a public route and a guarded
one. A constructor is shared by every route on its class, so naming a
middleware-registered value there would demand it on routes that never
run that middleware; a method signature is per route:

```{code-block} php
final readonly class ReportController
{
    #[Get('/reports/public')]
    public function teaser(): array
    {
        return ['sample' => true];
    }

    #[Get('/reports/private')]
    #[Middleware(BearerAuthMiddleware::class)]
    public function full(CurrentUserInterface $user): array
    {
        return ['userId' => $user->id()];
    }
}
```

Anything the container can supply works the same way — a repository, a
`MailerInterface`, whatever a package bootstrap bound — which also means
a dependency only one route needs is only built for that route, instead
of on every request to the class.

If nothing can supply the parameter, the failure surfaces: a route that
forgot the middleware meant to register the value fails loudly rather
than handing the controller something disconnected. A default value, or
a nullable type, says that absence is acceptable instead:

```{code-block} php
#[Get('/reports/maybe')]
public function maybe(?CurrentUserInterface $user = null): array
{
    return ['signedIn' => $user !== null];
}
```

That covers absence only — an id the container could never supply.
Everything else is a defect and is reported rather than quietly arriving
as `null`; {doc}`container` states the rule constructor autowiring and
`Dispatcher` share.

```{note}
This applies to HTTP controllers. An MCP tool's arguments arrive as one
flat object, so a class-typed parameter there is a DTO hydrated from
those arguments — see {doc}`mcp`.
```

A parameter matching none of the six — untyped, or scalar-typed with no
attribute and no matching placeholder — falls back to its default value
if it has one, and otherwise fails with an error naming every source it
could have come from, rather than passing `null` silently. Not every
default can be captured for reuse — see "Default values a plan captures"
below for the rule, which applies to a controller parameter and a DTO
field alike.

(multipart-form-data-file-uploads)=
## Multipart/form-data & file uploads

`#[Body]` isn't limited to JSON. `Dispatcher` picks how to read the body
from the request's `Content-Type`:

| Content-Type | Read from |
|---|---|
| `application/json`, or an `application/*+json` subtype | `json_decode()` on the raw body |
| `multipart/form-data` | `getParsedBody()` |
| `application/x-www-form-urlencoded` | `getParsedBody()` |

A nonblank body under any other media type — or under no `Content-Type`
at all — is refused with a `415` before the DTO is hydrated and before
the controller is constructed, so a handler never receives bytes read
under a header that did not describe them, and neither its constructor
nor a factory registered for it runs. The error names the supported media
types and never echoes the one received. A blank or whitespace-only body
still hydrates an all-optional DTO from its own defaults whatever the
header says: there are no bytes for a media type to describe. A route
that has to accept arbitrary or binary bytes takes a
`ServerRequestInterface` parameter instead of `#[Body]`, and receives
them untouched.

A `Content-Type` is matched on its type and subtype alone — everything
before the first `;`, so a `charset` or a multipart `boundary` parameter
changes nothing — and compared ASCII-case-insensitively, as RFC 9110
§8.3.1 requires: `Application/X-WWW-Form-Urlencoded; charset=UTF-8`
lands on the same row as `application/x-www-form-urlencoded`. The match
is exact on the subtype apart from RFC 6839's `+json` suffix, so a
longer media type that merely begins with a listed one —
`application/x-www-form-urlencodedevil` — names none of these rows and
is refused. `Kinetis\Http\MediaType` is that classification,
and the one place a `Content-Type` is read — by `Dispatcher` here, and by
the Kernel's own `RequestBodyMiddleware` before it — so an application
gets the same answer under every runtime (see {doc}`runtime-adapters`).

Field names nest the way PHP's own parser nests them, under every
runtime: `user[address][city]` builds nested arrays,
`tags[]` appends, a repeated plain name replaces, and repeated or nested
file names build the same tree in `getUploadedFiles()`. How large and
how complicated a form may get is bounded by `Kinetis\Http\Form\FormLimits`
— input variables, file parts, nesting depth, multipart part and header
counts, and total bytes — identically under all four adapters, because
one middleware inside the Kernel applies them; a form past any of those
is refused with a `413` before the handler runs, never handed on with the
over-limit fields quietly missing. See "Request bodies: one contract
under every runtime" in {doc}`runtime-adapters` for the numbers and the
reasoning.

A `#[Body]` DTO can mix ordinary fields with an `UploadedFileInterface`-typed
constructor parameter — no special handling needed in the DTO itself:

```{code-block} php
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarUploadRequest
{
    public function __construct(
        public string $name,
        public UploadedFileInterface $avatar,
    ) {}
}
```

```{code-block} php
#[Post('/avatars')]
public function upload(#[Body] AvatarUploadRequest $data): array
{
    return [
        'filename' => $data->avatar->getClientFilename(),
        'contents' => (string) $data->avatar->getStream(),
    ];
}
```

Validation constraints (`#[MinLength]`, `#[Regex]`, ...) work identically on
a multipart-bound DTO's ordinary fields as on a JSON one — `Hydrator` never
knows or cares which content type produced the data it's validating.

An `UploadedFileInterface`-typed parameter doesn't have to sit inside a
`#[Body]` DTO — a top-level controller parameter of that type, with no
attribute, is resolved directly from the request's uploaded-files bag by
parameter name:

```{code-block} php
use Psr\Http\Message\UploadedFileInterface;

#[Post('/files')]
public function receiveFile(UploadedFileInterface $file): array
{
    return ['filename' => $file->getClientFilename()];
}
```

```{note}
This works the same way regardless of which `RuntimeAdapterInterface` is
driving the request, and for every method a form can arrive on. An
adapter delivers raw bytes the runtime never parsed — `php://input`
under the SAPI adapters, which require `enable_post_data_reading=0`, the
event body under `kinetis/bref-adapter`'s `BrefLambdaAdapter`, and the
`http.raw_body: true`-preserved body under `kinetis/roadrunner-adapter`'s
`RoadRunnerAdapter` — and the Kernel's own `RequestBodyMiddleware` fills
the uploaded-files bag from them through `Kinetis\Http\Form`. There is
one parse, under every runtime; see {doc}`runtime-adapters`.
```

## Returning a status other than the route's default

`#[Get('/users/{id}')]`'s `status` argument (default `200`) is only the
status used when the controller returns plain data — an array or a DTO.
Return a PSR-7 `ResponseInterface` directly instead, and `Dispatcher` passes
it through untouched, with whatever status/headers/body you gave it:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Response;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class UserController
{
    public function __construct(
        private UserRepository $users,
    ) {}

    #[Get('/users/{id}')]
    #[Response(404, description: 'User not found.')]
    public function show(int $id): ResponseInterface|array
    {
        $user = $this->users->find($id);

        if ($user === null) {
            return ErrorResponse::create(404, "User {$id} not found.");
        }

        return $user;
    }
}
```

Two different things are happening here, and they don't depend on each
other:

- The `return ErrorResponse::create(...)` **is what actually produces**
  the 404 at request time — `Dispatcher` sees a `ResponseInterface` and
  passes it through untouched instead of wrapping it in the route's
  default status.
- The `#[Response(404, description: ...)]` attribute **only documents**
  that possible outcome for `/openapi.json` — see
  [Zero-config OpenAPI & Swagger UI](#zero-config-openapi--swagger-ui)
  below. `Dispatcher` never reads it; only `OpenApiGenerator` does. Nothing
  enforces that the two agree — you could return a 404 without declaring
  it, or declare a status the method never actually returns.

It documents the statuses *besides* the route's own. The route attribute
already declares that one — `200` unless you set `status:` — and the
generator describes it from the method's return type, response schema
included. An attribute repeating that status is ignored rather than
overwriting the richer entry with a bare description, so there is no way
to accidentally strip a route's own response schema out of the document.

## Returning HTML, files, and redirects

Any route can return something other than JSON, using the same
`ResponseInterface` passthrough — Kinetis ships a few response builders
for the common cases:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Http\Responses\HtmlResponse;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Http\Responses\RedirectResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class PagesController
{
    #[Get('/welcome')]
    public function welcome(): ResponseInterface
    {
        return HtmlResponse::create('<h1>Welcome</h1>');
    }

    #[Get('/reports/{id}.csv')]
    public function report(int $id): ResponseInterface
    {
        return FileResponse::fromContents(
            "id,total\n{$id},42\n",
            'text/csv',
            downloadFilename: "report-{$id}.csv",
        );
    }

    #[Get('/old-url')]
    public function oldUrl(): ResponseInterface
    {
        return RedirectResponse::to('/new-url', 301);
    }

    #[Get('/robots.txt')]
    public function robots(): ResponseInterface
    {
        return PlainTextResponse::create("User-agent: *\nDisallow:\n");
    }
}
```

- `HtmlResponse::create(string $html, int $status = 200)` sets
  `Content-Type: text/html`.
- `PlainTextResponse::create(string $text, int $status = 200)` sets
  `Content-Type: text/plain`.
- `FileResponse::fromContents(string $contents, string $contentType, int $status = 200, ?string $downloadFilename = null)`
  builds a response around bytes you already hold — a generated CSV,
  image or PDF — and adds a `Content-Disposition: attachment` header when
  `$downloadFilename` is given, see below.
- `RedirectResponse::to(string $url, int $status = 302)` sets a `Location`
  header.
- `ErrorResponse::create(int $status, string $message, array $headers = [])` builds
  `{"error": "..."}` at the given status, the same shape Kinetis's own
  404/405/500 responses already use — a real 405 (a path matches, but not
  this method) carries a real RFC 9110 `Allow` header listing every
  method the path *does* support, via this same `$headers` parameter.

No response builder takes a filesystem path. Reading one synchronously
holds the worker thread for the length of the I/O, and core carries no
asynchronous filesystem client.

- Files the deployment owns — CSS, images, downloads shipped with the
  release — are served by the web server in front of the application
  (Caddy's `file_server`, nginx's `root`), which answers them without
  entering a worker at all.
- Files the application owns are read through
  [kinetis/storage](storage.md), whose local adapter suspends the Fiber
  rather than blocking, and the bytes it returns are passed to
  `fromContents()`.
- A body too large to hold in memory is written incrementally by a route
  returning a `Kinetis\Http\StreamedResponse`, whose emitter writes and
  flushes each chunk itself.

### Download filenames are treated as untrusted

`$downloadFilename` is usually whatever a user called the file when they
uploaded it, so it is escaped rather than dropped into the header. The
name is written as an RFC 6266 quoted-string with `\` and `"` escaped as
quoted-pairs, so a value like `a.pdf"; filename="evil.exe` stays one
value instead of closing the quoting and appending a second `filename`
parameter that decides what the browser saves.

A name carrying anything outside ASCII is sent twice: an ASCII fallback
in `filename`, with each non-ASCII byte replaced by `_`, and the real
name percent-encoded in `filename*=UTF-8''…` per RFC 8187, which
recipients prefer when they understand it.

```{code-block} text
attachment; filename="na__ve-r__sum__.pdf"; filename*=UTF-8''na%C3%AFve-r%C3%A9sum%C3%A9.pdf
```

A control character, or an empty string, raises `FileResponseException`.
PSR-7 refuses a control character in any header value regardless, but as
a complaint about the header rather than about the argument that produced
it. Pass `null` for no download name.

Path separators are left alone: RFC 6266 puts stripping them on the
recipient, and rewriting the name here would quietly change what the
caller asked for. Call `basename()` yourself if the source is a stored
path rather than a name.

## Validation constraints

DTOs referenced by a `#[Body]` parameter declare their validation rules as
attributes directly on constructor-promoted properties:

```{code-block} php
use Kinetis\Validation\Constraints\{Email, MinLength, GreaterThan, Regex};

final readonly class CreateProductRequest
{
    public function __construct(
        #[Regex('/^[A-Z]{3}\d{3}$/')]
        public string $sku,

        #[GreaterThan(0)]
        public float $price,
    ) {}
}
```

| Attribute | Checks | Constructor |
|---|---|---|
| `#[Email]` | `filter_var($value, FILTER_VALIDATE_EMAIL)` | *(no arguments)* |
| `#[NotBlank]` | not empty or all-whitespace after `trim()` | *(no arguments)* |
| `#[MinLength(n)]` | `mb_strlen($value) >= n` | `int $length` |
| `#[MaxLength(n)]` | `mb_strlen($value) <= n` | `int $length` |
| `#[GreaterThan(n)]` | `$value > n` | `int\|float $threshold` |
| `#[LessThan(n)]` | `$value < n` | `int\|float $threshold` |
| `#[Regex($pattern)]` | `preg_match($pattern, $value) === 1` | `string $pattern` |
| `#[In($choices)]` | `in_array($value, $choices, true)` | `array $choices` |
| `#[Url]` | `filter_var($value, FILTER_VALIDATE_URL)` | *(no arguments)* |
| `#[Uuid]` | matches an RFC 4122 UUID | *(no arguments)* |

`#[Regex]` and `#[NotBlank]` are runtime-only: neither has an equivalent
JSON Schema keyword. `pattern` holds an undelimited ECMA-262 expression, a
different dialect from the delimited PHP PCRE `#[Regex]` takes, and no
keyword carries `#[NotBlank]`'s trim-aware blank-string semantics —
`minLength: 1` rejects the empty string, not `"   "`. For those two a
generated OpenAPI or MCP schema is broader than the check the request
actually gets. Every other constraint in the table maps onto a keyword; see
[Zero-config OpenAPI & Swagger UI](#zero-config-openapi--swagger-ui).

`#[MinLength]`/`#[MaxLength]` and `#[GreaterThan]`/`#[LessThan]` compose on
the same field for a length or numeric range — `Hydrator` runs every
`Constraint`-implementing attribute on a parameter, not just the first
one:

```{code-block} php
#[GreaterThan(0)]
#[LessThan(100)]
public int $percentage,
```

`Hydrator::hydrate()` checks **every** constrained field before
constructing the DTO — a request with three invalid fields gets all three
errors back in one response, not just the first one it happened to
encounter:

```{code-block} json
{
    "errors": {
        "name": ["must be at least 3 characters."],
        "email": ["must be a valid email address."]
    }
}
```

A failed validation short-circuits straight to a `422` — the controller
method is never invoked at all.

An empty body is treated as no data at all, so a DTO with only optional
fields hydrates from its own defaults — the same outcome a `{}` body
produces. A non-empty body must be a JSON object: one that isn't valid
JSON, or that decodes to anything else (a top-level JSON array, `null`,
a bare string, a number, a boolean), is a `400` instead, before any
field-level validation runs. That check belongs to the decoder, which is
where the object/array distinction still exists:

```{code-block} json
{
    "error": "Request body is not valid JSON."
}
```

### Scalar type checking

Before a value is cast to a `#[Body]` field's, `#[Query]` parameter's, or
path parameter's declared scalar type, its actual shape is checked
first — casting only ever happens once that check passes. This is the
one check shared by every source of typed input: a `#[Body]` DTO field,
a `#[Query]`/path parameter, and — since `Kinetis\Mcp\McpDispatcher`
delegates to the identical `Hydrator::typeMismatchMessage()` method — an
MCP tool's own top-level argument. Every builtin type PHP can attach to
a constructor or method parameter gets one of the two policies below,
never left to fall through silently:

**Supported — checked, cast, and accepted:**

- A `string`-typed field/parameter must actually be a string. An array,
  object, number, or boolean is rejected.
- An `int`-typed field/parameter accepts three things, all inside PHP's
  native integer range: a JSON integer (`42`), a float with no fractional
  part (`42.0`), and a string spelled as a plain base-10 integer (`"42"`,
  `"+42"`, `"-42"`). A string is read as written, never through a float,
  so a decimal spelling (`"42.0"`), an exponent spelling (`"4.2e1"`), a
  whitespace-padded one, and a value a `double` cannot tell apart from an
  integer (`"1.0000000000000001"`) are all rejected. So is a fractional,
  non-finite, or out-of-range number: the result is a `422` ("must be an
  integer within the platform integer range."), never a truncated cast —
  `4.5` does not become `4`. An array or a boolean is rejected too.
- A `float`-typed field/parameter accepts a real number or a numeric
  string, and rejects any value that isn't finite (`"1e999"` overflows to
  `INF`) as well as a non-numeric string, an array, or a boolean.
- A `bool`-typed field/parameter accepts exactly `true`, `false`, `1`,
  `0`, `"1"`, or `"0"` for a `#[Body]`/MCP value — see "Query and path
  values are raw strings" below for the different, source-specific
  spellings a `#[Query]`/path value actually needs.
- An `array`-typed field/parameter (no `#[ListOf]`) must be a real JSON
  *array* (`[...]`), never a JSON object (`{...}`) — including the empty
  object `{}`, and including one whose own keys happen to look
  sequential (`{"0":"a","1":"b"}`). The request body is decoded with
  `json_decode(..., associative: false)`, not `true`, specifically so
  this distinction survives: every JSON object anywhere in the body is
  marked before `array_is_list()` is ever consulted, so a map-shaped
  value — of any shape, empty included — is always rejected with its own
  message ("must be a JSON array, not a JSON object."), never silently
  accepted. `#[ListOf]`'s own array (a real JSON array of nested DTOs)
  gets the identical list-shape check.
- An `iterable`-typed field/parameter gets the identical check as
  `array` — decoded JSON input can only ever produce a PHP array, never
  a real `Traversable`, and a plain array genuinely satisfies PHP's
  `iterable` type, so the accepted shape and the wire contract are the
  same as `array`'s.
- `mixed` accepts anything, JSON `null` included — there's nothing to
  check. Its own JSON Schema is the empty schema *object* (`{}`), never
  a bare `[]` — PHP has no native empty-object type, so a naive empty
  PHP array would otherwise serialize as the invalid JSON array `[]`
  where JSON Schema requires an object.
- A standalone `null`-typed field/parameter (PHP's own literal-null
  type) accepts only a literal JSON `null` — any other value is
  rejected. See "Query and path values are raw strings" below for why
  this type can never be satisfied by a `#[Query]`/path source at all.
- Standalone `true`/`false`-typed fields (PHP 8.2's literal-boolean
  types) each accept exactly that one boolean value — narrower than
  `bool`, which accepts either.

**Rejected — no value is ever accepted, once one is actually supplied:**

- `object`-typed fields/parameters are always rejected. A JSON object on
  the wire is always either hydrated into a nested DTO (a class-typed
  field) or, for a `mixed`/array-element field, unwrapped back into a
  plain PHP array/scalar tree before it ever reaches application code —
  never handed through as a raw PHP `object`, so there is no request
  value that could ever truthfully construct a bare `object`-typed
  parameter.
- `callable`-typed fields/parameters are always rejected, for a
  security reason as much as a representational one: a JSON string
  handed to a `callable`-typed constructor parameter is exactly the
  shape of an arbitrary-function-name-injection risk if that value is
  ever invoked downstream, so it's refused outright rather than treated
  as though it were safe.

Both are also refused earlier, at OpenAPI-document/MCP-tool-schema
generation time, since neither has a truthful JSON Schema representation
this framework produces — but that generation step is optional
(`/openapi.json`, `tools/list`) and never a prerequisite for a route or
tool to register and dispatch real requests. The type-mismatch check
above is what closes the gap for every deployment shape: it runs on
every real request/tool call regardless of whether schema generation
ever executes.

A mismatch is a `422` with a message under that field's key, in the same
`errors` structure a failed constraint produces — not a value silently
coerced into something that happens to look plausible (an array becoming
the literal string `"Array"`, a non-numeric string becoming `0`), and
never a raw `TypeError` escaping the constructor for a genuinely
unsupported type. Every field's own errors are collected together before
throwing once, so two independently-invalid fields in the same request —
including two rejected-category fields at once — both surface in the
same response, not just whichever one happened to be checked first. An
MCP tool's own validation failure surfaces the same `{field: [messages]}`
shape inside a `tools/call` result's `isError: true` content, rather than
a JSON-RPC-level error — see {doc}`mcp`.

### Query and path values are raw strings

The type-mismatch check above is genuinely the same method regardless of
source — but the *value* it checks is not. A `#[Body]`/MCP value is
already a real, JSON-decoded PHP value (a genuine `bool`, `array`, ...);
a `#[Query]`/path value only ever arrives as a raw string (or, for a
`#[Query]` array-style parameter — `?tags=a&tags=b` — a list of them).
Two consequences follow directly from this:

- **`bool`/`true`/`false` accept the OpenAPI-documented `"true"`/`"false"`
  spelling too, not just `"1"`/`"0"`.** `Dispatcher` translates those two
  literal string spellings into real PHP `true`/`false` before the shared
  check runs — the one place a `#[Query]`/path *source* genuinely differs
  from a JSON body, so the same check still receives a genuinely
  equivalent value. `bool`'s own pre-existing `"1"`/`"0"` spellings are
  unaffected.
- **A standalone `null`-typed `#[Query]`/path parameter is rejected at
  registration, not at request time.** There is no established, safe
  string convention for "this means explicit null" the way `"true"`/
  `"false"` is an established convention for booleans, so this
  declaration is unconditionally impossible to satisfy: a `#[Query]`
  parameter with no default fails "is required." when omitted and
  "must be null, ... given." for any value actually sent; a path
  parameter fails the same way *regardless* of any declared default,
  since a matched route's own placeholder capture always supplies a
  real, non-empty string — there is no "value missing" case a default
  could ever be reached from. Both are rejected at `Router::register()`
  itself — the one boundary every route passes through regardless of
  deployment shape, so a route that could never succeed is rejected
  before it can ever register, be advertised at `/openapi.json`, or
  accept traffic, rather than only failing the first time a real client
  actually dispatches to it — a `#[Query]` field genuinely optional at
  this type needs a default (so omitting it is the only way to reach
  it); a path parameter needs a different type, or to move to
  `#[Body]`, where a real JSON `null` is representable.
- **An `array`/`iterable`-typed path parameter is rejected at
  registration too, unconditionally.** A `#[Query]` array works via the
  repeated-key form below, but a route placeholder is always exactly
  one path segment — `Route::match()` captures it as a single string,
  with no repetition, comma, or any other convention that could ever
  turn it into an array. Move it to `#[Query]` (where an array-style
  parameter is representable) or `#[Body]` instead.
- **A `#[Query]` array-style parameter accepts OpenAPI 3.1's own
  *default* query-array serialization** (`style: form`, `explode: true`
  — never stated explicitly in the generated document, since it's the
  spec default whenever neither is overridden): the repeated-key
  spelling, `?tags=a&tags=b`. This is what a client generated strictly
  from `/openapi.json` actually sends, and it is the only form that
  binds — parsed directly from the request's own raw, unparsed query
  string, since PHP's native `parse_str()` (what `getQueryParams()` is
  built from on every runtime) cannot represent it at all: a repeated,
  non-bracketed key silently collapses to its last value there, with
  every earlier one lost. PHP's bracket spelling, `?tags[]=a`, sends a
  different key — the name on the wire is `tags[]`, not `tags` — so it
  satisfies no `#[Query('tags')]` parameter: the value is missing, and
  the parameter's own default, `null`, or an "is required." `422`
  follows, exactly as for a request that never mentioned the key.

### Form-encoded and multipart bodies get the raw-string rules too

A `multipart/form-data`/`application/x-www-form-urlencoded` `#[Body]`
is read from `getParsedBody()`, not JSON-decoded — every field arrives
exactly the way a `#[Query]`/path value does: a raw string (or a real
PHP array, for a repeated/bracketed field name), never an
already-typed JSON value. It shares its DTO class with the JSON-body
path, though — the same `#[Body]` parameter type can receive either
encoding depending on the client's own `Content-Type` — so `Dispatcher`
only applies the `#[Query]`/path-style literal normalization when the
request is genuinely form-encoded, never for a real JSON request
reaching the identical DTO: `describeRequestBody()`'s advertised
content types, `application/json` included, always apply, on the exact
same route, based purely on which the client actually sent.

Every `#[Body]`-reachable route also advertises
`application/x-www-form-urlencoded` and `multipart/form-data` in its
generated `requestBody` alongside `application/json` — the identical
schema under all three, since `Dispatcher` hydrates the same DTO class
regardless of which the client sent; the wire representation is what
differs, laid out below.

- `bool`/standalone `true`/`false` accept both the pre-existing
  `"1"`/`"0"` spelling *and* the `"true"`/`"false"` spelling for a
  form-encoded value — the identical normalization `#[Query]`/path
  already has, applied here only when `Dispatcher` knows the whole
  request body is form-encoded, so a real JSON request for the same
  field still correctly rejects the JSON *string* `"true"` (as opposed
  to the JSON boolean literal `true`) exactly as it always has.
- Standalone `null` can never be satisfied by a form-encoded value at
  all, for the identical reason a `#[Query]`/path value can't (there is
  no established string convention for "this means explicit null") —
  but since the *same* DTO class can also be reached via a genuine JSON
  body on the same route, this is a per-request outcome, not a
  registration-time impossibility the way a `#[Query]`/path parameter's
  own type is: a route accepting a `#[Body]` DTO with a standalone-null
  field still registers and works correctly over JSON, and only fails a
  request that happens to arrive form-encoded instead.
- `array`/`iterable` get the identical map-shaped-value rejection
  documented above (a form-encoded field parsed into a genuinely
  associative PHP array is rejected the same way a JSON object is), but
  the numeric-keyed-object provenance tracking `JsonTree` provides for a
  JSON body does not apply here — there is no JSON object to have lost
  provenance from in the first place; a form-encoded array field's own
  shape comes directly from PHP's parsed-body array, unchanged.
- An `UploadedFileInterface`-typed field is described as `{type: string,
  format: binary}` — OpenAPI's own real convention for a file upload
  inside a multipart-serialized schema — genuinely satisfiable only via
  `multipart/form-data`, the one content type of the three that can
  actually carry a file.

Missing and explicitly-null values get the same treatment, whether or not
the field carries any constraint attributes: a `#[Body]` DTO field whose
key is absent from the request is `is required.` unless the constructor
parameter has a default, and a field sent as JSON `null` whose declared
type doesn't allow null is `must not be null.` — both under the field's
key in the same `422`, never a raw `TypeError` from the constructor.
Nullability and required presence are independent: a nullable field with
no default (`?string $name`) still rejects an absent key, only accepting
one explicitly present and set to `null` — the generated OpenAPI schema
(and an MCP tool's `inputSchema`) states this exactly, listing that field
in `required` and giving it `type: ["string", "null"]` rather than a bare
`"string"`, so a client generated from the schema can't be misled into
thinking the key is safe to omit.

### Asymmetric-visibility properties

Because both `Hydrator` and `Dispatcher` reason about a DTO purely through
constructor-parameter reflection, PHP 8.4's asymmetric visibility works
with zero special-casing:

```{code-block} php
use Kinetis\Validation\Constraints\MinLength;

final class UpdateStatusRequest
{
    public function __construct(
        #[MinLength(3)]
        public private(set) string $status,
    ) {}
}
```

The property's visibility declaration is simply irrelevant to how it's
bound and validated — the constructor parameter is what both classes
actually inspect.

### Writing your own constraint

A constraint is any class implementing the one-method `Constraint`
interface:

```{code-block} php
use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Uppercase implements Constraint
{
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || $value !== strtoupper($value)) {
            return 'must be all uppercase.';
        }

        return null;
    }
}
```

Returning `null` means valid; any non-null string becomes that field's
error message. Any attribute implementing `Constraint` on a parameter is
picked up automatically — there is no fixed list of "known" constraints to
register your own class into.

### Nested DTOs

A constructor parameter typed as another class — not a builtin scalar — is
hydrated as a nested DTO, recursively, the same way the top-level `#[Body]`
DTO itself is:

```{code-block} php
use Kinetis\Validation\Constraints\MinLength;

final readonly class Address
{
    public function __construct(
        #[MinLength(3)]
        public string $street,
        public string $city,
    ) {}
}

final readonly class CreateOrderRequest
{
    public function __construct(
        #[MinLength(2)]
        public string $customerName,
        public Address $shippingAddress,
    ) {}
}
```

```{code-block} json
{
    "customerName": "John Doe",
    "shippingAddress": { "street": "1 Infinite Loop", "city": "Cupertino" }
}
```

A nested DTO's own validation runs the same way its parent's does — every
field, top-level and nested, is checked before construction, and a nested
field's error surfaces under a dotted key (`shippingAddress.street`) rather
than only reporting the outer field name:

```{code-block} json
{
    "errors": {
        "shippingAddress.street": ["must be at least 3 characters."]
    }
}
```

A class-typed field accepts exactly two shapes and nothing else: an
object-shaped value, hydrated into the declared class; or a value that is
already an instance of that class, taken as given — most notably an
`UploadedFileInterface` merged in for a
[multipart](#multipart-form-data-file-uploads) field. A scalar, a `null`
for a non-nullable field, or an object of some other class is a `422`
under that field's key, never a raw `TypeError` from the constructor.

Object-shaped means a JSON object (`{...}`, including `{}`) or — for a
direct `Hydrator::hydrate()` call or a form-encoded body, neither of which
carries a JSON object/array distinction — a map-shaped PHP array. A JSON
array is not an object: `[]` and `[...]` are both a `422` ("must be a JSON
object, not a JSON array.") even for a class whose every field has a
default and would otherwise have accepted no fields at all.

A field typed as a class that cannot be instantiated — an interface, an
abstract class, an enum — accepts only an existing instance: nothing on
the wire can construct one, so an array or a scalar for it is a `422`
("must be a `Psr\Http\Message\UploadedFileInterface` instance."). That
is exactly how a `#[Body]` DTO's own file field works, since `Dispatcher`
merges the uploaded file in as an object.

### Collections of nested DTOs

A constructor parameter typed `array` and carrying
`#[ListOf(SomeClass::class)]` is hydrated as a list of nested DTOs — each
object-shaped element is hydrated the same way a single nested DTO field is:

```{code-block} php
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\ListOf;

final readonly class OrderItem
{
    public function __construct(
        public string $product,
        #[GreaterThan(0)]
        public int $quantity,
    ) {}
}

final readonly class CreateOrderRequest
{
    public function __construct(
        #[MinLength(2)]
        public string $customerName,
        #[ListOf(OrderItem::class)]
        public array $items,
    ) {}
}
```

```{code-block} json
{
    "customerName": "John Doe",
    "items": [
        { "product": "Widget", "quantity": 2 },
        { "product": "Gadget", "quantity": 5 }
    ]
}
```

Each element's own validation errors surface under a dotted
`field.index.nestedField` key, alongside every other error in the same
response:

```{code-block} json
{
    "errors": {
        "items.1.quantity": ["must be greater than 0."]
    }
}
```

Every element gets the same two-shape contract a single nested DTO field
has: object-shaped and hydrated into the item class, or already an
instance of it. A scalar, a `null`, a nested JSON array, or an object of
another class is a `422` under that element's own `field.index` key —
`items.1: must be an object, value given.` — alongside every other error
in the response.

`#[ListOf]` itself is only valid on a parameter typed `array`, and its
item class must be a class that can be instantiated.

### DTO definitions Kinetis rejects

A hydration plan is compiled from a DTO's constructor by reflection —
ahead of time by `kinetis build`, or on that class's first hydration
otherwise. It supports a finite set of parameter shapes: a builtin type,
a single named class (hydrated when it can be instantiated, instance-only
when it can't), an `array` carrying `#[ListOf]`, and nullable variants of
each.

Anything else is rejected while the plan is compiled, with an
`UnsupportedDtoDefinitionException` naming the class and the parameter —
so the definition fails at build time, or on that route's first request
in development, rather than as a `TypeError` on a live one:

- A **union** or **intersection** parameter type (`int|string`,
  `Countable&ArrayAccess`). Kinetis hydrates neither; declare a single
  named type.
- A **recursive or mutually recursive** class reference — a `Comment`
  with a `Comment $parent` field, or two DTOs naming each other. A plan
  embeds each nested class's own plan inline, so a cycle has no finite
  plan, and nothing {doc}`caching`'s AOT compilation could bake into a
  cache file through `var_export()`. Take the nested payload as a plain
  `array` field, or model the deeper level as its own request.
- A class type reflection cannot resolve to a real class: `self`,
  `parent`, `static`.
- `#[ListOf]` on a parameter that isn't typed `array`, or naming a class
  that cannot be instantiated.
- A `#[Body]` DTO class that cannot itself be instantiated.

The generated OpenAPI document and MCP tool input schemas hold the same
line: a class-typed field whose class cannot be instantiated has no
truthful object schema, so schema generation refuses it rather than
emitting a bare `{"type": "object"}` no request could satisfy.
`UploadedFileInterface` is the one such type both sides accept — it is
described as `{"type": "string", "format": "binary"}` and supplied by
`Dispatcher` from the request's uploaded-files bag.

### Default values a plan captures

A plan — the hydration plan behind a `#[Body]` DTO, the binding plan
behind a controller method — is derived once and reused: memoized for a
persistent worker's whole lifetime, and written into
`.kinetis-cache/compiled.php` by `kinetis build` (see {doc}`caching`).
The default value it captures is handed to every request that leaves
that parameter unfilled, so a default has to be a value PHP would have
rebuilt identically on every evaluation: a scalar, `null`, an array of
those, or an enum case. An enum case qualifies because a case is a
process-wide singleton — there is no second instance for a plan to hand
out in place of the one the declaration names.

Any other object default is rejected where the plan is derived, with an
`UnsupportedDefaultValueException` naming the DTO class or
`Controller::method()` and the parameter: on that DTO's first hydration
or that route's registration in development, at build time for an AOT
build.

```php
enum SortDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}

final readonly class SearchRequest
{
    public function __construct(
        public string $term,
        // Captured: every request that omits `direction` gets this case.
        public SortDirection $direction = SortDirection::Ascending,
        // A per-request value: `new DateTimeImmutable()` written here
        // is rejected, since a plan holds one moment — the first
        // request's, or the build's — for every request after it.
        public ?DateTimeImmutable $since = null,
    ) {}
}
```

Where a request-time object is what the parameter wants, declare it
nullable with a `null` default, as `$since` does above, and build the
real value in the constructor body or in the controller. That code runs
per request, which is the whole point of a `new` in a default and the
one thing a captured default cannot do.

## Zero-config OpenAPI & Swagger UI

Every route `Router` has registered is reflected a second time — the same
controller-method metadata `Dispatcher` already reads at request time — to
build a complete OpenAPI 3.1 document, with no annotations beyond the
attributes already shown on this page:

- `GET /openapi.json` — the generated document.
- `GET /openapi` — a Swagger UI shell rendering it. It loads Swagger UI
  from a CDN and sends its own `Content-Security-Policy` permitting
  exactly that, so it keeps working under an application-wide policy
  that would otherwise block it — see {doc}`middleware`.

Both are served by an ordinary controller the framework ships, found by
the same route discovery that finds yours — so they appear in
`kinetis routes:list` alongside your own routes, and any middleware you
attach to them behaves like middleware anywhere else.

`#[Body]` DTOs become `requestBody` schemas, with every constraint from the
table above mapped onto the matching JSON Schema keyword (`format: email`,
`minLength`/`maxLength`, `exclusiveMinimum`/`exclusiveMaximum`, `enum`,
`format: uri`, `format: uuid`) — except `#[NotBlank]` and `#[Regex]`, which
have no JSON Schema keyword to map onto. `#[Query]` parameters and path
parameters become `parameters` entries, with the identical constraint-to-
keyword mapping applied to their own `schema` when they carry one. A
controller method's declared return type becomes the default response's
schema too — `UserResponse` (or `?UserResponse`, or a union like
`ResponseInterface|array` where `UserResponse` is one member) produces a
`content` entry describing it; a bare `array`/`ResponseInterface`-only
return, with no shape reflection can recover, leaves the response
description-only.

A [`#[ListOf]` field](#collections-of-nested-dtos) becomes a `{"type":
"array", "items": ...}` schema, with `items` describing the element class
the same way any other DTO reference does.

Every DTO schema — whether reached via a `requestBody`, a response, or a
[`#[ListOf]`](#collections-of-nested-dtos) element, at any depth — is
deduplicated into `components/schemas` and referenced by `$ref`, rather
than inlined at each point of use:

```{code-block} json
{
    "requestBody": {
        "content": { "application/json": { "schema": { "$ref": "#/components/schemas/CreateOrderRequest" } } }
    },
    "components": {
        "schemas": {
            "CreateOrderRequest": {
                "type": "object",
                "properties": {
                    "customerName": { "type": "string", "minLength": 2 },
                    "shippingAddress": { "$ref": "#/components/schemas/Address" }
                }
            },
            "Address": { "type": "object", "properties": { "..." : "..." } }
        }
    }
}
```

The component name is the DTO's short class name (`CreateOrderRequest`, not
its full namespace) when nothing else has claimed it; two distinct classes
that happen to share a short name fall back to the fully-qualified name
instead of silently overwriting one schema with the other's.

A route attribute's `status` only describes the *default* response — it has
no way to know a method might also return a `ResponseInterface` directly
with a different status. The repeatable `#[Response(status, description)]`
attribute documents those additional statuses manually — see
`UserController::show()` [above](#returning-a-status-other-than-the-routes-default)
for a complete example. It's purely descriptive and has **no effect on
runtime behavior**: `Dispatcher` never reads it, only `OpenApiGenerator`
does. Each one adds one entry to that operation's `responses` alongside the
route's default — nothing checks that the method actually produces the
status it declares, the same trust already placed in the route attribute's
own default.

Both routes resolve like any other: the `openapi` middleware group runs
through the normal pipeline, and `Kernel` registers `OpenApiAccess` and
`OpenApiDocumentProvider` on each request scope for the controller's
constructor to be autowired from.

### Choosing where the documentation is reachable

Both paths are **off until you name the environments they belong in**.
Together they describe your whole route table, which is reconnaissance
handed over for free rather than a vulnerability by itself, and there is
no version of publishing it that you chose:

```{code-block} text
:caption: .env
APP_ENV=development
OPENAPI_ENVIRONMENTS=development,staging
```

`OPENAPI_ENVIRONMENTS` is a comma-separated list of `APP_ENV` values,
compared ignoring case and surrounding space. It is matched against
`APP_ENV` itself rather than against Kinetis's own `AppEnvironment`,
which resolves every unfamiliar name to production — so a `staging`
deployment can name itself and mean it. Unset, empty, or naming an
environment you are not running in, both paths fall through to routing
and 404 — nothing confirms that they would exist somewhere else.

An explicit argument decides outright and ignores the variable, which is
what a test or a deliberately documentation-only service wants:

```{code-block} php
new Kinetis\Http\Kernel($app, $router, exposeOpenApi: true);
```

The check runs per request rather than when routes are registered. That
is deliberate: registering the routes conditionally would push the
decision into `kinetis build`, and a production image would then answer
according to whichever environment compiled it rather than the one it is
running in. The routes always exist — `routes:list` shows them either
way — and a closed one answers exactly as an unregistered path does.

### When the document is generated

In development the document is generated per request, so an attribute
you change is visible on the next reload.

In production it is generated once per process and held in memory for
that process's lifetime, by a provider the `Kernel` builds for its own
router. The route table cannot change under a running process, and a
deployment that changes routes, DTOs, or constraints starts new
processes, each with its own router and its own provider. So the
document a process serves always describes the routes that process
dispatches: there is nothing to clear, no expiry to wait out, and no
cached entry a previous deployment could leave behind.

### Hiding a route from the document

`#[Hidden]` excludes a route from `/openapi.json` and `/openapi` — the route
itself keeps working exactly as before, only its documentation is
suppressed. Useful for a route that isn't really part of the API surface,
like an HTML page served alongside a JSON API:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;
use Kinetis\Http\Responses\HtmlResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class DashboardController
{
    #[Get('/')]
    #[Hidden]
    public function index(): ResponseInterface
    {
        return HtmlResponse::create('<h1>Dashboard</h1>');
    }
}
```

`#[Hidden]` on a controller class hides every route on it at once, for a
controller that shouldn't appear in the document at all:

```{code-block} php
#[Hidden]
final readonly class InternalController
{
    #[Get('/internal/status')]
    public function status(): array { /* ... */ }
}
```

## See also

- {doc}`container` — how a controller's own constructor dependencies get
  resolved.
- {doc}`middleware` — `#[Middleware]`, the same attribute-driven pattern
  applied to wrapping a route rather than binding its parameters.
- {doc}`caching` — how route/binding/validation metadata gets precomputed
  ahead of time in production, and exactly what that does and doesn't
  change about the behavior described on this page.
- {doc}`runtime-adapters` — how a request's raw bytes reach the one
  middleware that fills the uploaded-files bag
  `#[Body]`/`UploadedFileInterface` read from here.
