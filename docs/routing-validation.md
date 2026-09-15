# Routing & Validation

Routes, request binding and validation are attributes on your controller
classes and request DTOs. There is no route file to keep in sync. This
page covers the everyday path; {doc}`appendix-routing-validation` holds
the complete matching, binding, validation and schema rules.

## A first controller

```{code-block} php
:caption: src/Http/ArticleController.php

<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\{Body, Get, Post};

final readonly class ArticleController
{
    public function __construct(
        private ArticleRepository $articles,
    ) {}

    #[Get('/articles/{id}')]
    public function show(int $id): ArticleResponse
    {
        return $this->articles->get($id);
    }

    #[Post('/articles', status: 201)]
    public function create(#[Body] CreateArticleRequest $request): ArticleResponse
    {
        return $this->articles->create($request);
    }
}
```

`ArticleRepository` and `ArticleResponse` are application classes;
`CreateArticleRequest` is defined in [Validating input](#validating-input).

Nothing registers this controller. Any class under one of your project's
PSR-4 roots joins the route table as soon as one of its methods carries a
route attribute; {doc}`cli` covers restricting that scan in a large
application. Methods without a route attribute are ordinary helpers.

`#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]` and `#[Delete]` each take a path
and an optional `status`, which defaults to `200`. A controller that
returns an array or an object is answered with it as JSON at that status;
[Responses](#responses) covers returning anything else.

- A path starts with `/`. `#[Get('articles')]` is rejected when the route
  registers.
- A trailing slash is ignored, in the declaration and in the request:
  `/articles/` reaches `/articles`.
- `{id}` captures one whole path segment. There is no inline pattern such
  as `{id:\d+}`; the parameter's type and constraints decide what the
  value may be, so `GET /articles/abc` reaches the route and fails with
  `422` rather than `404`.
- The most specific route wins whatever the declaration order:
  `/articles/latest` beats `/articles/{id}`. Two routes with the same
  method and path shape are rejected at registration.

`kinetis routes:list` prints the resulting table. Matching order, the
placeholder grammar and registration failures are in [Route registration
and matching](appendix-routing-validation.md#route-registration-and-matching).

## Binding request values

Each controller parameter takes its value from the first source that
claims it:

| Parameter | Receives |
|---|---|
| typed `ServerRequestInterface` | the PSR-7 request |
| typed `UploadedFileInterface` | the uploaded file with the parameter's name |
| `#[Body]` | a DTO hydrated and validated from the request body |
| `#[Query]` | the query-string value with the parameter's name |
| named like a `{placeholder}` | that path segment |
| any other class type | a service from the request container |

Binding runs before the controller is constructed, so a request that
fails it never reaches your code. A parameter no source claims uses its
default, or fails with an error naming every source it could have come
from.

### Path and query values

```{code-block} php
use Kinetis\Http\Attributes\{Get, Query};
use Kinetis\Validation\Constraints\{GreaterThan, In};

#[Get('/authors/{authorId}/articles')]
public function byAuthor(
    #[GreaterThan(0)] int $authorId,
    #[Query] #[GreaterThan(0)] int $page = 1,
    #[Query] #[In(['newest', 'oldest'])] string $sort = 'newest',
    #[Query] ?string $tag = null,
): array
```

Path segments and query values are text, and bind only when the text
spells the declared type: `"42"` binds an `int`, while `"4.5"` and `"abc"`
do not, and a `bool` accepts `true`, `false`, `1` and `0`. A value of the
wrong type, or one that fails a constraint, is a `422` naming the
parameter. A missing query value takes the parameter's default, then
`null` for a nullable type; a parameter with neither is required.

`#[Query]` takes no arguments: the parameter name is the query key. An
`array` parameter reads a repeated key, `?tag=php&tag=http`. PHP's
bracket spelling, `?tag[]=php`, is a different key and does not bind. A
path parameter is always a single segment, never an array. See [Query and
path values are raw
strings](appendix-routing-validation.md#query-and-path-values-are-raw-strings).

### The request body

`#[Body]` builds the parameter's class from the request body and
validates it before the controller runs, as [Validating
input](#validating-input) shows. The body is read according to its
`Content-Type`:

| `Content-Type` | Read as |
|---|---|
| `application/json`, or an `application/*+json` subtype | a JSON object |
| `application/x-www-form-urlencoded` | form fields |
| `multipart/form-data` | form fields and uploaded files |

- A nonblank body under any other media type, or without a
  `Content-Type`, is refused with `415`. A route that accepts arbitrary
  bytes — a signed webhook, a binary upload — takes a
  `ServerRequestInterface` parameter and reads the body itself.
- A JSON body that is not valid JSON, or not an object, is a `400`. An
  empty body counts as `{}`.
- A body larger than `MAX_BODY_SIZE`, 2 MiB unless you change it, is
  refused with `413` before routing. See [Request body
  limits](middleware.md#request-body-limits).

JSON values carry their own types, while form fields are text. One DTO
accepts both encodings, but a JSON body must send `42` for an `int` field
and `true` for a `bool`: the strings `"42"` and `"true"` are type errors
in JSON and ordinary values in a form. See [Scalar type
checking](appendix-routing-validation.md#scalar-type-checking).

When the payload is wrapped in one member, `{"article": {...}}`, name that
member: `#[Body('article')]`. A method has at most one `#[Body]`
parameter. See [Reading the DTO from one top-level
member](appendix-routing-validation.md#reading-the-dto-from-one-top-level-member).

### Services and the current user

A class-typed parameter matching no other source comes from the request
container: a repository, a mailer, or a value a route middleware
registered for this request.

The authentication example below uses the optional `kinetis/auth`
package. Install and configure its user provider as shown in
{doc}`auth` before guarding the route.

```{code-block} php
use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Http\Attributes\{Get, Middleware};
use Kinetis\Http\CurrentUserInterface;

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

A dependency declared on the method belongs to that route alone. The
constructor is shared by both routes, and only the guarded one has a
current user. When nothing registered the value, the request fails
instead of passing `null`; declare `?CurrentUserInterface $user = null`
where absence is acceptable. {doc}`middleware` shows how a middleware
registers the value, and {doc}`auth` provides `BearerAuthMiddleware`.

## Validating input

A request DTO is a class whose constructor parameters are the fields a
client sends, with constraint attributes as the rules:

```{code-block} php
:caption: src/Http/CreateArticleRequest.php

<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Validation\Constraints\{Email, MaxLength, MinLength, NotBlank};

final readonly class CreateArticleRequest
{
    public function __construct(
        #[NotBlank, MaxLength(200)]
        public string $title,
        #[MinLength(20)]
        public string $body,
        #[Email]
        public string $authorEmail,
        public ?string $summary = null,
        public bool $published = false,
    ) {}
}
```

The `create()` action at the top of this page binds it with
`#[Body] CreateArticleRequest $request`. From that one declaration:

- A field without a default is required; a field with one may be
  omitted. `?string $summary` with no default would still be required,
  and would accept an explicit `null`.
- Every field is checked, and every failure is reported in one response.
- The controller is not constructed for a request that fails validation.
- A JSON member the DTO does not declare is rejected as
  `unexpected_field`, which catches a misspelled field name. Form bodies
  accept extra fields such as a CSRF token.

### The 422 response

A request that fails validation receives an [RFC 9457][rfc9457] problem
document, served as `application/problem+json`:

```{code-block} json
{
    "type": "about:blank",
    "title": "Unprocessable Content",
    "status": 422,
    "detail": "The request data failed validation.",
    "errors": [
        {
            "path": ["title"],
            "code": "required",
            "message": "is required.",
            "parameters": {}
        },
        {
            "path": ["body"],
            "code": "min_length",
            "message": "must be at least 20 characters.",
            "parameters": {"length": 20}
        }
    ]
}
```

Each error carries:

- `path` — the segments leading to the failing value, where a member name
  is a string and a list index an integer: `["lines", 1, "quantity"]`;
- `code` — a stable identifier for a client to switch on;
- `message` — default English text;
- `parameters` — the values the message was built from, for translation.

A path or query parameter that fails its type or a constraint is reported
the same way. To answer with something else, such as a redirect back to
an HTML form, replace the renderer: see [Rendering validation
failures](middleware.md#rendering-validation-failures).

[rfc9457]: https://www.rfc-editor.org/rfc/rfc9457.html

### Constraints

The built-in constraints live in `Kinetis\Validation\Constraints` and
apply to DTO fields, path parameters and query parameters alike:

| Values | Constraints |
|---|---|
| Strings | `NotBlank`, `MinLength`, `MaxLength`, `Regex`, `Email`, `Url`, `Uuid`, `Ip`, `Date`, `DateTime` |
| Numbers | `GreaterThan`, `GreaterThanOrEqual`, `LessThan`, `LessThanOrEqual`, `MultipleOf` |
| Choices | `In`, `NotIn` |
| Lists | `MinItems`, `MaxItems` |
| Uploaded files | `FileSize`, `FileExtension` |

Stack constraints for a range: `#[GreaterThan(0)] #[LessThan(100)] int
$percentage`. The declared PHP type is checked first, so a constraint
only runs against a value of that type. Each constraint's exact check,
code and published schema keyword are in [Validation
constraints](appendix-routing-validation.md#validation-constraints). A
rule of your own implements `Kinetis\Validation\Constraint`; see [Writing
your own
constraint](appendix-routing-validation.md#writing-your-own-constraint).

### Nested objects, lists and enums

```{code-block} php
use Kinetis\Validation\Constraints\{GreaterThan, MinItems};
use Kinetis\Validation\ListOf;

enum Shipping: string
{
    case Standard = 'standard';
    case Express = 'express';
}

final readonly class OrderLine
{
    public function __construct(
        public string $sku,
        #[GreaterThan(0)]
        public int $quantity,
    ) {}
}

final readonly class CreateOrderRequest
{
    public function __construct(
        public Shipping $shipping,
        #[ListOf(OrderLine::class)]
        #[MinItems(1)]
        public array $lines,
    ) {}
}
```

```{code-block} json
{
    "shipping": "express",
    "lines": [{ "sku": "BOOK-1", "quantity": 2 }]
}
```

- A field typed as another class is a nested DTO, validated the same way.
  Its failures carry the full path, such as `["lines", 0, "quantity"]`.
- A backed-enum field takes the case its backing value names; any other
  value is an `enum_case` violation listing the valid values.
- `array` has no element type, so a list declares one with `#[ListOf]`: a
  scalar type name such as `'string'`, a backed enum, a DTO class, or
  `UploadedFileInterface`. `#[Each]` runs a constraint on every element of
  a scalar, enum or file list.
- A field that accepts a JSON object with arbitrary keys is declared
  `#[ObjectMap] array`.

The reference covers [nested
DTOs](appendix-routing-validation.md#nested-dtos), [typed
collections](appendix-routing-validation.md#typed-collections), [backed
enum fields](appendix-routing-validation.md#backed-enum-fields) and
[object maps](appendix-routing-validation.md#object-map-properties).

### Partial updates

A `PATCH` must tell a field the client left out from a field it cleared.
`Absent` is that third state:

```{code-block} php
use Kinetis\Validation\Absent;
use Kinetis\Validation\Constraints\{MaxLength, NotBlank};
use Kinetis\Validation\ObjectConstraints\AtLeastOneProvided;

#[AtLeastOneProvided('title', 'summary')]
final readonly class UpdateArticleRequest
{
    public function __construct(
        #[NotBlank, MaxLength(200)]
        public string|Absent $title = Absent::Value,
        public string|null|Absent $summary = Absent::Value,
    ) {}
}
```

`Absent::Value` means the member was not sent; `null` means the client
cleared it. `#[AtLeastOneProvided]` rejects an update that names no
field. Keep create and update DTOs as separate classes. See [Required,
optional, and absent
fields](appendix-routing-validation.md#required-optional-and-absent-fields)
and [Rules about the whole
DTO](appendix-routing-validation.md#rules-about-the-whole-dto).

## File uploads

A `multipart/form-data` request binds a file to an `UploadedFileInterface`
field of a `#[Body]` DTO, or to a controller parameter named like the
file control:

```{code-block} php
use Kinetis\Http\Attributes\{Body, Post};
use Kinetis\Validation\Constraints\{FileExtension, FileSize, NotBlank};
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarUploadRequest
{
    public function __construct(
        #[NotBlank]
        public string $displayName,
        #[FileSize(maxBytes: 1_000_000)]
        #[FileExtension(['png', 'jpg'])]
        public UploadedFileInterface $avatar,
    ) {}
}

#[Post('/avatars')]
public function upload(#[Body] AvatarUploadRequest $request): array
{
    return ['size' => $request->avatar->getSize()];
}
```

```{warning}
`#[FileExtension]` checks the filename the client chose, and `#[FileSize]`
the size the upload reported. Neither reads the file, and Kinetis never
validates upload contents. Treat both the name and the bytes as untrusted:
check the content where your code reads it, and do not use the client
filename as a storage path.
```

- A file control the user left empty counts as an omitted field: required,
  defaulted, `null` or `Absent::Value`, exactly as for a text field.
- An upload that failed in transfer is one `upload_failed` violation, and
  no constraint runs on it.
- A repeated control, `photos[]`, binds to
  `#[ListOf(UploadedFileInterface::class)] array $photos`.
- Form bodies are bounded by `MAX_BODY_SIZE` and by
  `Kinetis\Http\Form\FormLimits` — field count, file count, nesting depth.
  A form past any limit is refused whole with `413`; see
  {doc}`runtime-adapters`'s "Request bodies: one contract under every
  runtime".

The generated document describes a DTO that declares an upload as
`multipart/form-data` only. Nested file controls and index handling are in
[Uploads](appendix-routing-validation.md#uploads); storing the file is
covered in {doc}`storage`.

## Responses

### Status codes and errors

The route's `status` applies when the controller returns data. For any
other status, return a PSR-7 `ResponseInterface`, which Kinetis sends
unchanged:

```{code-block} php
use Kinetis\Http\Attributes\{Get, Response};
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

#[Get('/articles/{id}')]
#[Response(404, description: 'Article not found.')]
public function show(int $id): ResponseInterface|ArticleResponse
{
    $article = $this->articles->find($id);

    if ($article === null) {
        return ErrorResponse::create(404, "Article {$id} not found.");
    }

    return $article;
}
```

`ErrorResponse::create()` produces the returned `404`, as
`{"error": "Article 7 not found."}` — the shape of Kinetis's own `404`,
`405` and `500` responses. `#[Response]` only documents the extra status
in the OpenAPI document; nothing checks that the method returns it.

An exception can carry its own status instead; see [Mapping your own
exceptions to a
status](middleware.md#mapping-your-own-exceptions-to-a-status).

### HTML, text, files and redirects

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Responses\{FileResponse, HtmlResponse, PlainTextResponse, RedirectResponse};
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

`HtmlResponse` does not escape anything: escape user input yourself, or
render through {doc}`views`.

No response builder takes a filesystem path, because a synchronous read
holds the worker for the duration of the I/O:

- files shipped with the release — CSS, images, downloads — are served by
  the web server in front of the application;
- files the application stores are read through {doc}`storage`, and the
  bytes are passed to `FileResponse::fromContents()`;
- a body too large to hold in memory is written by returning a
  `Kinetis\Http\StreamedResponse`.

`downloadFilename` is escaped for the `Content-Disposition` header, so a
user-supplied name cannot add header parameters. Path separators are
kept; call `basename()` when the name comes from a stored path. Builder
signatures and the filename encoding are in
[Responses](appendix-routing-validation.md#responses).

## OpenAPI documentation

Kinetis generates an OpenAPI 3.1 document from the same attributes:

- `GET /openapi.json` serves the document;
- `GET /openapi` serves Swagger UI for it.

Both are off until you name the environments that serve them:

```{code-block} text
:caption: .env
OPENAPI_ENVIRONMENTS=development,staging
```

Each value is compared with `APP_ENV`, ignoring case. When
`OPENAPI_ENVIRONMENTS` is unset or names no running environment, both
paths answer `404`. The document describes your whole route table, so
name an environment only where that is acceptable, and add middleware
for these two paths with `#[AsOpenApiMiddleware]` where they need
protection (see [Middleware groups](middleware.md#middleware-groups)).

The document contains:

- every route, with its path and query parameters and their constraints
  as schema keywords;
- each `#[Body]` DTO as a `requestBody` schema, stored once per class
  under `components/schemas`;
- the method's return type as the default response, and each
  `#[Response]` as an additional status.

`#[Regex]`, `#[NotBlank]`, `#[FileSize]` and `#[FileExtension]` have no
JSON Schema equivalent. They are enforced, but not published, so the
document accepts more than the route does.

`#[Hidden]` leaves a route out of the document without changing how it
runs. On a controller class it hides every route of that class:

```{code-block} php
use Kinetis\Http\Attributes\{Get, Hidden};

#[Hidden]
final readonly class InternalController
{
    #[Get('/internal/status')]
    public function status(): array { /* ... */ }
}
```

In development the document is rebuilt on every request; in production
it is built once per process. The schema mapping is in [OpenAPI
generation](appendix-routing-validation.md#openapi-generation).

## Grouping routes under a prefix

`#[RoutePrefix]` prepends a path to every route of a controller. Combined
with a trait, one set of route methods can be mounted at a different path
by each controller that uses it:

```{code-block} php
use Kinetis\Http\Attributes\{Get, RoutePrefix};

trait CrudRoutes
{
    #[Get('/')]
    public function index(): array { /* ... */ }

    #[Get('/{id}')]
    public function show(int $id): array { /* ... */ }
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

That registers `/users`, `/users/{id}`, `/orders` and `/orders/{id}`.
Share route methods through a trait, not a base class: a routed method
inherited from a parent is rejected at registration. A middleware class
can also carry a prefix; see [Sharing routes across
controllers](appendix-routing-validation.md#sharing-routes-across-controllers).

## See also

- {doc}`middleware` — authentication, CORS, rate limits and the
  validation response around your routes.
- {doc}`appendix-routing-validation` — the complete matching, binding,
  validation and OpenAPI rules.
- {doc}`container` — how controller constructor dependencies are
  resolved.
- {doc}`testing` — sending requests to these routes from a test.
- {doc}`caching` — how routes and validation plans are compiled for
  production.
