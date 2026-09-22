# Appendix: Routing & Validation

The route, binding, validation and OpenAPI rules behind
{doc}`routing-validation`. That guide covers declaring routes, binding and
validating input, responses and the generated document; this page is the
reference it links to.

## Route registration and matching

### Route attributes

`#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]` and `#[Delete]` implement one
`RouteAttribute` interface (`httpMethod()`, `path()`, `status()`).
`Router` finds route attributes through that interface rather than by
class name.

A method may carry more than one route attribute — `#[Get('/x')]` and
`#[Post('/x')]` on the same method register two independent routes
sharing that controller method and its middleware. Methods without a
route attribute are skipped, so a controller can mix routed actions with
plain helper methods. Each `{placeholder}` in a path template is compiled
to a named regex capture group once, when the route is registered — not
on every request.

### Path rules

**Every declared path must start with `/`** — a route path is absolute,
so `#[Get('users')]` is a typo rather than a shorthand and is rejected at
registration, as is `#[RoutePrefix('users')]`. The empty string is
rejected for the same reason: it would resolve to `/` and quietly claim
the root route.

Trailing slashes are normalised away, so every path is stored in one
canonical form. `#[Get('/users')]` and `#[Get('/users/')]` are the same
route, and declaring both is a duplicate. `/` itself is unchanged.

The request path goes through the same rule, so a request for `/users/`
reaches a route registered as `/users` and binds path parameters exactly
as it would without the slash. Both URLs serve the response directly
rather than redirecting; a `301` to the canonical form belongs in front
of the application.

### What a placeholder matches

A path template describes URL structure and nothing else. `{id}` occupies
one whole segment and matches any run of characters up to the next `/`;
there is no inline syntax for narrowing that. What a captured value may
hold is described where the value is consumed — by the controller
parameter's own type and its validation attributes:

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

### Which route wins

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
always wins for a path it also matches.

A second route claiming *exactly* the same requests (the same method and
path shape — placeholder names don't count, so `/users/{id}` and
`/users/{userId}` collide) is rejected at registration with a
`DuplicateRouteException`, since it could never run at all.

### Registering a controller

Registering a controller is all-or-nothing: every one of its routes is
reflected and checked for conflicts before any of them are committed,
so a later method's bad path, or a conflict against an earlier one,
leaves none of that controller's routes registered.

Registering the same class a second time is a no-op *only* when the
global-middleware context is identical to the one already used. That is
what keeps a class discovered through more than one scan (a project's own
scan overlapping a package's `extra.kinetis` root, for instance) from
registering its routes twice, since every such scan shares the one
project-wide global-middleware list. A second registration under a
different context is rejected instead of silently kept under the first
one.

## Sharing routes across controllers

`#[RoutePrefix]` is resolved when the route is registered, so everything
downstream sees the finished path: duplicate detection, the compiled
cache, the OpenAPI document and `kinetis routes:list`. Two controllers
sharing one trait under different prefixes therefore don't collide, while
two under the *same* prefix are rejected as duplicates, exactly as if the
paths had been written out by hand. A route declaring `/` sits at the
prefix itself.

A trait is the way to share route methods — not a base class. An
attribute is only ever read from the class it is written on, so a routed
method inherited from a parent is rejected at registration; see
[Where attributes are read from](cli.md#where-attributes-are-read-from).

(a-middleware-can-own-a-prefix-too)=
### A middleware can own a prefix too

`#[RoutePrefix]` also reads from a *middleware* class, not just a
controller — the shape API versioning takes, where the prefix and a piece
of version-related behavior belong to the same class:

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
    public function index(): array { /* ... */ }
}
```

`/users` becomes `/v1/users`, with no change to `UserController` itself —
every controller referencing `VersionMiddleware` moves together the next
time its `#[RoutePrefix]` changes. The same is true of a middleware
discovered as {doc}`global <middleware>` (`#[AsGlobalMiddleware]`): its
prefix applies to every route in the project, ahead of everything else.

A route's final path composes outer to inner, in the order the middleware
itself runs: the global-middleware chain first (priority order), then the
route's own `#[Middleware(...)]` chain (class-level before method-level —
see {ref}`route middleware <route-middleware>`), then the controller's
own `#[RoutePrefix]`, then the route's own declared path. Declaration
order decides both what runs first *and* which segment lands leftmost, so
two controllers referencing the same prefixed middlewares in a different
order end up with different URLs.

A middleware referenced only through a `#[Middleware('@name')]` group
never contributes a prefix this way: group membership isn't resolved
until `Kernel` is constructed, after routing has already produced the
final path.

## Parameter binding

### Binding order

A controller method's parameters are resolved from six possible sources.
`Dispatcher` checks them in a fixed order, and the first that claims a
parameter wins:

1. A `ServerRequestInterface`-typed parameter.
2. An `UploadedFileInterface`-typed parameter.
3. `#[Body]`.
4. `#[Query]`.
5. A name matching a `{placeholder}` in the route's path template.
6. Any other class-typed parameter, resolved from the request container.

The two type-matched sources come first, so neither an attribute nor a
placeholder name can shadow them; the container comes last, so it can
never shadow `#[Body]`, `#[Query]`, or a path placeholder. Binding runs
before the controller is resolved, so a request refused with a `400`, a
`415` or a validation failure never constructs the controller, and no
constructor or registered factory runs on its behalf.

A `ServerRequestInterface` parameter bypasses `#[Body]`'s decoding
entirely, for anything that needs the request itself: a raw body stream,
headers, a content type `#[Body]` does not read.

### Reading the DTO from one top-level member

A wire contract that wraps its payload in a named member —
`{"article": {...}}` — names that member as the attribute's root, and the
DTO is hydrated from the member alone. `CreateArticle` and
`ArticleResponse` stand for the application's own classes:

```{code-block} php
#[Post('/articles')]
public function create(#[Body('article')] CreateArticle $article): ArticleResponse
```

A root is exactly one top-level member name. It is never inferred from
the parameter name and it is not a path: `#[Body('data.article')]` names a
member spelled `data.article`. An empty root, and a second `#[Body]`
parameter on the same method, are refused with an
`UnresolvableParameterException` when the route registers — a request
carries one document, and every `#[Body]` parameter validates its outer
object as its own.

