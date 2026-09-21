# Appendix: MCP Reference

The contracts behind {doc}`mcp`: the protocol revision and its request
grammar, Streamable HTTP and its status codes, the error catalogue,
progress streaming, the `mcp` middleware group, request scope and
disposal, and argument binding. For the task-first path — tools,
resources, stdio and HTTP setup — see {doc}`mcp`.

## Ownership

`kinetis/mcp-protocol` owns the wire: JSON-RPC 2.0 envelopes, the one MCP
revision, typed tool and resource descriptions, progress notifications,
and the checked newline-delimited stdio loop. `kinetis/mcp` owns
everything Kinetis-specific around it — the attributes, discovery and
registry, schema generation, hydration and validation, telemetry,
middleware, request scopes, the `/mcp` route and the package bootstrap —
and adapts them to that wire through `Kinetis\Mcp\KinetisMcpApplication`.
`kinetis/mcp-docs` and `kinetis/orbitron` are the other two consumers of
the same protocol package, and Orbitron composes `kinetis/mcp-docs` to
publish its resources and its page-window tool too; neither depends on `kinetis/mcp`, whose
installation would register a bootstrap and a discovery plugin in the
consumer application.

## Protocol revision

`Kinetis\McpProtocol\McpServer` implements MCP `2025-06-18` and no
other. A session opens with the ordinary lifecycle handshake:

```{code-block} json
{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "claude-code", "version": "2.1.273"}}}
```

`protocolVersion` must be a non-empty string, `capabilities` an object,
and `clientInfo` an object with non-empty string `name` and `version`;
anything else is `-32602`. The answer always selects `2025-06-18`,
whatever the client asked for — the specification's rule is that a server
responds with a version it supports and the client decides whether to
continue, so a single-version server has no unsupported-version error to
raise. A repeat `initialize` returns the identical result: the server
keeps no negotiated state.

```{code-block} json
{"jsonrpc": "2.0", "id": 1, "result": {"protocolVersion": "2025-06-18", "capabilities": {"tools": {}, "resources": {}}, "serverInfo": {"name": "Kinetis", "version": "1.0.0"}}}
```

`capabilities` carries `tools` only when the application registered at
least one tool, and `resources` only when it registered at least one
resource, so a server never invites a call it cannot answer.
`instructions` is present only when the consumer supplied it — `kinetis/mcp`
supplies none.

The server answers six methods: `initialize`, `ping`, `tools/list`,
`tools/call`, `resources/list` and `resources/read`. Any other request
method is `-32601`. `tools/list` and `resources/list` return the whole
list and issue no `nextCursor`, so a `cursor` a client sends is one this
server never gave it and is `-32602`.

Notifications are never answered and never dispatch anything.
`notifications/initialized` is a no-op, and every other valid
notification is suppressed — including a `tools/call` without an `id`,
which runs no tool, because nothing this revision defines from client to
server asks this server to act. A structurally valid *response* message
(an `id` plus exactly one of `result` and `error`, and no `method`) is
ignored too: this server sends no requests, so there is nothing it could
be a response to.

Initialization ordering is not enforced. A client is required to
initialize first, but the same stateless server sits behind the HTTP
route, where each request stands alone, so an otherwise valid
`tools/list` before `initialize` is answered rather than refused.

## Streamable HTTP

`POST /mcp` carries one JSON-RPC message. `MCP-Protocol-Version` is the
transport's only protocol header:

```{code-block} text
POST /mcp HTTP/1.1
Content-Type: application/json
MCP-Protocol-Version: 2025-06-18

{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "get_weather", "arguments": {"city": "Berlin"}}}
```

`initialize` may arrive without the header, because it is what
establishes the version. Every later message must carry it and must
carry exactly `2025-06-18`. A missing header on a later message means the
specification's `2025-03-26` fallback, which this single-version server
does not implement, so it is `400` rather than assumed; an unsupported or
malformed value is `400` too. Nothing is remembered between requests, so
an earlier `initialize` never excuses a missing header on a later one.

