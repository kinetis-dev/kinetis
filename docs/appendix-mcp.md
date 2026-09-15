# Appendix: MCP Reference

The contracts behind {doc}`mcp`: the protocol revision and its request
grammar, the Streamable HTTP headers and status codes, the error
catalogue, progress streaming, the `mcp` middleware group, request scope
and disposal, and argument binding. For the task-first path — tools,
resources, stdio and HTTP setup — see {doc}`mcp`.

## Protocol revision

`McpServer` implements the `2026-07-28` revision of MCP and no other: a
stateless, per-request model. Every request carries its own protocol
version and client capabilities in `params._meta`. There is no
connection-level handshake — no `initialize`, no
`notifications/initialized`, no `ping` — and a client discovers what the
server offers with `server/discover`.

```{code-block} json
{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {
        "_meta": {
            "io.modelcontextprotocol/protocolVersion": "2026-07-28",
            "io.modelcontextprotocol/clientCapabilities": {}
        }
    }
}
```

The server answers five methods: `server/discover`, `tools/list`,
`tools/call`, `resources/list` and `resources/read`. Any other method is
`-32601`, once `_meta` has passed the checks below.

`_meta.io.modelcontextprotocol/protocolVersion` and
`_meta.io.modelcontextprotocol/clientCapabilities` are required on every
request. A request missing either is `-32602 Invalid params` — which is
also what a client on an earlier revision receives for an `initialize`
without `_meta`. A request naming any version other than `2026-07-28` is
`-32022 UnsupportedProtocolVersion`, whose `error.data` carries
`supported` (`["2026-07-28"]`) and `requested`.

Every result carries `resultType: "complete"` and the server's identity
under `_meta`, added after the handler's own result so a handler cannot
override either:

```{code-block} json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "tools": [],
        "ttlMs": 3600000,
        "cacheScope": "public",
        "resultType": "complete",
        "_meta": {"io.modelcontextprotocol/serverInfo": {"name": "Kinetis", "version": "1.0.0"}}
    }
}
```

`server/discover` returns `supportedVersions: ["2026-07-28"]` and
`capabilities` with empty `tools` and `resources` objects.

### Caching hints and server instructions

`server/discover`, `tools/list` and `resources/list` results carry
`ttlMs` (how long a client may consider the result fresh, in
milliseconds, `3600000`) and `cacheScope: "public"`, since they describe
the registered tools and resources, identical for every caller.
`resources/read` carries the same `ttlMs` with `cacheScope: "private"`,
since a resource method's content can be caller-specific. `tools/call`
carries neither: it is an action, not a cacheable read.

`server/discover` also carries `instructions` — a short description of
what the server's tools are for — when `McpServer` is constructed with
one, and omits the key otherwise:

```{code-block} php
use Kinetis\Mcp\McpServer;

$server = new McpServer($registry, $dispatcher, instructions: 'This server manages orders and inventory.');
```

## Streamable HTTP headers

A request over HTTP mirrors body fields into headers, so an intermediary
can route or inspect it without parsing the body:

```{code-block} text
POST /mcp HTTP/1.1
Content-Type: application/json
MCP-Protocol-Version: 2026-07-28
Mcp-Method: tools/call
Mcp-Name: get_weather

{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "get_weather", ...}}
```

`MCP-Protocol-Version` mirrors `_meta`'s protocol version and
`Mcp-Method` mirrors `method`; both are required on every request.
`Mcp-Name` mirrors `params.name` on `tools/call` and `params.uri` on
`resources/read`, and is required for those two methods only. A value
that is not safe as a plain header (non-ASCII or control characters) is
sent Base64-encoded inside `=?base64?…?=`:

```{code-block} text
Mcp-Name: =?base64?SGVsbG8sIOS4lueVjA==?=
```

A header that is missing, that does not match its body value once
decoded, or that uses the wrapper around invalid Base64 is rejected with
`400` and a JSON-RPC `-32020` header-mismatch error. The comparison runs
only after the body has passed the checks in "Malformed requests" below,
so a malformed body is never reported as a header mismatch.

`GET /mcp` and `DELETE /mcp` answer the router's own `405` with
`Allow: POST`. Earlier Streamable HTTP revisions used GET for a
server-initiated stream and DELETE to end a session; `2026-07-28` has
neither.

## HTTP status codes

