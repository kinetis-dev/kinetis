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

Matching is first-match-wins in registration order, so two routes may
overlap — `/users/{id:\d+}` alongside `/users/{id}`, or `/users/self`
alongside `/users/{id}` — and the earlier registration takes the requests
both match. A second route claiming *exactly* the same requests (the same
method and path shape — placeholder names don't count, so `/users/{id}`
and `/users/{userId}` collide) is rejected at registration with a
`DuplicateRouteException`, since it could never run at all.

### Constraining a placeholder's shape

A plain `{id}` matches any run of characters up to the next `/`. Add an
optional `:pattern` suffix — a raw regex fragment, no delimiters, no
anchors — to constrain it further:

```{code-block} php
#[Get('/orders/{id:\d+}')]
public function show(int $id): array { /* ... */ }

#[Get('/files/{hash:[0-9a-f]{40}}')]
public function download(string $hash): array { /* ... */ }
```

A path segment that doesn't match the constraint never matches the route
at all — `GET /orders/abc` against the first example above 404s the same
way a completely unregistered path would, rather than reaching the
controller with a value that would go on to fail a `#[Query]`/`#[Body]`
constraint check instead. `{id:\d+}` and a `#[GreaterThan(0)]` on the
same `int $id` parameter are complementary, not redundant: the route
constraint decides whether this path matches *this route at all* (versus
falling through to a 404, or to a different route registered for the
same literal segment shape); a parameter constraint decides whether an
already-matched value is *valid* (versus a 422). A fixed-length
constraint like the SHA-1 example above needs its own `{n}`/`{n,m}`
repetition quantifier, which is handled correctly even though it
contains braces of its own — nothing about the placeholder syntax gets
confused by a `{...}` inside the constraint.

A pattern is regex text Kinetis inserts rather than rewrites, and the
brace scanner that finds where the placeholder ends reads enough PCRE to
know where a `}` is *not* that end. All of these parse and match
correctly:

| constraint | matches |
|---|---|
| `{value:\}}` | a literal `}` — an escaped brace |
| `{value:[{]}` | a literal `{` — a brace as an ordinary character-class member |
| `{value:[[:alpha:]{]}` | a letter or a literal `{` — a POSIX sub-form inside a class |
| `{value:a\Q{\E}` | a literal `a{` — a `\Q...\E` quoted span |
| `{value:\Q~\E}` | a literal `~` — the delimiter itself, inside a quoted span |
| `{value:(?#})a}` | `a` — a `}` inside a `(?#...)` comment group |
| `{value:[#~!%@|+\-=]+}` | any of those characters, delimiter included |

The delimiter is `~`, and a literal occurrence of it in a pattern is
escaped rather than dodged by picking a different one. Inside a `\Q...\E`
span that escape needs a rewrite rather than a plain backslash — a
backslash is literal text there, so `\~` would match two characters
instead of one — so the span is closed and reopened around it. That
happens automatically; the pattern you write is the pattern that runs.

```{warning}
The scanner is a bounded reader of those constructs, not a full PCRE
parser, and the supported constraint grammar is exactly what it can read
faithfully. Two things fall outside it, and both are rejected at
registration with an error naming them rather than mis-scanned:

**Extended mode** — the `x` flag, via `(?x)`, `(?x:...)`, or `x` among a
set that enables it like `(?imx:...)`. In extended mode an unescaped `#`
starts a comment running to the end of the line, so a `}` after one would
stop closing the placeholder; unlike every construct in the table above,
whether the mode is on at a given point is flag *scope* rather than
something with a fixed opener and closer. A route constraint is a single
fragment, with no real need for the whitespace and comments extended mode
exists to allow. Only flags a run actually *enables* count — everything
after a `-` is being switched off, so `(?-x:...)` and `(?im-sx:...)`
register and match normally.

**Control verbs** — anything spelled `(*...)`, such as `(*MARK:name)` or
`(*atomic:...)`. Their shape varies by verb: some end at their first `)`
while others hold a whole nested sub-pattern, so a `}` inside one can't
be told apart from the brace closing the placeholder. Use `(?>...)` for
atomic grouping; the backtracking verbs have no meaning in a
single-fragment constraint.