Sessions are optional in this revision and this server issues none: no
`Mcp-Session-Id` is ever sent, and none is ever required. `GET /mcp` and
`DELETE /mcp` answer the router's own `405` with `Allow: POST` — GET
opens a server-initiated stream and DELETE terminates a session, and
neither is implemented.

## HTTP status codes

| Outcome | Status | Body |
|---|---|---|
| A JSON-RPC result, or a JSON-RPC error after a valid envelope | `200` | JSON-RPC response |
| Malformed JSON or a malformed envelope (`-32700`, `-32600`, `-32602` on `params`) | `400` | JSON-RPC error |
| A missing, unsupported or malformed `MCP-Protocol-Version` | `400` | JSON-RPC `-32600` naming the supported revision |
| A `tools/call` request with a well-formed `_meta.progressToken` | `200` | `text/event-stream`; an error arrives in the final event |
| A notification, or a client response message | `202` | none |
| An `Origin` not listed in `MCP_ALLOWED_ORIGINS` | `403` | `{"error": "Origin \"…\" is not allowed to access this MCP endpoint."}` |
| No `CurrentUserInterface` and `MCP_HTTP_PUBLIC` not true | `401` | `{"error": "Unauthenticated."}` |
| A body over the request body limit | `413` | the framework's error body |
| `GET` or `DELETE` | `405` | the framework's error body, with `Allow: POST` |

A request whose envelope was understood carries its outcome in the
JSON-RPC envelope rather than in the status: an unknown method, an
unknown tool name and a failed parameter check are all `200`. Only input
the transport could not use at all is a `4xx`.

A `401` produced by an authentication middleware in the group, such as
`BearerAuthMiddleware`'s, is that middleware's own response and runs
before the guard.

## Malformed requests

Every message — over HTTP, over stdio, or handed to
`McpServer::handle()` directly — passes the same structural validation in
`Kinetis\McpProtocol\JsonRpcCodec` before `method` is dispatched.
Invalid JSON is `-32700 Parse error` under `id: null`. Valid JSON that is
not a well-formed message is `-32600 Invalid Request`: a missing or wrong
`jsonrpc`, a missing or empty `method`, an `id` outside string and
integer, or a top-level JSON array. Batching is not part of this
revision, so an array body is rejected outright.

An `id` MUST NOT be null in `2025-06-18`, so `{"id": null}` is an invalid
request rather than a request whose id is null. A boolean, a float or a
structured `id` is refused the same way, and the error answers under
`id: null` because there was nothing valid to echo.

Only a structurally valid message without `id` is a notification, and it
gets no response. A structurally invalid message still gets an error,
because the envelope is exactly the thing that would have told us it was
a notification. Over HTTP a malformed body therefore never becomes
`202`: a `202` always means a genuine notification or a client response
message.

`params`, `_meta` and `tools/call`'s `arguments` are named objects. A
present value that is a JSON array, a scalar or `null` is `-32602`; only
omitting the field means "none given". `{}` and `[]` are different on the
wire and get the responses their shapes mean. A present
`_meta.progressToken` that is not a string or an integer is also
`-32602`, rather than silently disabling progress. A notification whose
own `params` are malformed is neither answered nor dispatched.

`tools/call`'s `name` and `resources/read`'s `uri` are required non-empty
strings. A well-formed name or URI the consumer does not publish is
refused before anything runs: `-32602` for a tool, `-32002` for a
resource.

A caller building a message in PHP — a test, or an embedder bypassing
both transports — cannot write `{}` and `[]` differently in an array
literal. `Kinetis\McpProtocol\JsonObject` marks a value as an object:
`new JsonObject()` is accepted exactly like a decoded `{}`, and
`JsonRpcCodec::toObjectTree()` converts a tree holding markers back into
the `stdClass`/array shape a consumer receives, so a marker never reaches
consumer code.

## Error catalogue