| Outcome | Status | Body |
|---|---|---|
| A JSON-RPC result | `200` | JSON-RPC response |
| `-32600`, `-32602`, `-32020`, `-32022` | `400` | JSON-RPC error |
| `-32601` | `404` | JSON-RPC error |
| `-32700`, `-32603` | `200` | JSON-RPC error |
| A `tools/call` request with `_meta.progressToken` | `200` | `text/event-stream`; an error arrives in the final event |
| A notification | `202` | none |
| An `Origin` not listed in `MCP_ALLOWED_ORIGINS` | `403` | `{"error": "Origin \"…\" is not allowed to access this MCP endpoint."}` |
| No `CurrentUserInterface` and `MCP_HTTP_PUBLIC` not true | `401` | `{"error": "Unauthenticated."}` |
| A body over the request body limit | `413` | the framework's error body |
| `GET` or `DELETE` | `405` | the framework's error body, with `Allow: POST` |

A `401` produced by an authentication middleware in the group, such as
`BearerAuthMiddleware`'s, is that middleware's own response and runs
before the guard.

## Malformed requests

Every message — over HTTP, over stdio, or handed to
`McpServer::handle()` directly — passes the same structural validation
before `method` is dispatched. Invalid JSON is `-32700 Parse error`.
Valid JSON that is not a well-formed request object is `-32600 Invalid
Request`: a missing or wrong `jsonrpc`, a missing or non-string
`method`, an `id` outside string, integer or null, or a top-level JSON
array. Batching is not supported, so an array body is rejected outright.

Only a structurally valid message without `id` is a notification and
gets no response. A structurally invalid message still gets an error,
with `id: null` when no valid id could be read. Over HTTP a malformed
body therefore never becomes `202`: a `202` always means a genuine
notification.

`params`, `_meta`, `_meta.io.modelcontextprotocol/clientCapabilities`
and `tools/call`'s `arguments` are named objects. A present value that
is a JSON array, a scalar or `null` is `-32602`; only omitting the field
means "none given". `{}` and `[]` are different on the wire and get the
responses their shapes mean. A present `_meta.progressToken` that is not
a string or integer is also `-32602`, rather than silently disabling
progress.

A caller building a message in PHP — a test, or an embedder bypassing
both transports — cannot write `{}` and `[]` differently in an array
literal. `Kinetis\Mcp\JsonObject` marks a value as an object:
`new JsonObject([])` is accepted by `McpServer::handle()` and
`JsonRpcCodec::validateMessage()` exactly like a decoded `{}`, and
`json_encode()`s as `{}` when empty or as its properties otherwise.

`McpServer::preflight()` checks all of this, including the
method-specific requirements, without invoking anything. `tools/call`'s
`name` and `resources/read`'s `uri` are required non-empty strings there.
A well-formed name or URI that is not registered passes preflight and is
refused at dispatch with `-32602`. `handle()` runs preflight as its own
first step; `McpController` runs it before the header comparison and
before choosing a progress stream, so a malformed nested value can never
surface as a header mismatch or commit the response to
`text/event-stream`.

Once the envelope is valid, a notification whose MCP content fails
preflight is neither answered nor dispatched: no tool or resource runs,
and over HTTP the request gets a plain `202`, never a `400` and never a
stream.

## Error catalogue

| Code | Message | Raised when |
|---|---|---|
| `-32700` | `Parse error.` | The body or line is not valid JSON. |
| `-32600` | `Invalid Request.` | The envelope is malformed, including a top-level array. |
| `-32601` | `Method not found: "…".` | The method is not one of the five this server answers. |
| `-32602` | varies | A named object has the wrong shape, a required `_meta` key, `name` or `uri` is missing, `progressToken` has the wrong type, or the tool name or resource URI is not registered. |
| `-32603` | `Internal error.` | An unexpected exception outside a tool call, including a resource method that throws. |
| `-32020` | `Header mismatch: …` | HTTP only: a mirrored header is missing or does not match the body. |
| `-32022` | `Unsupported protocol version "…".` | `_meta` names a version other than `2026-07-28`. |

A tool that runs and fails is not in this table. `McpServer::callTool()`
catches it and returns a result with `isError: true`:

- A `ValidationException` carries its violations as
  `{"errors": [...]}` in the text content, each entry with a segmented
  `path`, a stable `code`, a `message` and its `parameters` — the
  structure the HTTP renderer puts in its RFC 9457 document, never that
  document itself. Invalid UTF-8 in a violation is substituted rather
  than failing the encode.
- Any other exception, including a tool result that cannot be
  JSON-encoded, becomes the fixed text `Tool execution failed.`.

A resource method has no such containment: its exception propagates to
`handle()`'s top-level catch and becomes `-32603` with the fixed message
`Internal error.`. Every unexpected exception in `handle()` is redacted
the same way. A resource method's string return value is its `text`;
anything else is JSON-encoded.

The real exception, in every case, goes to the `Psr\Log\LoggerInterface`
passed as `McpServer`'s `logger` argument, `NullLogger` by default. The
package's bootstrap passes the container's logger, so `mcp:serve` and
`/mcp` both log through the application's binding (see {doc}`logging`).
A logger that throws is caught and discarded; it can never replace the
response or stop a stdio process.