The document around the root is read exactly as an unrooted one is: the
media type decides how it is decoded, uploaded files are merged into a
form body first, and a malformed JSON document, or one whose outer value
is not an object, is still a `400`. The root member then reports what a
nested DTO field reports (see [Nested DTOs](#nested-dtos)), under the
root's path:

| Request | Violation |
|---|---|
| a blank body, or no `article` member | `required` at `["article"]` |
| `"article": null` | `null_not_allowed` at `["article"]` |
| `"article": "text"` | `type_mismatch` at `["article"]` |
| `"article": []` | `not_a_json_object` at `["article"]` |
| an invalid `title` inside it | that field's own code at `["article", "title"]` |

A JSON document is closed around its root as a DTO's own object is:
every other top-level member is `unexpected_field` at its own path,
reported after the root's own failures. A form-encoded or multipart body
stays open, so a CSRF token or a submit button's name beside the root is
accepted. See [Unknown members are rejected for
JSON](#unknown-members-are-rejected-for-json) for both policies, and
[OpenAPI generation](#openapi-generation) for the published document.

### Uploaded files as parameters

An `UploadedFileInterface`-typed parameter is resolved from the request's
uploaded files by parameter name. A request without the expected file —
including one whose file control the user left empty — resolves like a
missing `#[Query]` value: the parameter's default if it has one, `null`
if its type allows null, and a `422` (`is required.`) otherwise. A file
that is present runs the parameter's own `Constraint` attributes, after
its transport status has been checked (see [Uploads](#uploads)); the
parameter itself stays outside the generated OpenAPI document.

### Class-typed parameters

A class-typed parameter matching no other source is resolved from the
request container. Anything the container can supply works — a
repository, a `MailerInterface`, whatever a package bootstrap bound — and
a dependency only one route needs is built only for that route, instead
of on every request to the class.

If nothing can supply the parameter, the failure surfaces: a route that
forgot the middleware meant to register the value fails loudly rather
than handing the controller something disconnected. A default value, or
a nullable type, says that absence is acceptable instead. That covers
absence only — an id the container could never supply. Everything else,
a registered service that failed to construct included, is a defect and
is reported rather than quietly arriving as `null`; {doc}`container`
states the rule constructor autowiring and `Dispatcher` share.

A class-typed parameter that *is* claimed by another source is decided
by that source instead: `#[Body]` hydrates the class from the request
body, and a `#[Query]` or path parameter admits one class only, a backed
enum — see [Enum path and query
parameters](#enum-path-and-query-parameters).

```{note}
This applies to HTTP controllers. An MCP tool's arguments arrive as one
flat object, so a class-typed parameter there is a DTO hydrated from
those arguments — see {doc}`mcp`.
```

### Enum path and query parameters

A `#[Query]` or path parameter typed as a backed enum binds the case its
backing value names. `Dispatcher` resolves it through the same
`Hydrator::resolveEnumValue()` a [backed enum field](#backed-enum-fields)
goes through, so an HTTP parameter and a DTO field answer by one set of
rules:

- The wire text is resolved as the enum's backing type first, under the
  raw-string rules below. `?priority=urgent` on an `int`-backed enum is
  therefore the ordinary `not_an_integer` violation — the value named no
  case of anything — while `?priority=9` is an `enum_case` violation
  carrying `{"choices": [1, 2, 3]}`.
- The lookup is `tryFrom()` on that backing value, so matching is exact.
  A `string`-backed `asc` case is not named by `ASC`.
- A nullable parameter with no matching key binds `null`; one with a
  default binds that default case; one with neither is required. A path
  segment is always present.
- Constraint attributes run against the resolved case, as they do on a
  DTO field.

Every other class type on a `#[Query]`/path parameter is refused when
the route's binding plan is derived, which `Router::register()` does
eagerly — so the route never registers, is never advertised at
`/openapi.json`, and never accepts traffic. A unit enum's cases have no
backing value, and an interface, an abstract class or a DTO has no wire
spelling a single query value or path segment could carry;
`Kinetis\Http\Exception\UnresolvableParameterException` names the
parameter, its source and the class, and points at `#[Body]`, where a
DTO field may declare a class type.

The parameter's compiled binding plan records the enum as `enumClass`
and its backing type as `scalarType`, the same pairing a DTO field's
hydration plan uses. Both are plain strings, so the plan is written into
`.kinetis-cache/compiled.php` unchanged — see {ref}`the AOT artifact
<runtime-reference-aot-artifact>`.

The generated document publishes the enum's own domain for that
parameter: the backing scalar's JSON type and the exact `enum` of its
case values, widened with `null` where the declaration admits it.

### Parameters no source claims

A parameter matching none of the six — untyped, or scalar-typed with no
attribute and no matching placeholder — falls back to its default value
if it has one, and otherwise fails with an
`UnresolvableParameterException` naming every source it could have come
from, rather than passing `null` silently. Not every default can be
captured for reuse — see [Default values a plan
captures](#default-values-a-plan-captures), which applies to a controller
parameter and a DTO field alike.

### Query and path values are raw strings

A `#[Body]`/MCP value is already a real, JSON-decoded PHP value (a
genuine `bool`, `array`, ...); a `#[Query]`/path value only ever arrives
as a raw string (or, for a `#[Query]` array-style parameter —
`?tags=a&tags=b` — a list of them). `Dispatcher` therefore resolves both
under `InputSource::Text`, always, whatever the request body's own
content type says. Several consequences follow:

- **`bool` accepts the OpenAPI-documented `"true"`/`"false"` spelling
  too, not just `"1"`/`"0"`.** Once the type check has accepted one,
  `Text` translates those two literal spellings into real PHP booleans,
  so the `(bool)` cast never meets the string `"false"`, which would
  cast to `true`.
- **A numeric string binds an `int`/`float` parameter**, since text is
  the only spelling a query string or a path segment has. The same
  string in a JSON body does not; see [Scalar type
  checking](#scalar-type-checking).
- **A `#[Query]`/path parameter typed outside the supported set is
  rejected at registration**, not at request time — see [Builtin types
  outside the supported set](#builtin-types-outside-the-supported-set).
  A class type is refused there too, a backed enum excepted; see [Enum
  path and query parameters](#enum-path-and-query-parameters).
- **A `#[Query]`/path parameter declaring a union or intersection type
  is rejected at registration.** One request value has one shape, and
  the `T|Absent` presence union a DTO field may declare needs a member
  that is either present or absent, which a query key is not.
- **An `array`/`iterable`-typed path parameter is rejected at
  registration too, unconditionally.** A route placeholder is always
  exactly one path segment — `Route::match()` captures it as a single
  string — so move it to `#[Query]` (where an array-style parameter is
  representable) or `#[Body]` instead.
- **A `#[Query]` array-style parameter accepts OpenAPI 3.1's default
  query-array serialization** (`style: form`, `explode: true` — never
  stated explicitly in the generated document, since it is the spec
  default): the repeated-key spelling, `?tags=a&tags=b`. This is what a
  client generated from `/openapi.json` sends, and it is the only form
  that binds — parsed from the request's raw query string, since PHP's
  `parse_str()` (what `getQueryParams()` is built from on every runtime)
  collapses a repeated, non-bracketed key to its last value. PHP's
  bracket spelling, `?tags[]=a`, sends a different key — the name on the
  wire is `tags[]`, not `tags` — so it satisfies no `#[Query] array
  $tags` parameter: the value is missing, and the parameter's own
  default, `null`, or an `is required.` `422` follows, exactly as for a
  request that never mentioned the key.

(multipart-form-data-file-uploads)=
## Request bodies

### Media types

`Dispatcher` picks how to read a `#[Body]` from the request's
`Content-Type`:

| Content-Type | Read from |
|---|---|
| `application/json`, or an `application/*+json` subtype | `json_decode()` on the raw body |
| `multipart/form-data` | `getParsedBody()` |
| `application/x-www-form-urlencoded` | `getParsedBody()` |

A nonblank body under any other media type — or under no `Content-Type`
at all — is refused with a `415` before the DTO is hydrated and before
the controller is constructed, so a handler never receives bytes read
under a header that did not describe them. The error names the supported
media types and never echoes the one received. A blank or
whitespace-only body is a document with no members whatever the header
says, so an all-optional unrooted DTO still hydrates from its own
defaults.

A `Content-Type` is matched on its type and subtype alone — everything
before the first `;`, so a `charset` or a multipart `boundary` parameter
changes nothing — and compared ASCII-case-insensitively, as RFC 9110
§8.3.1 requires: `Application/X-WWW-Form-Urlencoded; charset=UTF-8`
lands on the same row as `application/x-www-form-urlencoded`. The match
is exact on the subtype apart from RFC 6839's `+json` suffix, so a
longer media type that merely begins with a listed one —
`application/x-www-form-urlencodedevil` — names none of these rows and
is refused. `Kinetis\Http\MediaType` is that classification, and the one
place a `Content-Type` is read — by `Dispatcher` here, and by the
Kernel's own `RequestBodyMiddleware` before it — so an application gets
the same answer under every runtime (see {doc}`runtime-adapters`).

### Empty and malformed JSON bodies

An empty body is treated as a document with no members — the same outcome
a `{}` body produces. A non-empty body must be a JSON object: one that
isn't valid JSON, or that decodes to anything else (a top-level JSON
array, `null`, a bare string, a number, a boolean), is a `400` before any
field-level validation runs. That check belongs to the decoder, which is
where the object/array distinction still exists:

```{code-block} json
{
    "error": "Request body is not valid JSON."
}
```

### Form field names and limits

Field names nest the way PHP's own parser nests them, under every
runtime: `user[address][city]` builds nested arrays, `tags[]` appends, a
repeated plain name replaces, and repeated or nested file names build the
same tree in `getUploadedFiles()`. How large and how complicated a form
may get is bounded by `Kinetis\Http\Form\FormLimits` — input variables,
file parts, nesting depth, multipart part and header counts, and total
bytes — identically under all four adapters, because one middleware
inside the Kernel applies them; a form past any of those is refused with
a `413` before the handler runs, never handed on with the over-limit
fields missing. See "Request bodies: one contract under every runtime"
in {doc}`runtime-adapters` for the numbers.

An adapter delivers raw bytes the runtime never parsed — `php://input`
under the SAPI adapters, which require `enable_post_data_reading=0`, the
event body under `kinetis/bref-adapter`'s `BrefLambdaAdapter`, and the
`http.raw_body: true`-preserved body under `kinetis/roadrunner-adapter`'s
`RoadRunnerAdapter` — and the Kernel's own `RequestBodyMiddleware` fills
the uploaded-files bag from them through `Kinetis\Http\Form`. There is
one parse, under every runtime and for every method a form can arrive
on.

### Form-encoded and multipart bodies get the raw-string rules too

A `multipart/form-data`/`application/x-www-form-urlencoded` `#[Body]` is
read from `getParsedBody()`, not JSON-decoded — every field arrives the
way a `#[Query]`/path value does: a raw string (or a real PHP array, for
a repeated/bracketed field name), never an already-typed JSON value. The
same `#[Body]` parameter type can receive either encoding depending on
the client's own `Content-Type`, so `Dispatcher` applies the textual
rules only when the request is form-encoded, never to a JSON
request reaching the identical DTO.

Every `#[Body]`-reachable route whose DTO declares no uploaded file
advertises `application/x-www-form-urlencoded` and `multipart/form-data`
in its generated `requestBody` alongside `application/json` — the
identical schema under all three, since `Dispatcher` hydrates the same
DTO class regardless of which the client sent. A DTO that *does* declare
an upload advertises `multipart/form-data` alone, since neither of the
other two can carry a file.

- Every field is read under `InputSource::Text`, the vocabulary
  `#[Query]`/path values use, so `bool` accepts both the `"1"`/`"0"` and
  the `"true"`/`"false"` spelling and `int`/`float` accept their textual
  ones. The source is the *request's*, not the route's: a real JSON
  request for the identical DTO class still rejects the JSON *string*
  `"true"` and the JSON string `"3"` for an `int` field. It reaches a
  list's own scalar elements, and a nested or `#[ListOf]` DTO's own
  scalar fields, via PHP's bracket-style `field[sub]=value` convention.
- `array`/`iterable` get the identical map-shaped-value rejection
  documented for JSON (a form-encoded field parsed into an associative
  PHP array is rejected the same way a JSON object is), but the
  numeric-keyed-object provenance tracking `JsonTree` provides for a JSON
  body does not apply — a form-encoded array field's shape comes directly
  from PHP's parsed-body array.
- An `UploadedFileInterface`-typed field is described as `{type: string,
  format: binary}` — OpenAPI's convention for a file upload inside a
  multipart-serialized schema — and is satisfiable only via
  `multipart/form-data`.

## Uploads

A `#[Body]` DTO can mix ordinary fields with an
`UploadedFileInterface`-typed constructor parameter. Validation
constraints work identically on a multipart-bound DTO's ordinary fields
as on a JSON one — `Hydrator` never knows which content type produced
the data it validates.

### An empty file control is an omitted field

A browser submits a file input the user left alone as a *present* part
carrying `UPLOAD_ERR_NO_FILE`, not as nothing at all. Binding reads that
as ordinary omission, exactly as it reads a text field the form never
sent:

- a defaultless field or parameter reports `is required.`;
- one with a default takes its default;
- a nullable one binds `null`;
- an `UploadedFileInterface|Absent` DTO field binds `Absent::Value`, so
  an update DTO can tell "no file was chosen" from "this file was
  cleared". See [Required, optional, and absent
  fields](#required-optional-and-absent-fields).

The same pruning runs at every depth. A branch left with nothing in it
is omitted in turn, so a repeated control whose every part was empty is
an absent field rather than a supplied empty list — `[]` is a list the
client sent, which a `#[MinItems]` rule would then measure.

This is what *binding* reads. The PSR-7 request keeps the bag its
runtime adapter built, `UPLOAD_ERR_NO_FILE` parts included, so
middleware and a `ServerRequestInterface`-typed parameter still see
exactly what arrived.

### Nested and repeated file controls

Files nest and repeat under the same bracket convention text fields do,
and the two trees are merged into one set of fields before the DTO is
hydrated. `profile[name]` arrives as text and `profile[avatar]` as a
file; both hydrate the same nested DTO:

```{code-block} php
final readonly class ProfileDetails
{
    public function __construct(
        public string $name,
        public UploadedFileInterface $avatar,
    ) {}
}

final readonly class ProfileUploadRequest
{
    public function __construct(
        public ProfileDetails $profile,
    ) {}
}
```

At each key: two arrays merge, and anything else leaves the parsed text
in place. A form naming one key as both a text value and a file has
contradicted itself, so the text stays and the field reports the
ordinary declared-type violation any other wrong value would get. A JSON
body never consumes uploaded files at all — a JSON document names every
value it sends.

A repeated control is a `#[ListOf]` field naming
`UploadedFileInterface`, the one interface `#[ListOf]` admits:

```{code-block} php
use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\{Each, ListOf};

final readonly class PhotoUploadRequest
{
    public function __construct(
        #[ListOf(UploadedFileInterface::class)]
        #[Each(FileExtension::class, ['png'])]
        public array $photos,
    ) {}
}
```

A *flat* repeated control closes its indices back up after empty parts
are dropped: `photos[]` sent as file, empty, file binds two files at
`0` and `1`. An index there is nothing but position among files, so the
elements a handler receives are the files that arrived, in order, with
no holes.

A list of *nested DTOs* keeps its positions instead, because there an
index is the one the parsed text names too:

```{code-block} php
final readonly class GalleryEntry
{
    public function __construct(
        public string $caption,
        public ?UploadedFileInterface $image = null,
    ) {}
}

final readonly class GalleryUploadRequest
{
    public function __construct(
        #[ListOf(GalleryEntry::class)]
        public array $entries,
    ) {}
}
```

Three captioned entries whose middle `entries[1][image]` control was
left empty bind the second entry without an image and leave
`entries[2][image]` on the third entry, where the client put it.
Closing those indices up would merge the third file into the second
entry's caption, and an optional field would hydrate that misbinding
without a violation. Map-shaped branches keep their keys as well, which
are field names a DTO declares rather than positions.

### A file that did not arrive

Every typed route into an uploaded file — a DTO field, a `#[ListOf]`
element, a direct parameter — checks the part's PSR-7 status before
anything else looks at it. A status other than `UPLOAD_ERR_OK` is one
violation at the field's own path:

```{code-block} json
{
    "path": ["photos", 1],
    "code": "upload_failed",
    "message": "could not be uploaded.",
    "parameters": {"error": 3}
}
```

`parameters.error` is the raw `UPLOAD_ERR_*` constant, for a log to
read. No rule on the field runs, and nothing opens the stream — a failed
part has no readable stream, so a rule that ran would raise a `500` for
what is a client-visible transfer failure. `UPLOAD_ERR_NO_FILE` never
reaches this point: it is already omission, above.

### Rules about an uploaded file

Two built-in rules describe a file, on a DTO field, on a `#[Each]` of an
upload list, or on a direct controller parameter:

```{code-block} php
use Kinetis\Validation\Constraints\{FileExtension, FileSize};

#[Post('/scans')]
public function scan(
    #[FileSize(maxBytes: 5_000_000, minBytes: 1)]
    #[FileExtension(['png', 'jpg'])]
    UploadedFileInterface $file,
): array
```

`#[FileSize]` bounds the size the part itself reported, inclusive on
both ends. PSR-7 permits a null size, and a bound cannot be checked
against a size that does not exist, so such a file fails closed with
`file_size_unknown` rather than passing unchecked.

`#[FileExtension]` is a policy about the *client-supplied filename*, an
untrusted label the server has not verified. It is not a MIME type and
it is not sniffed content: a name ending in `.png` says nothing about
the bytes behind it. A suffix is written without its leading dot and may
contain dots of its own, so `png` and `tar.gz` are both declarable, and
each matches the end of the name after the dot that separates it —
`archive.tar.gz` satisfies `gz` and `tar.gz` alike, while a name with no
separating dot satisfies neither. Matching is ASCII case-insensitive, so
`PHOTO.PNG` satisfies `png`. The name never appears in the violation:
the message and its `choices` parameter carry the declared suffixes,
which the server wrote.

Neither rule opens the file, and Kinetis validates no upload's contents
anywhere: content checks belong where the content is read.

### Uploads in the generated document

An `UploadedFileInterface` field is published as
`{"type": "string", "format": "binary"}` — OpenAPI's convention for a
file inside a multipart-serialized schema — and a `#[ListOf]` of them as
an `array` of that item. A `#[Body]` DTO that declares an upload
anywhere in it, on a nested DTO or a list element included, advertises
`multipart/form-data` **alone**: only a multipart body can carry a file,
and a JSON or urlencoded entry beside it would describe a request that
cannot hydrate the DTO it names. An upload-free DTO keeps all three
encodings it accepts.

A direct `UploadedFileInterface` *parameter* is outside generated input
metadata entirely — it is not a request body, and none is synthesized
for it. An application that needs its upload input documented declares
the file as a field of a `#[Body]` DTO.

## Validation

### Validation constraints

Each rule owns three things: what it checks, the stable `code` its
violation carries, and the JSON Schema keyword that states the same rule
to a client reading the generated document. Every rule lives in
`Kinetis\Validation\Constraints`.

| Attribute | Checks | Constructor | Violation code | Schema keyword |
|---|---|---|---|---|
| `#[Email]` | `filter_var($value, FILTER_VALIDATE_EMAIL)` | *(no arguments)* | `email` | `format: email` |
| `#[NotBlank]` | not empty or all-whitespace after `trim()` | *(no arguments)* | `not_blank` | *(none)* |
| `#[MinLength(n)]` | `mb_strlen($value) >= n` | `int $length` | `min_length` | `minLength` |
| `#[MaxLength(n)]` | `mb_strlen($value) <= n` | `int $length` | `max_length` | `maxLength` |
| `#[GreaterThan(n)]` | `$value > n` | `int\|float $threshold` | `greater_than` | `exclusiveMinimum` |
| `#[LessThan(n)]` | `$value < n` | `int\|float $threshold` | `less_than` | `exclusiveMaximum` |
| `#[GreaterThanOrEqual(n)]` | `$value >= n` | `int\|float $threshold` | `greater_than_or_equal` | `minimum` |
| `#[LessThanOrEqual(n)]` | `$value <= n` | `int\|float $threshold` | `less_than_or_equal` | `maximum` |
| `#[MultipleOf(n)]` | `$value % n === 0` | `int $divisor` | `multiple_of` | `multipleOf` |
| `#[Regex($pattern)]` | `preg_match($pattern, $value) === 1` | `string $pattern` | `regex` | *(none)* |
| `#[In($choices)]` | `in_array($value, $choices, true)` | `array $choices` | `in` | `enum` |
| `#[NotIn($choices)]` | `!in_array($value, $choices, true)` | `array $choices` | `not_in` | `not: {enum: ...}` |
| `#[MinItems(n)]` | a list of at least `n` elements | `int $count` | `min_items` | `minItems` |
| `#[MaxItems(n)]` | a list of at most `n` elements | `int $count` | `max_items` | `maxItems` |
| `#[Url]` | `filter_var($value, FILTER_VALIDATE_URL)` | *(no arguments)* | `url` | `format: uri` |
| `#[Uuid]` | RFC 9562's UUID string form | *(no arguments)* | `uuid` | `format: uuid` |
| `#[Ip]` | `filter_var($value, FILTER_VALIDATE_IP)` | *(no arguments)* | `ip` | `anyOf` (`format: ipv4`, `format: ipv6`) |
| `#[Date]` | `YYYY-MM-DD` naming a real calendar day | *(no arguments)* | `date` | `format: date` |
| `#[DateTime]` | an RFC 3339 `date-time` | *(no arguments)* | `date_time` | `format: date-time` |
| `#[FileSize($max, $min)]` | an uploaded file's reported size, inclusive on both bounds | `int $maxBytes, int $minBytes = 0` | `file_too_large`, `file_too_small` | *(none)* |
| `#[FileExtension($extensions)]` | an uploaded file's client filename ends in one of them | `array $extensions` | `file_extension` | *(none)* |

`#[GreaterThan]`, `#[LessThan]`, `#[GreaterThanOrEqual]` and
`#[LessThanOrEqual]` report `not_a_number`, `#[MultipleOf]` reports
`not_an_integer`, `#[MinItems]`/`#[MaxItems]` report `not_a_list`, and
`#[FileSize]`/`#[FileExtension]` report `not_a_file`, for a value of the
wrong shape entirely. Through a request that never happens — the
declared type is checked before any rule runs — but a rule invoked
directly still answers rather than counting something with no count. The
string rules have no such second code: `#[Email]`, `#[Url]`, `#[Uuid]`,
`#[Ip]`, `#[Date]` and `#[DateTime]` each fold a non-string into their
own code.

`#[MultipleOf]` is about whole numbers on both sides. Its divisor is an
`int` of at least 1, and a `float` value reports `not_an_integer` rather
than being tested: divisibility in binary floating point would need a
tolerance the published `multipleOf` does not carry. Zero and negative
multiples satisfy it.

`#[Date]` and `#[DateTime]` read a fixed ASCII grammar and then check the
numbers, rather than handing the value to a PHP date parser — which would
accept `2024-1-1`, roll `2023-02-29` forward into March, and apply a
local timezone the request never mentioned. `#[Date]` is `YYYY-MM-DD`
alone, so its years run `0001` through `9999`. `#[DateTime]` is RFC 3339's
`date-time` with two reductions: a leap second (`:60`) and the space
separator RFC 3339 permits in place of `T` are both rejected, the first
because nothing downstream of a validated string can place one, the
second because admitting two spellings under one `format: date-time`
would publish a document broader than the check. An offset is required —
`Z`, `z`, or a signed `HH:MM`, `-00:00` included — a fraction of any
length is accepted, and a trailing newline is not part of either value.

`#[Uuid]` is RFC 9562's string form and nothing more: 32 hexadecimal
digits in 8-4-4-4-12 groups, upper or lower case. Version and variant are
fields of the identifier rather than of its spelling, so the nil and max
UUIDs and versions 6, 7 and 8 all bind.

`#[Ip]` accepts an address of either family and nothing around it: a zone
identifier, a CIDR prefix, bracket notation and surrounding whitespace
are each something other than an address, and none of them binds.

`#[Regex]` and `#[NotBlank]` are runtime-only: neither has an equivalent
JSON Schema keyword. `pattern` holds an undelimited ECMA-262 expression, a
different dialect from the delimited PHP PCRE `#[Regex]` takes, and no
keyword carries `#[NotBlank]`'s trim-aware blank-string semantics —
`minLength: 1` rejects the empty string, not `"   "`. For those two a
generated OpenAPI or MCP schema is broader than the check the request
gets.

`#[FileSize]` and `#[FileExtension]` publish nothing for a different
reason: both describe a multipart part, which the document represents as
`{"type": "string", "format": "binary"}`. JSON Schema's string keywords
measure that string's own characters and say nothing about the bytes of
the part or the name the client attached to it, so publishing one would
state a rule the request is not checked against. Every other constraint
in the table maps onto a keyword; see [OpenAPI
generation](#openapi-generation).

A rule's constructor arguments reach a client twice — as a violation's
`parameters` and as a published schema keyword — so a definition with no
truthful form in either is refused with an `InvalidArgumentException`
where the constraint is first instantiated, during hydration or schema
generation: a negative `#[MinLength]`, `#[MaxLength]`, `#[MinItems]` or
`#[MaxItems]` bound; a non-finite threshold on any of the four numeric
bounds; a `#[MultipleOf]` divisor below 1, since zero divides nothing and
a negative divisor names the same multiples as its absolute value while
publishing a `multipleOf` JSON Schema does not allow; an `#[In]` or
`#[NotIn]` set that is empty, keyed, or carries a non-scalar or
non-finite member; a `#[FileSize]` with a negative bound or a minimum
above its maximum; a `#[FileExtension]` set that is empty or keyed, or
whose members are not alphanumeric suffixes written without a leading
dot (`png`, `tar.gz`); and a `#[Regex]` pattern PCRE cannot compile,
which left to run would match nothing and reject every value the field
ever receives.

Two rules on the same field may not contribute the *same* keyword.
`#[MinLength(3)] #[MinLength(5)]` states two different minimum lengths
and only one of them could be published, so generating the schema fails
with `Exception\JsonSchemaException` rather than letting declaration
order decide which bound a client is told about.

A rule may not restate the shape the PHP declaration itself owns,
either. The declared type is what `Hydrator` checks a value against, so
a rule contributing `type` — or `items`, on a `#[ListOf]` field — would
publish a shape no request is held to, and `Exception\JsonSchemaException`
refuses that declaration for the same reason. A rule refines the
declared shape (`#[MinLength(3)] string` narrows which strings bind); it
cannot replace it. What a rule nests *inside* its own keyword is never
inspected.

`#[MinLength]`/`#[MaxLength]`, `#[GreaterThan]`/`#[LessThan]`,
`#[GreaterThanOrEqual]`/`#[LessThanOrEqual]` and
`#[MinItems]`/`#[MaxItems]` compose on the same field for a length,
numeric or cardinality range — `Hydrator` runs every
`Constraint`-implementing attribute on a parameter, not just the first
one.

`#[MinItems]`/`#[MaxItems]` bound a list-shaped `array` field: a plain
one, or a [`#[ListOf]`](#typed-collections) one whose elements are
counted once they have hydrated. The list-shape check itself stays
`Hydrator`'s, so a field that isn't a JSON array fails there first and
never reaches the bound. A rule about a list's *elements* rather than
the list itself is declared with [`#[Each]`](#a-rule-for-every-element).

`Hydrator::hydrate()` checks **every** constrained field before
constructing the DTO, so a request with three invalid fields gets all
three violations back in one [RFC 9457][rfc9457] problem document. The
response is produced at the terminal boundary, so an application can
replace it — see [Rendering validation
failures](middleware.md#rendering-validation-failures).

[rfc9457]: https://www.rfc-editor.org/rfc/rfc9457.html

### Scalar type checking

Before a value is cast to a `#[Body]` field's, `#[Query]` parameter's, or
path parameter's declared scalar type, its actual shape is checked
first — casting only ever happens once that check passes. Every source of
typed input enters the same method, `Hydrator::resolveScalar()`: a
`#[Body]` DTO field, a `#[Query]`/path parameter, and an MCP tool's own
top-level argument, which `Kinetis\Mcp\McpDispatcher` hands to it
directly.

A request value binds to one of seven builtin types: `string`, `int`,
`float`, `bool`, `array`, `iterable`, `mixed`. Every other builtin —
`null`, `true`, `false`, `object`, `callable` — is a definition error, not
a runtime one: see [Builtin types outside the supported
set](#builtin-types-outside-the-supported-set).

Which *spelling* satisfies a declared type depends on where the value
came from, because different sources can say different things. A JSON
document distinguishes `42` from `"42"`; a query string has no spelling
for a number other than its text. `Kinetis\Validation\InputSource` names
the three vocabularies, and the caller that read the bytes picks one:

| Source | Chosen for | Carries |
|---|---|---|
| `Json` | a JSON `#[Body]`, an MCP tool argument | already-decoded JSON values, each with its own type |
| `Text` | `#[Query]`, path segments, `application/x-www-form-urlencoded` and `multipart/form-data` bodies | raw strings only |
| `Native` | a direct `Hydrator::hydrate()` call — an array a service built itself | whatever PHP values the caller already holds |

All three agree on these:

- A `string`-typed field/parameter must be a string. An array, object,
  number, or boolean is rejected.
- An `array`-typed field/parameter (no `#[ListOf]`) must be a real JSON
  *array* (`[...]`), never a JSON object (`{...}`) — including the empty
  object `{}`, and including one whose own keys happen to look
  sequential (`{"0":"a","1":"b"}`). The request body is decoded with
  `json_decode(..., associative: false)`, not `true`, so this distinction
  survives: every JSON object anywhere in the body is marked before
  `array_is_list()` is consulted, so a map-shaped value — of any shape,
  empty included — is always rejected with its own message ("must be a
  JSON array, not a JSON object."). `#[ListOf]`'s own array gets the
  identical list-shape check. An `array` field that is meant to take a
  JSON object declares it — see [Object-map
  properties](#object-map-properties).
- An `iterable`-typed field/parameter gets the identical check as
  `array` — decoded input can only ever produce a PHP array, never a
  real `Traversable`, so the accepted shape and the wire contract are the
  same as `array`'s.
- `mixed` accepts anything, JSON `null` included. Its own JSON Schema is
  the empty schema *object* (`{}`), never a bare `[]`.

They differ on the numeric and boolean scalars:

- An **`int`** accepts, under `Json` and `Native`, a JSON integer (`42`)
  and a float with no fractional part (`42.0`) — JSON has one number
  type, so a producer writing an integer that way still wrote an integer
  — both inside PHP's native integer range. `Text` and `Native` accept
  an integer's textual spelling: a plain base-10 string (`"42"`,
  `"+42"`, `"-42"`), which under `Text` is the only spelling there is.
  `Json` accepts no string — the schema published for that field says
  `{"type": "integer"}`, and `"42"` is a string. Where a string is
  accepted it is read as written, never through a float, so a decimal
  spelling (`"42.0"`), an exponent spelling (`"4.2e1"`), a
  whitespace-padded one, and a value a `double` cannot tell apart from an
  integer (`"1.0000000000000001"`) are all rejected. So is a fractional,
  non-finite, or out-of-range number: the result is a `422` ("must be an
  integer within the platform integer range."), never a truncated cast —
  `4.5` does not become `4`. An array or a boolean is rejected under
  every source.
- A **`float`** accepts either JSON number under `Json` and `Native`,
  and a numeric string — `Text`'s only spelling — under `Text` and
  `Native`. It rejects any value that isn't finite (`"1e999"` overflows
  to `INF`). A non-numeric string, an array or a boolean is rejected
  everywhere.
- A **`bool`** is the JSON literal `true`/`false` under `Json` and
  `Native`, and `Native` adds `1`, `0`, `"1"`, `"0"`, which is what a
  `TINYINT(1)` column produces depending on the driver. `Text` has the
  four textual spellings OpenAPI documents and only those — `"true"`,
  `"false"`, `"1"`, `"0"`. Under `Json`, nothing but the literal: `"true"`
  and `1` are both a `422`.

Each source is held to its own domain rather than trusted to stay
inside it. `Text` carries raw strings, so a scalar declaration reading a
`Text` value binds a textual spelling and nothing else: a real PHP
`int`, `float` or `bool` is no more a `Text` value than `"42"` is a JSON
integer. (`array`/`iterable` still take a real array under `Text` — a
repeated key produces one — and `mixed` is unconstrained everywhere.)

A mismatch is a `422` carrying a violation at that field's own path, in
the same `errors` list a failed constraint produces — never a value
silently coerced into something that looks plausible (an array becoming
the string `"Array"`, a non-numeric string becoming `0`), and never a raw
`TypeError` escaping the constructor. Its `code` is `type_mismatch`, with
the declared and received type names in `parameters`. Every field's own
violations are collected before throwing once. An MCP tool's own
validation failure carries that same ordered `errors` list inside a
`tools/call` result's `isError: true` content — the violations, not the
HTTP problem document around them — rather than a JSON-RPC-level error;
see {doc}`mcp`.

### Builtin types outside the supported set

`null`, `true`, `false`, `object` and `callable` are rejected as
declarations, before any request reaches them. A JSON body decodes into
arrays and scalars, never a real PHP object; a query string and a path
segment carry text only; and a `callable`-typed parameter fed an
attacker-controlled string is an arbitrary-function-name-injection risk
if it is ever invoked downstream. None of them has a request value worth
supporting, so none of them is accepted anywhere a request value is
bound.

The rejection fires wherever the binding is described:

- A `#[Body]` DTO field is rejected with
  `Exception\UnsupportedDtoDefinitionException` when its hydration plan
  is compiled — at build time for an AOT-compiled plan, on the first
  hydration for a live one.
- A `#[Query]` or path parameter is rejected with
  `Kinetis\Http\Exception\UnresolvableParameterException` when the route's
  binding plan is derived, which `Router::register()` does eagerly — so
  the route never registers, is never advertised at `/openapi.json`, and
  never accepts traffic.
- An OpenAPI or MCP schema is refused with
  `Exception\JsonSchemaException`, since there is no shape a client could
  be told to send.

A controller parameter that reads no request value at all — one filled
from the request container or from its own default — is unaffected.

Class types meet the same registration-time boundary from the other
side: a `#[Query]`/path parameter admits a backed enum and nothing else,
and a `#[Body]` DTO field admits an instantiable class or a backed enum.
Each refusal has a schema counterpart in
`Exception\JsonSchemaException`, since a declaration neither can bind
has no shape to publish either.

### Required, optional, and absent fields

A constructor parameter with no default is required: omitting its member
is an `is required.` violation (code `required`), whether or not the
declared type accepts `null`. A field sent as `null` whose declared type
doesn't allow null is `must not be null.` (code `null_not_allowed`).
Both are violations at the field's own path, never a raw `TypeError`
from the constructor. A parameter with a default may be omitted and
receives that default, and no rule on it runs — an omitted default is
the application's own value, not something the client sent.

Nullability and required presence are independent: a nullable field with
no default (`?string $name`) still rejects an absent key, accepting only
one explicitly present and set to `null`. The generated OpenAPI schema
(and an MCP tool's `inputSchema`) states this exactly, listing that field
in `required` and giving it `type: ["string", "null"]`.

That leaves one question a default alone cannot answer. On an update, a
field the client did not mention and a field the client explicitly
cleared are different instructions, and `?string $bio = null` binds
`null` for both. `Kinetis\Validation\Absent` is the third state:

```{code-block} php
use Kinetis\Validation\Absent;
use Kinetis\Validation\Constraints\{MaxLength, NotBlank};

final readonly class UpdateArticleRequest
{
    public function __construct(
        #[NotBlank, MaxLength(255)]
        public string|Absent $title = Absent::Value,
        public string|null|Absent $summary = Absent::Value,
    ) {}
}
```

`$title` is `Absent::Value` when the member was omitted and the string
when one was sent; sending `null` is a `null_not_allowed` violation,
since the declared type does not name `null`. `$summary` adds `null` to
the union, so all three answers are available: omitted, cleared, or set.

Exactly two union forms are supported, `T|Absent` and `T|null|Absent`,
and the parameter must default to exactly `Absent::Value`. `T` is one
otherwise-supported field type — a scalar, a backed enum, a nested DTO,
a `#[ListOf]` list, an `#[ObjectMap]` — and a supplied value is checked
against it exactly as `T` alone would be. Anything else about the
declaration is a definition error; see [DTO definitions Kinetis
rejects](#dto-definitions-kinetis-rejects).

Nothing a client sends can produce the marker. It comes from the
parameter's own default and nowhere else, so an `Absent` value appearing
in hydrated data is read as the wrong type for that field, never as
omission. The generated schema describes `T` (widened with `null` only
where the union names it), never `Absent`, and leaves the member out of
`required` because the declaration carries a default.

This is a DTO constructor field's contract. A `#[Query]` parameter, a
path parameter and an MCP tool method parameter still reject every union
— an absent query key is already answered by that parameter's own
default. Create and update stay separate DTO classes: the HTTP method
never selects behavior, and one class never changes shape per verb.

`kinetis/query-builder` does not recognize `Absent`. To write such a DTO
to a table, leave its omitted fields out of the row first — see
{ref}`partial updates with RowValues <query-builder-partial-updates>`.

### Unknown members are rejected for JSON

A JSON request body, and an MCP tool call's arguments, describe an object
whose members are exactly the DTO's own. A member outside that set is
something the client believes it is sending and the application will
never read — a misspelling, a renamed field, a value meant for a
different endpoint — so it is an `is not expected.` violation on its own
path (code `unexpected_field`), reported alongside every other failure
the same request has:

```{code-block} json
{
    "errors": [
        { "path": ["email"], "code": "required", "message": "is required.", "parameters": {} },
        { "path": ["nmae"], "code": "unexpected_field", "message": "is not expected.", "parameters": {} }
    ]
}
```

Closure applies at every nesting level: a nested DTO's own object and a
`#[ListOf]` DTO element's are closed under their own paths, and a
`#[Body('root')]` document is closed around its root. An `#[ObjectMap]`
property stays open inside itself — arbitrary keys are what it accepts —
while the DTO holding it is closed like any other.

Form-encoded and multipart bodies are **not** closed. They legitimately
carry members that are not fields — a CSRF token, the submit button's own
name, a honeypot — and rejecting those would break input that is doing
nothing wrong. A direct `Hydrator::hydrate()` call is not closed either:
its `InputSource::Native` default exists for callers handing over PHP
values they already hold, such as an array wider than the DTO reading
it.

Every generated object schema states the closed contract with
`additionalProperties: false`, including a DTO with no fields at all.
That is the strict reading of the two closed sources; a form body is
more tolerant at runtime, so a client that sends only what the document
describes is accepted whichever source it writes in.

### Asymmetric-visibility properties

`Hydrator` and `Dispatcher` reason about a DTO through
constructor-parameter reflection, so PHP 8.4's asymmetric visibility
needs no special handling:

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

The property's visibility declaration is irrelevant to how it is bound
and validated — the constructor parameter is what both classes inspect.

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

A nested DTO's own validation runs the same way its parent's does — every
field, top-level and nested, is checked before construction, and a nested
field's violation carries the whole route to it as path segments:

```{code-block} json
{
    "errors": [
        {
            "path": ["shippingAddress", "street"],
            "code": "min_length",
            "message": "must be at least 3 characters.",
            "parameters": {"length": 3}
        }
    ]
}
```

A class-typed field accepts exactly two shapes and nothing else: an
object-shaped value, hydrated into the declared class; or a value that is
already an instance of that class, taken as given — most notably an
`UploadedFileInterface` merged in for a multipart field, which is
additionally checked for the transport status described in
[Uploads](#uploads). A scalar, a `null` for a non-nullable field, or an
object of some other class is a `422` at that field's own path, never a
raw `TypeError` from the constructor.

Object-shaped means a JSON object (`{...}`, including `{}`) or — for a
direct `Hydrator::hydrate()` call or a form-encoded body, neither of which
carries a JSON object/array distinction — a map-shaped PHP array. A JSON
array is not an object: `[]` and `[...]` are both a `422` ("must be a JSON
object, not a JSON array.") even for a class whose every field has a
default.

A field typed as a class that cannot be instantiated — an interface, an
abstract class, a unit enum — accepts only an existing instance: nothing
on the wire can construct one, so an array or a scalar for it is a `422`
("must be a `Psr\Http\Message\UploadedFileInterface` instance."). That
is how a `#[Body]` DTO's own file field works, since `Dispatcher` merges
the uploaded file in as an object. A *backed* enum is the one exception —
its cases have wire values — see [Backed enum fields](#backed-enum-fields).

### Backed enum fields

A constructor parameter typed as a backed enum takes the case its
backing value names:

```{code-block} php
enum Priority: int
{
    case Low = 1;
    case Normal = 2;
    case High = 3;
}

final readonly class CreateTicketRequest
{
    public function __construct(
        public string $subject,
        public Priority $priority,
        public ?Priority $escalation = null,
    ) {}
}
```

```{code-block} json
{ "subject": "Printer offline", "priority": 2 }
```

The wire value is the scalar the enum is backed by — a JSON number for
an `int`-backed enum, a string for a `string`-backed one — and it is
checked as that scalar before any case is looked up, so every rule in
[Scalar type checking](#scalar-type-checking) applies unchanged: a JSON
body refuses the string `"2"` where an `int`-backed enum is declared, a
query string or form body accepts it (text is all either carries), and
`2.0` binds because that is how a producer with one number type writes
the integer `2`.

A correctly typed value that names no case is a `422` at that field's
own path, carrying every backing value the enum has:

```{code-block} json
{
    "path": ["priority"],
    "code": "enum_case",
    "message": "must be one of: 1, 2, 3.",
    "parameters": {"choices": [1, 2, 3]}
}
```

A value that is already a case of that enum is taken as given, the way
a [class-typed field](#nested-dtos) takes an instance. Rules run against
the resolved case, not the backing value, so an application rule
declared on the field receives a `Priority`; its own schema keywords
merge beside the enum's `type` and `enum`, which — like any other
declared shape — a rule may not restate.

The generated document publishes both halves of the domain — the
backing `type` and the exact `enum` — and a nullable field widens both,
since `null` has to satisfy each:

```{code-block} json
{
    "priority": { "type": "integer", "enum": [1, 2, 3] },
    "escalation": { "type": ["integer", "null"], "enum": [1, 2, 3, null] }
}
```

A `#[Query]` or path parameter typed as a backed enum resolves through
this same path and publishes this same schema pair — see [Enum path and
query parameters](#enum-path-and-query-parameters). An MCP tool argument
typed directly as a backed enum still fails registration: a tool's
arguments are one flat object with no DTO to own the distinction. A
*unit* enum has no backing values at all, so a field declaring one stays
instance-only, exactly like an interface — the `BackedEnum` interface
itself included.

### Typed collections

A constructor parameter typed `array` and carrying `#[ListOf]` declares
what its elements are — `array` itself carries no element type for
Kinetis to reflect on. Four families are admitted, and the attribute
names one of them:

```{code-block} php
use Kinetis\Validation\ListOf;

final readonly class PublishRequest
{
    public function __construct(
        #[ListOf('string')]
        public array $tags,
        #[ListOf(Priority::class)]
        public array $priorities,
        #[ListOf(OrderItem::class)]
        public array $items,
    ) {}
}
```

- A **scalar** — `string`, `int`, `float` or `bool` — resolves each
  element exactly as a field of that type resolves its own value,
  including the source's own spellings.
- A **backed enum** resolves each element's backing value first and
  then the case it names, exactly as a
  [backed enum field](#backed-enum-fields) does.
- An **instantiable class** hydrates each object-shaped element into
  that class, or takes an element already an instance of it.
- **`Psr\Http\Message\UploadedFileInterface`**, and no other
  interface, takes each element as the uploaded file it already is —
  the repeated file control a `photos[]` form sends. See
  [Nested and repeated file controls](#nested-and-repeated-file-controls).

The field itself must be a real JSON array; a JSON object for it is the
same `422` a plain `array` field gets. No element is nullable — a list
declares one element type — so a `null` element is a violation at its
own index rather than a hole in the list.

```{code-block} php
use Kinetis\Validation\Constraints\{GreaterThan, MinLength};
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

Every element's own violations carry the field name, the element's
index as an integer segment, and — for a DTO element — the nested field
name, alongside every other violation in the same response:

```{code-block} json
{
    "errors": [
        {
            "path": ["items", 1, "quantity"],
            "code": "greater_than",
            "message": "must be greater than 0.",
            "parameters": {"threshold": 0}
        }
    ]
}
```

A scalar or enum element carries the same path without the third
segment — `["tags", 0]` — since the element is the value that failed.
An index stays an integer all the way out, so an element's position is
never confused with a member named `1`. Every element is attempted, so
one request reports every bad element rather than only the first.

#### A rule for every element

`#[Each]` declares a rule that runs against each element of a scalar,
backed-enum or uploaded-file list, rather than against the list itself.
It is repeatable, and each occurrence names a `Constraint` class plus the
arguments that rule's own constructor takes — positional or named:

```{code-block} php
use Kinetis\Validation\Constraints\{MinItems, MinLength, Regex};
use Kinetis\Validation\{Each, ListOf};

final readonly class TagRequest
{
    public function __construct(
        #[MinItems(1)]
        #[ListOf('string')]
        #[Each(MinLength::class, 2)]
        #[Each(Regex::class, pattern: '/^[a-z-]+$/')]
        public array $tags,
    ) {}
}
```

The two levels of rule describe different things and run in this order:

1. Each element is resolved to the declared element type. An element of
   the wrong type gets its type violation and runs none of its rules.
2. Every resolved element runs every `#[Each]` rule. Failures aggregate
   under `["tags", <index>]` paths, together with any further path the
   rule's own violation carries.
3. Only once every element has succeeded do the field's own rules run.
   `#[MinItems]` counts a list that was built, so a single bad
   element leaves it nothing to count and it does not report.

A rule declared with `#[Each]` sees an element of the declared type —
an enum list's rules receive the resolved case, not its backing value.
The rule class is built when it runs, from the arguments as written, so
an argument it refuses raises where any other rule's arguments do, at
construction.

In the generated document the two levels stay separate too: the field's
own rules contribute keywords to the array, and its `#[Each]` rules to
`items`, beside whatever the element type already states there.

```{code-block} json
{
    "tags": {
        "type": "array",
        "items": { "type": "string", "minLength": 2 },
        "minItems": 1
    }
}
```

`#[ListOf]` and `#[Each]` declare a DTO constructor field. A controller
or MCP tool method parameter binds one value each and traverses no
elements, so a list whose elements are checked belongs in a `#[Body]`
DTO, or in the DTO an MCP tool takes as its argument.

### Object-map properties

A constructor parameter typed `array` and carrying `#[ObjectMap]` takes
a JSON *object* of arbitrary keys — the shape a plain `array` field
refuses — and receives it as a plain PHP array:

```{code-block} php
use Kinetis\Validation\ObjectMap;

final readonly class UpdateProfileRequest
{
    public function __construct(
        public string $username,
        #[ObjectMap]
        public array $preferences,
    ) {}
}
```

```{code-block} json
{
    "username": "alon",
    "preferences": { "locale": "en", "digest": { "weekly": true } }
}
```

`$preferences` arrives as `['locale' => 'en', 'digest' => ['weekly' =>
true]]` — nested objects unwrapped to plain arrays all the way down, the
same value a `mixed` field would have received. No schema is declared or
checked for the keys or the values.

A JSON array, a scalar, or a `null` for a non-nullable property is a
`422` at that property's own path — `["preferences"]`, with the message
`must be a JSON object, not a JSON array.` — alongside every other
violation in the same response. Inside a [nested DTO](#nested-dtos) the
parent field is prepended, `["profile", "preferences"]`.

`{}` is accepted and hydrates to `[]`, which is the value a plain `array`
field rejects. That pair is decided by *provenance*, not by shape: a JSON
object and a JSON array decode to the same PHP array, so only the
object/array distinction the request body's own decode preserves can
tell `{}` from `[]`. A source that never carried that distinction — a
form-encoded body, or a direct `Hydrator::hydrate()` call with a
hand-built PHP array — therefore cannot fill an `#[ObjectMap]` property
at all; a JSON request body and an MCP tool call both can.

`#[ObjectMap]` is only valid on a parameter typed `array`, and never on
the same parameter as `#[ListOf]`: one admits a JSON object and the
other a JSON array, so a parameter carrying both would accept nothing.

### Writing your own constraint

A constraint is any class implementing the two-method `Constraint`
interface. `validate()` answers what a broken value gets back;
`schema()` answers which JSON Schema keywords state the same rule to a
client generating requests from the published document.

```{code-block} php
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Uppercase implements Constraint
{
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match('/^[A-Z]+$/', $value) !== 1) {
            return new Violation([], 'uppercase', 'must be all uppercase letters.');
        }

        return null;
    }

    public function schema(): array
    {
        return ['pattern' => '^[A-Z]+$'];
    }
}
```

Returning `null` from `validate()` means valid. A `Violation` carries the
stable `code` a client switches on, the default-English `message`, and
the `parameters` that message was built from — its `path` is relative to
the value being checked, so it is normally `[]` and `Hydrator` prefixes
the owning field's own path. Every built-in rule is written this way;
none of them is special-cased anywhere.

`schema()` returns the JSON Schema 2020-12 keywords for the rule, merged
into the schema of whatever it guards — `['pattern' => '^[A-Z]+$']`
above, an ECMA-262 expression because that is the dialect JSON Schema
uses. A rule no keyword expresses returns `[]`, exactly as `#[NotBlank]`
and `#[Regex]` do: it still runs, it publishes nothing. Never state a
keyword the check does not enforce — one class owning both answers is
what keeps the document and the check from disagreeing.

Any attribute implementing `Constraint` on a parameter is picked up
automatically, in validation and in the generated OpenAPI/MCP schema
alike — there is no list of known constraints to register into.

A rule is constructed fresh for each validation or schema operation from
the literal arguments its attribute was written with, and discarded, so
it must be pure: no I/O, no container, no request state, nothing retained
between calls. A rule that throws is a programmer error and propagates as
an ordinary exception, not a `422`.

### Rules about the whole DTO

A `Constraint` sees one value, so it cannot express a rule relating two
of them. `ObjectConstraint` is the class-level counterpart: a rule about
the whole DTO, discovered from a class attribute the same way a field
rule is discovered from a parameter attribute.

```{code-block} php
use Kinetis\Validation\ObjectConstraints\{AtLeastOneProvided, SameAs};

#[AtLeastOneProvided('title', 'summary')]
final readonly class UpdateArticleRequest { /* ... */ }
```

| Attribute | Checks | Constructor | Violation path | Violation code | Schema |
|---|---|---|---|---|---|
| `#[AtLeastOneProvided(...)]` | the input supplied at least one of the named fields | `string ...$fields` | `[]` | `at_least_one_provided` | `anyOf` of one `required` per field |
| `#[SameAs($field, $other)]` | the two fields hold the identical value | `string $field, string $other` | `[$field]` | `same_as` | *(none)* |

`#[AtLeastOneProvided]` is what makes an empty update a client error
rather than a silent no-op write. It asks about presence only, never
values: a field sent as `null` counts as supplied wherever the
declaration accepts `null`, because clearing a field is a change.

`#[SameAs]` compares only when the input supplied both members —
whether either had to be there at all is the declaration's question, or
another rule's. It reads both values off the constructed object by
reflection, so a promoted `private` field needs no getter, and reports on
`$field`, the one the client is asked to retype. No JSON Schema keyword
compares two properties' values, so it publishes nothing rather than
something weaker.

Writing your own is the field-rule contract, one level out:

```{code-block} php
use Kinetis\Validation\{ObjectConstraint, ValidationContext, Violation};
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EndsAfterItStarts implements ObjectConstraint
{
    public function fields(): array
    {
        return ['startsAt', 'endsAt'];
    }

    public function validate(object $value, ValidationContext $context): iterable
    {
        if ($value->endsAt <= $value->startsAt) {
            yield new Violation(['endsAt'], 'ends_after_start', 'must be after startsAt.');
        }
    }

    public function schema(): array
    {
        return [];
    }
}
```

`fields()` names every constructor field the rule reads off the object or
asks the context about — the names its attribute was configured with, or
the ones it has hardcoded, as here. Kinetis checks each of them against
the guarded class's constructor where the hydration plan is compiled and
where the schema is generated, so a mistyped name fails as a definition
error instead of a rule that silently never matches or an `anyOf` no
request can satisfy. A rule about the object as a whole, naming no field,
returns `[]`.

Object rules run once, last: after every field has resolved, passed its
own rules, and the DTO has been constructed. If any field failed, no
object rule runs at all — the only values available then are the
declaration's own defaults, and a rule reporting on those would be
reporting on something the client never sent.

`ValidationContext` carries the one thing a constructed object cannot be
asked: which of its own constructor fields the input supplied.
`wasSupplied(string): bool` is its whole read surface. It holds no
request, container, transport or DTO, and is built for one hydration.

Yielded paths are relative to the DTO — `[]` addresses the object as a
whole — and the owner prefixes its own field name or list index when the
DTO is a nested one, exactly as it does for a field failure. Like a field
rule, an object rule is constructed fresh from the literal arguments its
attribute was written with and discarded, so it must be pure. Throwing,
or yielding anything other than a `Violation`, is a programmer error and
propagates as an ordinary exception, not a `422`.

`schema()` contributes object-level keywords, merged into the class's own
schema under the same one-owner rule field rules follow: a rule cannot
claim `type`, `properties`, `required` or `additionalProperties`, which
the PHP declaration owns, nor a keyword another rule on the same class
already contributed. Either is a definition error rather than a silently
overwritten bound.

### DTO definitions Kinetis rejects

A hydration plan is compiled from a DTO's constructor by reflection —
ahead of time by `kinetis build`, or on that class's first hydration
otherwise. It supports a finite set of parameter shapes: one of the seven
supported builtin types, a backed enum, a single named class (hydrated
when it can be instantiated, instance-only when it can't), an `array`
carrying `#[ListOf]`, an `array` carrying `#[ObjectMap]`, nullable
variants of each, and the presence union `T|Absent`/`T|null|Absent`
around any of them (see [Required, optional, and absent
fields](#required-optional-and-absent-fields)).

Anything else is rejected while the plan is compiled, with an
`UnsupportedDtoDefinitionException` naming the class and the parameter —
so the definition fails at build time, or on that route's first request
in development, rather than as a `TypeError` on a live one:

- An **intersection** parameter type (`Countable&ArrayAccess`), or a
  **union** other than the two presence forms (`int|string`). Declare a
  single named type, or `T|Absent`/`T|null|Absent` where the field needs
  to tell an omitted member from an explicit `null`.
- A malformed presence union: `Absent` with no value type beside it
  (`?Absent`), with two of them (`int|string|Absent`), with no default,
  or with a default other than `Absent::Value`. Nothing but that default
  can produce the marker, so any other spelling names a field that could
  never hold one.
- A **recursive or mutually recursive** class reference — a `Comment`
  with a `Comment $parent` field, or two DTOs naming each other. A plan
  embeds each nested class's own plan inline, so a cycle has no finite
  plan, and nothing {doc}`caching`'s AOT compilation could bake into a
  cache file through `var_export()`. Take the nested payload as a plain
  `array` field, or model the deeper level as its own request.
- A class type reflection cannot resolve to a real class: `self`,
  `parent`, `static`.
- A builtin type outside the supported set — see [Builtin types outside
  the supported set](#builtin-types-outside-the-supported-set).
- `#[ListOf]` on a parameter that isn't typed `array`, or naming an
  element type outside the four families [it admits](#typed-collections)
  — another builtin, an empty name, a name no class answers to, an
  interface other than `UploadedFileInterface`, an abstract class, a unit
  enum.
- A backed enum with no cases, named by a field or by a `#[ListOf]`. No
  value can name a case that does not exist, and JSON Schema's `enum`
  may not be empty, so such a field could only publish a schema it
  rejects every request against.
- `#[Each]` on a parameter that declares no `#[ListOf]`, on a list of
  DTOs — whose elements carry their own fields' rules — or naming a
  class that does not implement `Constraint`. An upload list *may* carry
  it: an uploaded file has no fields of its own on which a rule could
  otherwise be written.
- `#[ObjectMap]` on a parameter that isn't typed `array`, or on the same
  parameter as `#[ListOf]`.
- A `#[Body]` DTO class that cannot itself be instantiated.
- An `ObjectConstraint` class attribute naming a field the constructor
  does not declare — see [Rules about the whole
  DTO](#rules-about-the-whole-dto). Schema generation reads the same
  rules and refuses it identically.

The generated OpenAPI document and MCP tool input schemas hold the same
line: a class-typed field whose class cannot be instantiated has no
truthful object schema, so schema generation refuses it rather than
emitting a bare `{"type": "object"}` no request could satisfy.
`UploadedFileInterface` is the one such type both sides accept, as a
field and as a `#[ListOf]` element alike — it is described as
`{"type": "string", "format": "binary"}` and supplied by `Dispatcher`
from the request's normalized uploaded files.

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

```{code-block} php
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
per request, which a captured default cannot do.

## Responses

### Response builders

- `HtmlResponse::create(string $html, int $status = 200, array $headers = [])`
  sets `Content-Type: text/html`.
- `PlainTextResponse::create(string $text, int $status = 200, array $headers = [])`
  sets `Content-Type: text/plain`.
- `JsonResponse::create(mixed $data, int $status = 200, array $headers = [])`
  encodes the value with `JSON_THROW_ON_ERROR` and sets
  `Content-Type: application/json`, replacing any caller-supplied content
  type case-insensitively. An encoding failure, including invalid UTF-8,
  raises `JsonException` before a response exists; an exception thrown by
  the value's own `JsonSerializable::jsonSerialize()` propagates unchanged.
- `FileResponse::fromContents(string $contents, string $contentType, int $status = 200, ?string $downloadFilename = null, array $headers = [])`
  builds a response around bytes you already hold — a generated CSV,
  image or PDF — and adds a `Content-Disposition: attachment` header when
  `$downloadFilename` is given.
- `RedirectResponse::to(string $url, int $status = 302, array $headers = [])`
  sets a `Location` header.
- `ErrorResponse::create(int $status, string $message, array $headers = [])`
  builds `{"error": "..."}` at the given status, the same shape Kinetis's
  own 404/405/500 responses use — a 405 (a path matches, but not this
  method) carries an RFC 9110 `Allow` header listing every method the
  path supports, via this same `$headers` parameter.

All six live in `Kinetis\Http\Responses`. No response builder takes a
filesystem path: reading one synchronously holds the worker for the
length of the I/O, and core carries no asynchronous filesystem client.

Every builder accepts caller-supplied headers. Headers derived from its
semantic arguments — such as `Content-Type`, `Content-Length`, `Location`
or a generated attachment disposition — replace caller-supplied values
with any casing; the remaining headers pass through unchanged.

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
recipient, and rewriting the name here would change what the caller
asked for. Call `basename()` yourself if the source is a stored path
rather than a name.

### `#[Response]` and the route's own status

A route attribute's `status` is used when the controller returns plain
data — an array or a DTO. A returned `ResponseInterface` passes through
`Dispatcher` untouched, with whatever status, headers and body it
carries.

`#[Response(int $status, string $description, ?string $body = null,
string $mediaType = 'application/json')]` is repeatable and purely
descriptive: `Dispatcher` never reads it, only `OpenApiGenerator` does.
Each one adds one entry to that operation's `responses` alongside the
route's default, and nothing checks that the method produces the status,
the shape or the media type it declares. It documents the statuses
*besides* the route's own. The generator describes the route's own status
from the method's return type, response schema included, so an attribute
repeating that status is ignored rather than replacing the richer entry
with a bare description.

`$body` names the DTO that status's payload is shaped like — an error
envelope, a problem document — which the method's declared return type
does not describe. It is published as that class's component schema
under `$mediaType`, through the same deduplication a request body and
the default response use, so a DTO named by two statuses, or by a status
and a request body, is one `components/schemas` entry referenced twice:

```{code-block} php
#[Get('/users/{id}')]
#[Response(404, description: 'User not found.', body: ApiError::class)]
#[Response(422, description: 'Validation failed.', body: ApiError::class, mediaType: 'application/problem+json')]
#[Response(503, description: 'Temporarily unavailable.')]
public function show(int $id): ResponseInterface|UserResponse
```

```{code-block} json
{
    "404": {
        "description": "User not found.",
        "content": {"application/json": {"schema": {"$ref": "#/components/schemas/ApiError"}}}
    },
    "422": {
        "description": "Validation failed.",
        "content": {"application/problem+json": {"schema": {"$ref": "#/components/schemas/ApiError"}}}
    },
    "503": {"description": "Temporarily unavailable."}
}
```

Without a `$body` the entry carries a description and nothing else, and
`$mediaType` then describes nothing and is not read at all. A `$body`
naming something that is not a class fails document generation with
`Exception\JsonSchemaException`: publishing a component under a name
nothing backs would advertise a response shape no route can produce.

Kinetis adds no status of its own here. A route that should advertise the
`422` a validation failure produces declares its own
`#[Response(422, ...)]`, with the problem document's DTO as the body
where it has one.

## OpenAPI generation

### What the document describes

Every route `Router` has registered is reflected a second time — the same
controller-method metadata `Dispatcher` reads at request time — to build
an OpenAPI 3.1 document.

`#[Body]` DTOs become `requestBody` schemas, with every constraint mapped
onto the matching JSON Schema keyword (`format: email`,
`minLength`/`maxLength`, `exclusiveMinimum`/`exclusiveMaximum`,
`minimum`/`maximum`, `multipleOf`, `enum`, `not`, `minItems`/`maxItems`,
`format: uri`, `format: uuid`, `format: date`, `format: date-time`, and
`#[Ip]`'s `anyOf` of the two address formats) — except the rules with no
keyword listed in [Validation constraints](#validation-constraints).
`#[Query]` parameters and path parameters become `parameters` entries,
with the identical constraint-to-keyword mapping applied to their own
`schema`; one typed as a [backed
enum](#enum-path-and-query-parameters) publishes that enum's backing
`type` and the exact `enum` of its case values. A controller method's
declared return type becomes the default response's schema — `UserResponse` (or `?UserResponse`, or a union like
`ResponseInterface|array` where `UserResponse` is one member) produces a
`content` entry describing it; a bare `array`/`ResponseInterface`-only
return, with no shape reflection can recover, leaves the response
description-only.

A rooted `#[Body('article')]` publishes the document it reads — `{"type":
"object", "properties": {"article": {"$ref": ...}}, "required":
["article"], "additionalProperties": false}` — under the content types an
unrooted `#[Body]` of the same DTO gets, so a DTO declaring an upload
still advertises `multipart/form-data` alone. The DTO's own component is
the same either way, and an unrooted `#[Body]` publishes its bare `$ref`.

Each additional status a `#[Response]` attribute declares becomes its own
entry, with the DTO it names published as a component schema under the
attribute's media type — see ["`#[Response]` and the route's own
status"](#response-and-the-routes-own-status) above.

A [`#[ListOf]` field](#typed-collections) becomes a `{"type": "array",
"items": ...}` schema: `items` describes the element class the same way
any other DTO reference does, or the element's own scalar type or
backed-enum domain, with each `#[Each]` rule's keywords merged into it
and the field's own constraint keywords merged beside `items`. A
[backed enum field](#backed-enum-fields) becomes its backing `type` and
the exact `enum` of its cases. An [`#[ObjectMap]`
field](#object-map-properties) becomes `{"type": "object",
"additionalProperties": true}`.

Every DTO object schema says `additionalProperties: false`, and carries
whatever object-level keywords its [class-level
rules](#rules-about-the-whole-dto) contribute — see [Unknown members are
rejected for JSON](#unknown-members-are-rejected-for-json) for what the
runtime holds each source to.

### Components

Every DTO schema — whether reached via a `requestBody`, a response, or a
[`#[ListOf]`](#typed-collections) element, at any depth — is
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
that share a short name fall back to the fully-qualified name instead of
overwriting one schema with the other's.

### Security

A middleware class describes security by implementing
`Kinetis\OpenApi\SecurityDescriberInterface`, whose one static method
returns a `SecurityDescription`: the schemes it defines, and the
requirements it enforces in disjunctive normal form — the outer list is
OR, the schemes named inside one requirement object are AND, and each
value is that scheme's required scopes. `SecurityDescription::scheme()`
builds the usual one-scheme description, and its `TYPES` names the five
`type` values OpenAPI 3.1 defines. {ref}`openapi-security` has the shape
and the purity rule; this section is what the generator does with it.

The pipeline a route runs under is read as class-strings, so nothing is
constructed to describe it: `Kernel` hands the generator the effective
global middleware order and the group map it dispatches against, `@name`
references expand through the same
`Kinetis\Http\Attributes\Middleware::expandGroups()` dispatch uses, and
the classes implementing the interface — a subclass included — are the
describers. A hidden route is discarded before any of this, so it
publishes neither an operation nor a scheme.

Describers apply in pipeline order, global first, and sequence is AND,
so composing them is the Cartesian product of their alternatives: two
alternatives on each of two describers produce four. Merging one
alternative into another unions the scopes of a scheme both name. Every
composed requirement object is canonical — scheme names sorted, each
scope list sorted and deduplicated — and alternatives stating the same
requirement collapse into one.

Global describers alone compose the document's root `security`. An
operation publishes its own only where the composition differs from that
root, so a route that adds no describer of its own adds nothing to the
document. An empty requirement object survives composition and is
published as `{}`: a requirement object with no string key is published
as a JSON object, never as the `[]` a PHP array would encode to.

`components/securitySchemes` holds every definition reached this way,
beside `components/schemas`; neither overwrites the other. Two providers
may share a scheme name only when they define it identically, compared
with every map's keys sorted at any depth — the order members are
written in is not part of what a definition means, while a list's order
is. The definition published is the one the first provider wrote.

`#[OpenApiSecurity]` replaces inference for one operation. Its arguments
are provider classes composed as AND, and it is read from the method
first and then from the controller class the route was registered on —
never from a parent, which is the ordinary rule for [where attributes
are read from](cli.md#where-attributes-are-read-from). With no argument
it publishes `security: []`. An explicit declaration does not combine
with global or route inference, and it shares scheme definitions with
everything else in the document like any other provider. It reaches the
document alone: the middleware pipeline is unchanged, so a no-argument
declaration describes a route that already answers an unauthenticated
request rather than making a guarded one reachable.

`Kinetis\OpenApi\Exception\OpenApiSecurityException` fails generation
for a provider that does not implement the interface, a scheme with an
empty name or a definition that is not an array, a `type` outside
OpenAPI 3.1's five, requirements that are not a non-empty list of
requirement objects, a requirement naming a scheme its own description
does not declare or holding anything but scope strings, and two
providers declaring one scheme name differently — the last naming the
scheme and both classes. Nothing else in a definition is checked: it is
the provider's own contract.

No response status is derived from any of this. `#[Response]` remains
the only way a `401` or `403` enters the document.

### How the documentation routes resolve

`/openapi.json` and `/openapi` are served by an ordinary controller the
framework ships, found by the same route discovery that finds yours — so
they appear in `kinetis routes:list` alongside your own routes, and
middleware attached to them behaves like middleware anywhere else. The
`openapi` middleware group runs through the normal pipeline, and `Kernel`
registers `OpenApiAccess` and `OpenApiDocumentProvider` on each request
scope for the controller's constructor to be autowired from.

The Swagger UI page at `/openapi` loads Swagger UI from a CDN and sends
its own `Content-Security-Policy` permitting exactly that — see
{doc}`appendix-middleware`.

### Choosing where the documentation is reachable

`OPENAPI_ENVIRONMENTS` is a comma-separated list of `APP_ENV` values,
compared ignoring case and surrounding space. It is matched against
`APP_ENV` itself rather than against Kinetis's own `AppEnvironment`,
which resolves every unfamiliar name to production — so a `staging`
deployment can name itself and mean it. Unset, empty, or naming an
environment you are not running in, both paths fall through to routing
and 404 — nothing confirms that they would exist somewhere else.

An explicit argument decides outright and ignores the variable, which is
what a test or a documentation-only service wants:

```{code-block} php
new Kinetis\Http\Kernel($app, $router, exposeOpenApi: true);
```

The check runs per request rather than when routes are registered.
Registering the routes conditionally would push the decision into
`kinetis build`, and a production image would then answer according to
whichever environment compiled it rather than the one it is running in.
The routes always exist — `routes:list` shows them either way — and a
closed one answers exactly as an unregistered path does.

### When the document is generated

In development the document is generated per request, so an attribute
you change is visible on the next reload.

In production it is generated once per process and held in memory for
that process's lifetime, by a provider the `Kernel` builds for its own
router. The route table cannot change under a running process, and a
deployment that changes routes, DTOs, or constraints starts new
processes, each with its own router and its own provider. The document a
process serves therefore always describes the routes that process
dispatches: there is nothing to clear, no expiry to wait out, and no
cached entry a previous deployment could leave behind.

## See also

- {doc}`routing-validation` — the task guide this page supports.
- {doc}`appendix-middleware` — the pipeline around binding and the
  validation response.
- {doc}`runtime-adapters` — how a request's raw bytes reach the
  middleware that fills the uploaded-files bag.
- {doc}`caching` — how route, binding and validation plans are compiled
  ahead of time in production.
- {doc}`mcp` — the same `Hydrator` and schema rules applied to MCP tool
  arguments.