| Code | Message | Raised when |
|---|---|---|
| `-32700` | `Parse error.` | The body or line is not valid JSON, or a stdio line exceeded the payload cap. |
| `-32600` | `Invalid Request.` | The envelope is malformed, including a top-level array. HTTP also uses it, with its own message, for a missing or unsupported `MCP-Protocol-Version`. |
| `-32601` | `Method not found: "…".` | The method is not one of the six this server answers. |
| `-32602` | varies | A named object has the wrong shape, `name`, `uri`, `protocolVersion`, `capabilities` or `clientInfo` is missing or malformed, `progressToken` has the wrong type, a `cursor` was sent, or the tool name is not registered. |
| `-32002` | `Resource not found: "…".` | `resources/read` names a URI the consumer does not publish; `error.data.uri` carries it. |
| `-32603` | `Internal error.` | An unexpected exception outside a tool call, including a resource method that throws, or a response the consumer made unencodable. |

A tool that runs and fails is not in this table.
`KinetisMcpApplication::callTool()` catches it and returns a result with
`isError: true`:

- A `ValidationException` carries its violations as
  `{"errors": [...]}` in the text content, each entry with a segmented
  `path`, a stable `code`, a `message` and its `parameters` — the
  structure the HTTP renderer puts in its RFC 9457 document, never that
  document itself. Invalid UTF-8 in a violation is substituted rather
  than failing the encode.
- Any other exception, including a tool result that cannot be
  JSON-encoded, becomes the fixed text `Tool execution failed.`.

A read has no error result of its own: a resource method that throws
becomes `-32603` with the fixed message `Internal error.`. Every
unexpected exception the protocol server catches is redacted the same
way, and its text is discarded rather than reported — a consumer that
wants diagnostics writes them before letting the exception reach that
boundary. A resource method's string return value is its `text`;
anything else is JSON-encoded.

Containment covers the response as well as the exceptions on the way to
it. A consumer chooses the bytes in a tool result, a resource's text, and
a protocol error's own message and data, and any of them can be a string
PHP accepts and JSON refuses. The protocol server checks every envelope
before returning it and answers the same generic `-32603` under the
request's own id when it cannot be encoded — never the bytes, and never a
repaired version of them, since altered content under a successful result
would be a worse answer than an honest failure. Encoding those bytes
where the frame is written would instead end a persistent stdio process
and lose every message queued behind the bad one.

The real exception, in every case, goes to the `Psr\Log\LoggerInterface`
passed to `KinetisMcpApplication`, `NullLogger` by default. The package's
bootstrap passes the container's logger, so `mcp:serve` and `/mcp` both
log through the application's binding (see {doc}`logging`). A logger that
throws is caught and discarded; it can never replace the response or stop
a stdio process.

## Progress streaming

A `Kinetis\Mcp\ProgressReporter`-typed tool parameter is injected by
type. It is excluded from the tool's `inputSchema`, never required of a
call, and an argument of that name sent by a client is
`unexpected_field`.
`report(int|float $progress, int|float|null $total = null, ?string $message = null)`
delegates to the protocol package's own emitter synchronously, on the
tool's own call stack; no Fiber or generator is involved. Without
`_meta.progressToken` on the request, `report()` does nothing, so a tool
calls it unconditionally.

Each report becomes one notification. `total` and `message` are omitted
when not given, rather than written as `null` — an absent optional and an
explicit null are different values, and only the first means "not
reported":

```{code-block} json
{"jsonrpc": "2.0", "method": "notifications/progress", "params": {"progressToken": "reindex-1", "progress": 2, "total": 3, "message": "halfway"}}
```

Over stdio, each notification is one line written before the response
line.

Over HTTP, a `tools/call` request carrying a well-formed
`_meta.progressToken` is answered with `Content-Type: text/event-stream`
and `X-Accel-Buffering: no`. Each notification is one `data: <json>`
event, flushed as `report()` is called, and the final event is the
JSON-RPC response. The status is `200` because headers are sent before
the tool runs, so a JSON-RPC error arrives in that final event. Every
other request gets one buffered JSON response — including one whose
`progressToken` has the wrong type, which is rejected as `-32602` rather
than streamed.