## Progress streaming

A `ProgressReporter`-typed tool parameter is injected by type. It is
excluded from the tool's `inputSchema`, never required of a call, and an
argument of that name sent by a client is `unexpected_field`.
`report(int|float $progress, int|float|null $total = null, ?string $message = null)`
calls the transport's emitter synchronously, on the tool's own call
stack; no Fiber or generator is involved. Without
`_meta.progressToken` on the request, `report()` does nothing.

Each report becomes one notification. `total` and `message` are always
present, `null` when not given:

```{code-block} json
{"jsonrpc": "2.0", "method": "notifications/progress", "params": {"progressToken": "reindex-1", "progress": 2, "total": 3, "message": null}}
```

Over stdio, each notification is one line written before the response
line.

Over HTTP, a `tools/call` request — `id` present, including `id: null`,
which JSON-RPC treats as a request — carrying `_meta.progressToken` is
answered with `Content-Type: text/event-stream` and
`X-Accel-Buffering: no`. Each notification is one `data: <json>` event,
flushed as `report()` is called, and the final event is the JSON-RPC
response. The status is `200` because headers are sent before the tool
runs, so a JSON-RPC error arrives in that final event. Every other
request gets one buffered JSON response.

A `tools/call` notification — `id` absent — never opens a stream, even
with a valid `progressToken`. It gets `202` with no body, and the tool
still runs, as JSON-RPC requires; its reports have nowhere to go.

## The `mcp` middleware group

`McpController` references the `mcp` group with `#[Middleware('@mcp')]`,
and the group resolves from each request's scope like any route
middleware (see {doc}`middleware`). Members run by descending
`#[AsMiddlewareGroup]` priority:

| Priority | Member |
|---|---|
| `100` | `Kinetis\Mcp\Http\McpOriginMiddleware`, always present |
| `50` (the attribute's default) | the application's own members, such as an authentication middleware |
| `0` | `Kinetis\Mcp\Http\McpIdentityGuardMiddleware`, always present |

`McpOriginMiddleware` reads `MCP_ALLOWED_ORIGINS` as a comma-separated
list of exact values, each trimmed. A request without an `Origin` header
passes; one whose `Origin` is not listed is `403`. The Streamable HTTP
specification requires this check against DNS rebinding. Its permanent
membership is also what guarantees the group exists whenever the
package is installed.

`McpIdentityGuardMiddleware` passes a request when `MCP_HTTP_PUBLIC` is
true or when `CurrentUserInterface` is registered on the request's scope
(`RequestScope::isRegistered()`, which an autowired instance does not
satisfy). Otherwise it answers `401` before `McpController` is
constructed: nothing is dispatched, and the body is the framework's own
`{"error": "Unauthenticated."}`. It sends no `WWW-Authenticate`
challenge, since the scheme belongs to the authentication middleware,
and nothing in the response distinguishes a missing identity from a
closed endpoint. A middleware registering only a concrete user class
does not open the endpoint.

`MCP_HTTP_PUBLIC` is a typed `Kinetis\Config` boolean read from the
boot-time environment (see {doc}`config`): a value that is not a
recognized boolean throws `InvalidConfigValueException`, and the
endpoint stays closed.

The stdio transport has no group, no guard and no `MCP_HTTP_PUBLIC`.

Global middleware wraps `/mcp` like every route, including
`RequestBodyMiddleware` (see {doc}`middleware`), which bounds and stages
the body before routing: an oversized body, with or without an honest
`Content-Length`, is `413` before `McpServer` sees it.

### Compiled group membership

A middleware group's membership is compiled into
`.kinetis-cache/compiled.php` (see {doc}`caching`), and a published
artifact is superseded only by a rebuild or a cache format change. A
deployment that pre-warms the cache runs `kinetis build` in every deploy
that changes the group's members — adding an authentication middleware,
or upgrading `kinetis/mcp` — or it keeps serving `/mcp` with the
membership it was built with. Development's live discovery, and a
production deployment that compiles lazily against an empty
`.kinetis-cache/`, pick up the change with nothing extra to do.

## Request scope and disposal

**Over HTTP**, `/mcp` is an ordinary route and gets the full request
lifecycle: a fresh `RequestScope` per request, every
`AppScope::onRequestScopeCreated()` initializer run on it — including
`kinetis/database-bridge`'s lazy `TransactionGuard` binding (see
{doc}`container`) — the tool or resource class and its dependencies
resolved from it, and disposal once the response is written. A tool
constructor-injecting `RequestScope` receives that live scope, and
whatever an `mcp`-group middleware registered on it.