Both exclusions are about a construct being *active*, not about the
characters that spell it. The constructs in the table above compose with
them exactly as you'd expect: `{value:[(*]}` is a character class
matching `(` or `*`, `{value:\Q(?x)\E}` matches that literal text, and
`{value:(?#(*)a}` is a comment followed by `a` — none of them turns
anything on, and all three register and match.
```

This is purely a routing-time detail: `/orders/{id}` and `/orders/{id:\d+}`
are indistinguishable to a client, to `#[Query]`/`#[Body]` binding, and
in the generated OpenAPI document — the constraint moves into the path
parameter's own `schema.pattern` there, and the path key itself always
reads as plain `{id}`, since OpenAPI's own path-templating syntax has no
concept of an inline regex.

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
expected, a non-numeric string for `int`/`float`) is also a `422`,
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

If the container cannot supply it, the failure surfaces: a route that
forgot the middleware meant to register the value fails loudly rather
than handing the controller something disconnected. Give the parameter a
default to say that absence is acceptable instead:

```{code-block} php
#[Get('/reports/maybe')]
public function maybe(?CurrentUserInterface $user = null): array
{
    return ['signedIn' => $user !== null];
}
```

A default has to be written out even when the type is nullable — unlike
`#[Query]` and path parameters, where a nullable type alone is enough.
Absence means something different here: for those, a missing value is
ordinary input variation, while a value the container cannot supply is
usually a route missing its middleware. Writing the default is how you
say which of the two you meant.

The default covers genuine absence only. A service that *was* registered
and then failed to construct, or a dependency cycle, is a defect rather
than an absent value, so it is reported rather than quietly arriving as
`null`.

```{note}
This applies to HTTP controllers. An MCP tool's arguments arrive as one
flat object, so a class-typed parameter there is a DTO hydrated from
those arguments — see {doc}`mcp`.
```

A parameter matching none of the six — untyped, or scalar-typed with no
attribute and no matching placeholder — falls back to its default value
if it has one, and otherwise fails with an error naming every source it
could have come from, rather than passing `null` silently.

(multipart-form-data-file-uploads)=
## Multipart/form-data & file uploads

`#[Body]` isn't limited to JSON. `Dispatcher` picks how to read the body
from the request's `Content-Type`:

| Content-Type | Read from |
|---|---|
| `application/json` (or anything else) | `json_decode()` on the raw body |
| `multipart/form-data` | `getParsedBody()` |
| `application/x-www-form-urlencoded` | `getParsedBody()` |

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
driving the request — `FrankenPhpAdapter`/`FpmAdapter` populate the
uploaded-files bag via PHP 8.4's `request_parse_body()` for `PUT`/`PATCH`
(PHP's SAPI only does this automatically for `POST`), while
`kinetis/bref-adapter`'s `BrefLambdaAdapter` and
`kinetis/roadrunner-adapter`'s `RoadRunnerAdapter` both parse it
themselves — from the Lambda event body directly, and from the
`http.raw_body: true`-preserved raw body respectively. See
{doc}`runtime-adapters` for what differs underneath each one.
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

    #[Get('/avatars/{id}')]
    public function avatar(int $id): ResponseInterface
    {
        return FileResponse::fromPath("/storage/avatars/{$id}.png");
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
- `FileResponse::fromPath(string $path, int $status = 200, ?string $contentType = null, ?string $downloadFilename = null)`
  reads a file from disk and detects its content type automatically when
  `$contentType` is omitted. `FileResponse::fromContents(string $contents, string $contentType, int $status = 200, ?string $downloadFilename = null)`
  does the same for data you already have in memory — a generated image
  or PDF, for instance. Either one adds a `Content-Disposition: attachment`
  header when `$downloadFilename` is given — see below.
- `RedirectResponse::to(string $url, int $status = 302)` sets a `Location`
  header.
- `ErrorResponse::create(int $status, string $message, array $headers = [])` builds
  `{"error": "..."}` at the given status, the same shape Kinetis's own
  404/405/500 responses already use — a real 405 (a path matches, but not
  this method) carries a real RFC 9110 `Allow` header listing every
  method the path *does* support, via this same `$headers` parameter.

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
fields hydrates from its own defaults. A body that isn't valid JSON, or
that decodes to something other than a JSON object (`null`, a bare
string, a number, a boolean), is a `400` instead, before any field-level
validation runs:

```{code-block} json
{
    "error": "Request body is not valid JSON."
}
```

### Scalar type checking

Before a value is cast to a `#[Body]` field's, `#[Query]` parameter's, or
path parameter's declared scalar type, its actual shape is checked
first — casting only ever happens once that check passes:

- A `string`-typed field/parameter must actually be a string. An array,
  object, number, or boolean is rejected.
- An `int`/`float`-typed field/parameter accepts a real number or a
  numeric string (`"42"` for an `int` field is fine) — but rejects a
  non-numeric string, an array, or a boolean.
- A `bool`-typed field/parameter accepts exactly `true`, `false`, `1`,
  `0`, `"1"`, or `"0"` — nothing else.

A mismatch is a `422` with a message under that field's key, in the same
`errors` structure a failed constraint produces — not a value silently
coerced into something that happens to look plausible (an array becoming
the literal string `"Array"`, a non-numeric string becoming `0`).

Missing and explicitly-null values get the same treatment, whether or not
the field carries any constraint attributes: a `#[Body]` DTO field whose
key is absent from the request is `is required.` unless the constructor
parameter has a default, and a field sent as JSON `null` whose declared
type doesn't allow null is `must not be null.` — both under the field's
key in the same `422`, never a raw `TypeError` from the constructor.

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

This is a data-driven distinction, not a type-driven one: nesting only
happens when the incoming value for that field is actually an array. A
class-typed field holding anything else — most notably an
`UploadedFileInterface` merged in for a [multipart](#multipart-form-data-file-uploads)
field — passes through completely unchanged, exactly like it always has.

```{note}
A self-referencing (or mutually referencing) DTO stops nesting the moment a
class repeats in the chain, rather than recursing forever — not just a
safety net, but a requirement of {doc}`caching`'s AOT compilation, which
bakes a DTO's hydration plan into a cache file via `var_export()` and has
no way to represent a genuinely circular array as re-parseable PHP. A
self-referencing field simply receives its raw array unhydrated one level
deep in that case.
```

### Collections of nested DTOs

A constructor parameter typed `array` and carrying
`#[ListOf(SomeClass::class)]` is hydrated as a list of nested DTOs — each
array-shaped element is hydrated the same way a single nested DTO field is:

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

A list element that isn't itself an array — most notably an
already-constructed instance — passes through completely unchanged, the
same tolerance a single nested DTO field gives a non-array value.

```{note}
The same self-reference guard described above covers a `#[ListOf]` pointing
back at its own class: nesting stops the moment the class repeats in the
chain, and that list's elements receive their raw array unhydrated one
level deep.
```

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
`minLength`/`maxLength`, `exclusiveMinimum`/`exclusiveMaximum`, `pattern`,
`enum`, `format: uri`, `format: uuid`) — except `#[NotBlank]`, which has no
distinct JSON Schema keyword of its own. `#[Query]` parameters and path
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

Both are served **ahead of** the routing pipeline — they read `Router`'s
already-registered routes, not application state, so they need no
`RequestScope` at all.

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

### Clearing the cached document

In development the document is generated per request, so an attribute
you change is visible on the next reload. In production it is generated
once and cached in whatever `CacheInterface` the application has bound,
with no expiry: the route table cannot change without a deployment, and
a document that expired on a timer would spend that window describing an
API the deployment no longer serves.

The consequence is that a deployment which changes routes, DTOs, or
constraints has to drop it:

```{code-block} sh
php vendor/bin/kinetis openapi:clear
```

Run it alongside `kinetis build`. It is safe when nothing is cached, and
in development, where nothing ever is. With no cache configured — the
default `NullSimpleCache` — nothing is stored and every request
regenerates, which is correct but slower for a large route table.

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
- {doc}`runtime-adapters` — how each runtime gets a request's multipart
  body into the uploaded-files bag `#[Body]`/`UploadedFileInterface` read
  from here.
