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
nothing to register by hand — see {doc}`getting-started` and {doc}`cli`
(including how to restrict the scan for a large application). Methods
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

This is purely a routing-time detail: `/orders/{id}` and `/orders/{id:\d+}`
are indistinguishable to a client, to `#[Query]`/`#[Body]` binding, and
in the generated OpenAPI document — the constraint moves into the path
parameter's own `schema.pattern` there, and the path key itself always
reads as plain `{id}`, since OpenAPI's own path-templating syntax has no
concept of an inline regex.

## Parameter binding

A controller method's parameters are resolved from five possible sources,
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

A parameter matching none of the above falls back to its default value, if
it has one; if it doesn't, the request fails with a clear "unresolvable
parameter" error rather than passing `null` silently.

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
(PHP's SAPI only does this automatically for `POST`), and
`kinetis/bref-adapter`'s `BrefLambdaAdapter` parses it from the Lambda
event body directly. See {doc}`runtime-adapters` for what differs
underneath each one.
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
  header when `$downloadFilename` is given.
- `RedirectResponse::to(string $url, int $status = 302)` sets a `Location`
  header.
- `ErrorResponse::create(int $status, string $message, array $headers = [])` builds
  `{"error": "..."}` at the given status, the same shape Kinetis's own
  404/405/500 responses already use — a real 405 (a path matches, but not
  this method) carries a real RFC 9110 `Allow` header listing every
  method the path *does* support, via this same `$headers` parameter.

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
- `GET /docs` — a Swagger UI shell rendering it.

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
`RequestScope` at all. Turn them off entirely with:

```{code-block} php
new Kinetis\Http\Kernel($app, $router, exposeOpenApi: false);
```

### Hiding a route from the document

`#[Hidden]` excludes a route from `/openapi.json` and `/docs` — the route
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