A progress-streamed call runs on the same scope. `Kernel` keeps a
streaming response's scope alive until the stream is settled and then
disposes it (see {doc}`container`), so everything the middleware
published — `CurrentUserInterface`, a concrete class alongside it such
as `JwtUser`, anything else request-scoped — is still there when the
tool runs. A disposal failure there is contained and logged rather than
raised.

**Over stdio**, `mcp:serve` gives the transport the application's
`AppScope`, and each line is its own unit of work: a scope from
`AppScope::createRequestScope()` with every initializer run, the
response written, then disposal and `gc_collect_cycles()` in a
`finally`. Disposal never throws: a failure is logged through
`AppScope`'s logger, since the message's own scope is already disposed,
and the loop moves on to the next line.

On both transports, a disposal failure never suppresses a response that
was written and never produces a second JSON-RPC message. A write
failure — a closed or broken stdout, or, on the streamed HTTP transport,
an output-buffer handler throwing during the flush — propagates as the
primary failure, with the scope still disposed underneath it. The data
being written was already encoded once inside `handle()`, so a tool's
result cannot cause it. Nothing about disposal timing changes when
progress notifications are written.

State a tool registers on its scope does not survive to the next
message. Only a hand-rolled transport that calls `McpServer::handle()`
without a scope shares the dispatcher's own container across messages.

### Stdio framing

The transport strips only the line terminator (`\r\n`) before decoding —
never a bare `trim()`, which would also remove NUL and vertical-tab
bytes and turn invalid input into accepted input. A line holding only
spaces or tabs is skipped as a blank.

Every frame — a progress notification or a response — is written whole:
the transport loops `fwrite()` until the encoded message and its newline
are written, because `fwrite()` may accept fewer bytes than given when
the reader of a pipe falls behind. A write that stops making progress
throws `Kinetis\Mcp\Exception\StdioWriteException` with the bytes
written and the total, and ends the loop, since nothing can safely
follow a partial frame.

A failed progress write is not allowed to reach the tool, where
`callTool()` would turn it into `Tool execution failed.` and write that
into the corrupted stream. The transport records the failure, skips
every later notification for that message, and throws it once
`handle()` returns, before the response is attempted.

## Argument binding

A tool call's arguments are one decoded JSON object, read under
`Kinetis\Validation\InputSource::Json` — the vocabulary of a JSON HTTP
body, and the one the tool's `inputSchema` promises. A parameter typed
`int` takes the JSON number `42`, not the string `"42"`; see
{doc}`routing-validation`'s "Scalar type checking".

- An argument the method requires and the call omitted is `required` at
  that argument's path.
- An explicit `null` for a parameter whose type refuses it is
  `null_not_allowed`, for a DTO-typed parameter as much as a scalar.
- The arguments object is closed, as the schema's
  `additionalProperties: false` says: a key naming no parameter is
  `unexpected_field` on its own path. A DTO-typed argument's object is
  closed one level in, and so is every DTO nested inside it.
- Every failure a call has — unknown, missing, wrong-typed, or refused
  by a rule — arrives together in one result.

A tool parameter declares a single named type. A union or intersection
has no truthful `inputSchema`, so it is refused when the tool is
registered — including the `T|Absent` presence union, which belongs to a
DTO constructor field (see {doc}`routing-validation`'s "Required,
optional, and absent fields"); a DTO-typed argument may use it on its
own fields. Deriving the binding plan refuses the same declaration in
the same words, so what a method may declare does not depend on the path
that reached it.

The `inputSchema` is built from the method's parameters and their
constraint attributes, as for an HTTP request body. `#[Regex]` and
`#[NotBlank]` have no JSON Schema keyword, so they check an argument at
runtime only; see {doc}`routing-validation`'s "Validation constraints".

Registration reads public methods. A tool name and a resource URI are
each unique across every registered class: two methods claiming one —
in the same class or in two packages — throw
`Kinetis\Mcp\Exception\DuplicateDefinitionException` naming both
`Class::method()` pairs, so the schema from `tools/list` is always the
one `tools/call` invokes. Registering the same class twice is a no-op.
Discovery scans the application's PSR-4 roots and every installed
package's `extra.kinetis` scan roots; `MCP_DISCOVERY_PATHS` restricts the
application scan (see {doc}`cli`'s "Restricting discovery"), and a
production build compiles the registry into the cache (see
{doc}`caching`).

## See also

- {doc}`mcp` — the task-first guide.
- {doc}`appendix-configuration` — every `MCP_*` key.
- {doc}`appendix-packages` — `Kinetis\Mcp` in the package map.
- {doc}`middleware` — middleware groups and `CorsMiddleware`.
- {doc}`container` — request scopes and disposal.