A `tools/call` notification — `id` absent — never opens a stream and
never runs the tool. It gets `202` with no body.

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

**Over stdio**, `mcp:serve` wraps the shared server in
`Kinetis\Mcp\ScopedMessageHandler`, which is where every lifecycle rule
lives: each decoded message gets a scope from
`AppScope::createRequestScope()` with every initializer run, and the
scope is disposed — followed by `gc_collect_cycles()` — in a `finally`
once the response has been computed and before the loop writes its frame.
Disposal never throws: a failure is logged through `AppScope`'s logger,
since the message's own scope is already disposed, and the loop moves on
to the next line. Progress notifications may already have been written by
then; they belong to the tool that was still running. A parse error the
codec answers on its own never reaches the handler, so it creates no
scope.

On both transports, a disposal failure never suppresses a response that
was written and never produces a second JSON-RPC message. A write
failure — a closed or broken stdout, or, on the streamed HTTP transport,
an output-buffer handler throwing during the flush — propagates as the
primary failure, with the scope still disposed underneath it. The data
being written was already encoded once inside the server, so a tool's
result cannot cause it. Nothing about disposal timing changes when
progress notifications are written.

State a tool registers on its scope does not survive to the next
message. Only a hand-rolled transport that drives the protocol server
without `ScopedMessageHandler` shares the dispatcher's own container
across messages.

### Stdio framing

`Kinetis\McpProtocol\StdioLoop` is the transport: one JSON-RPC message
per line on stdin, one frame per response on stdout, synchronous and one
message at a time, which is the backpressure. EOF ends the loop and
returns normally — a client closing the pipe is how a stdio server is
stopped.

Input is read in bounded chunks, never a whole-line `fgets()`, and a
message payload is capped at 2 MiB, matching the framework's default
`MAX_BODY_SIZE`. A line past the cap is drained through its next
terminator or to EOF, answered with exactly one `-32700` under `id: null`,
and the next frame is then processed normally. A full chunk arriving with
no terminator is not oversized on that ground alone: only the accumulated
payload decides, and a final line at EOF with no terminator is a complete
message.

Only `\r` and `\n` are stripped before decoding — never a bare `trim()`,
which would also remove NUL and vertical-tab bytes and turn invalid input
into accepted input. A line holding only spaces or tabs is skipped as a
blank.

Every frame — a progress notification or a response — is written whole:
the loop repeats `fwrite()` until the encoded message and its newline are
written, because `fwrite()` may accept fewer bytes than given when the
reader of a pipe falls behind. A write that stops making progress (a
`false` or a `0`) throws
`Kinetis\McpProtocol\Exception\StdioWriteException` with the bytes
written and the total, and ends the loop, since nothing can safely follow
a partial frame.

A failed progress write is never allowed to reach the tool, where it
would be caught as an ordinary tool failure and that failure then written
into the corrupted stream. The loop records it, skips every later
notification for that message, and re-throws it once the handler returns
— before any final response is attempted.

There is no output cap. A tool result has no universal safe size, so each
consumer bounds its own.

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
Discovery scans the application's production `autoload.psr-4` roots and
every installed package's `extra.kinetis` scan roots;
`MCP_DISCOVERY_PATHS` restricts the application scan (see {doc}`cli`'s
"Restricting discovery"), and a production build compiles the registry
into the cache (see {doc}`caching`).

## See also

- {doc}`mcp` — the task-first guide.
- {doc}`appendix-packages` — `kinetis/mcp-protocol` and its three
  consumers in the package map.
- {doc}`appendix-configuration` — every `MCP_*` key.
- {doc}`middleware` — middleware groups and `CorsMiddleware`.
- {doc}`container` — request scopes and disposal.
