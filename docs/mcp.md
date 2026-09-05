# Model Context Protocol (MCP)

Kinetis's native [Model Context Protocol](https://modelcontextprotocol.io)
server — expose tools and resources an AI agent can discover and call
using the same attributes and validation you already use for HTTP routes.
It ships as its own package, and installing it is the whole setup:

```{code-block} bash
composer require kinetis/mcp
```

That one install registers everything below — the `kinetis mcp:serve`
command, the `/mcp` HTTP endpoint, and Kinetis's own documentation as
readable resources — through the package's `extra.kinetis` declaration,
with nothing to wire by hand. Without the package, none of it exists:
core has no MCP surface of its own.

## Tools and resources

```{code-block} php
use Kinetis\Mcp\Attributes\{McpTool, McpResource};

final readonly class AccountController
{
    #[McpTool(name: 'get_user_status', description: 'Retrieve user status by ID')]
    public function getUserStatus(int $userId): array
    {
        return ['userId' => $userId, 'status' => 'active'];
    }

    #[McpTool(name: 'create_user', description: 'Create a user account')]
    public function createUser(CreateUserRequest $data): array
    {
        return ['name' => $data->name, 'email' => $data->email];
    }

    #[McpResource(uri: 'kinetis://status', name: 'status', description: 'Server status')]
    public function status(): string
    {
        return 'ok';
    }
}
```

Nothing to register by hand — any class anywhere under one of your own
PSR-4 roots is discovered automatically the moment `#[McpTool]`/
`#[McpResource]` appears on one of its methods (more on how that works
under [Transports](#transports) below). Installed packages can
contribute tools and resources the same way, through their own
`extra.kinetis` scan roots — see {doc}`cli`.

A tool name and a resource URI are each globally unique across every
registered class. Two different methods claiming the same name/URI —
whether they land in the same class by mistake, or two different
packages each publish a tool under an identical name — is a hard
registration-time failure (`Kinetis\Mcp\Exception\DuplicateDefinitionException`,
naming both conflicting `Class::method()` pairs), not something that
silently shadows one definition with the other: the schema an agent sees
from `tools/list` must always be the schema `tools/call` actually
invokes. Registering the exact same class a second time — direct or via
discovery finding it more than once — is a harmless no-op instead.

A tool call's arguments always arrive as one flat named JSON object, so
there's no `#[Body]`/`#[Query]` distinction to make the way HTTP routing
needs one (see {doc}`routing-validation`) — every parameter is resolved
from that object the same way. A class-typed parameter (like
`CreateUserRequest` above) is validated using the same constraint
attributes an HTTP request body uses; a failed validation becomes a
normal tool result with `isError: true`, not a transport-level error —
more on that distinction [below](#error-handling).

The tool's JSON Schema input is built automatically from the method's
parameters, so `#[Email]`/`#[MinLength]`/etc. describe an MCP tool's
arguments exactly as precisely as they describe an HTTP request body.

## Transports

### stdio — the primary transport

```{code-block} bash
php vendor/bin/kinetis mcp:serve
```

This is how Claude Desktop, Cursor, and most local MCP clients actually
talk to a server: launched as a subprocess, one JSON-RPC message per line
on stdin, one response per line on stdout. There's nothing to register —
any class anywhere under one of your own PSR-4 roots is found
automatically, the moment `#[McpTool]`/`#[McpResource]` appears on one of
its methods, with no required directory or namespace convention. See
{doc}`cli` for exactly how this discovery works, how to restrict it for a
large application, and how it interacts with production caching.

Every line this transport writes — a progress notification or a final
response — is written in full before moving on: stdout can be a pipe
whose reader falls behind, and PHP's own `fwrite()` is allowed to accept
fewer bytes than given in that case, so a single, unchecked call could
silently truncate a large tool result. A write that genuinely stalls
partway through (rather than merely arriving in smaller chunks) throws
rather than leaving a corrupt, half-written line on the stream.

### Streamable HTTP

`/mcp` is an ordinary route — `Kinetis\Mcp\Http\McpController`,
discovered from this package's own scan root the moment the package is
installed, with nothing to pass to `Kernel`.

It answers `401` until an application opens it: the endpoint's own
middleware group requires either a `CurrentUserInterface` registered for
the request or `MCP_HTTP_PUBLIC=true`, and "Securing the HTTP transport"
below is where that contract and both ways of satisfying it are stated.
The request below therefore carries the credential an `mcp`-group
authentication middleware is there to check; against an endpoint
declared public, drop that header.

```{code-block} bash
curl -X POST http://localhost:8080/mcp \
    -H "Authorization: Bearer $MCP_TOKEN" \
    -H "Content-Type: application/json" \
    -H "MCP-Protocol-Version: 2026-07-28" \
    -H "Mcp-Method: tools/list" \
    -d '{
        "jsonrpc": "2.0",
        "id": 1,
        "method": "tools/list",
        "params": {
            "_meta": {
                "io.modelcontextprotocol/protocolVersion": "2026-07-28",
                "io.modelcontextprotocol/clientCapabilities": {}
            }
        }
    }'
```

Being an ordinary route is what gives every message the full request
lifecycle with nothing special wired: a fresh request scope per message,
tool and resource controllers resolving from it — a tool
constructor-injecting `RequestScope` receives the live scope of its own
call — a dangling transaction rolled back through the same
`TransactionGuardHook` mechanism every HTTP request gets (see
{doc}`persistence`), and disposal once the response is written. State a
tool registers on its scope does not
survive to the next message. The stdio transport gives each line the
same treatment. Only a hand-rolled transport that calls
`McpServer::handle()` without passing a scope keeps the unscoped
behavior.

`GET /mcp` and `DELETE /mcp` both answer the router's own `405` carrying
`Allow: POST` — earlier Streamable HTTP revisions used GET to open a
server-initiated stream and DELETE to terminate a session; the
`2026-07-28` revision this server implements has neither, so neither
method is declared.

### Securing the HTTP transport

The endpoint's own middleware is the `mcp` middleware group, which
`McpController` references like any route references a group — resolved
from each request's own scope, so everything route middleware can do
works here (see {doc}`middleware`). Three layers, in the order they run:

1. **`Origin` validation, always on.** The Streamable HTTP specification
   requires validating the `Origin` header on every incoming connection
   to prevent DNS-rebinding attacks. The package's own
   `McpOriginMiddleware` is a permanent member of the group and reads
   `MCP_ALLOWED_ORIGINS` — a comma-separated exact list, empty by
   default, so any request carrying an `Origin` header at all is
   rejected `403` until the deployment names which ones may connect. A
   request with no `Origin` header (any non-browser client — the stdio
   transport never sends one) passes regardless.

   ```{code-block} text
   :caption: .env
   MCP_ALLOWED_ORIGINS=https://my-mcp-client.example
   ```

2. **Your own authentication, via `#[AsMiddlewareGroup('mcp')]`.**
   Declare membership on the middleware class and it joins the
   endpoint's pipeline at the attribute's default priority `50` —
   after the origin check at `100`, before the identity guard at `0`.
   Because the group resolves from the request's scope,
   `kinetis/auth`'s `BearerAuthMiddleware` and `kinetis/auth-jwt`'s
   `JwtAuthMiddleware` subclasses work here unchanged, and the
   `CurrentUserInterface` they publish reaches the tool:

   ```{code-block} php
   use Kinetis\Auth\BearerAuthMiddleware;
   use Kinetis\Http\Attributes\AsMiddlewareGroup;

   #[AsMiddlewareGroup('mcp')]
   final readonly class McpAuthMiddleware extends BearerAuthMiddleware {}
   ```

   A tool that constructor-injects `CurrentUserInterface` (or resolves
   it from its injected `RequestScope`) sees exactly the identity the
   middleware resolved for this message — the same mechanism an HTTP
   controller already uses. This holds for an ordinary call and a
   progress-streamed one alike: a streamed `tools/call` runs on a
   *second* scope, created after the request's own is disposed (see
   below), and this identity is carried across to it.

   **The portable identity handoff carries both `CurrentUserInterface`
   and a concrete class, when one was published too.**
   `JwtAuthMiddleware` publishes the same authenticated instance under
   both `CurrentUserInterface` *and* its own concrete `JwtUser` class —
   documented on that class specifically because a tool needing a claim
   only `JwtUser` itself exposes (`jti`, for revocation, most commonly)
   has to inject the concrete class directly rather than the interface.
   Both ids resolve to the exact same object, on an ordinary call and a
   streamed one alike; a custom middleware publishing an authenticated
   user under its own concrete class the identical way gets the same
   treatment automatically — the mechanism only ever asks "what else, if
   anything, already resolves to this exact instance," so it works for
   any concrete class, not one hardcoded to a particular auth package
   (this package has no dependency on `kinetis/auth-jwt`/`kinetis/auth`
   at all). A middleware that publishes only `CurrentUserInterface` (the
   plain `BearerAuthMiddleware` case) carries only that — there is no
   second id to preserve.

3. **The identity guard, last.** `McpIdentityGuardMiddleware` is a
   permanent group member at priority `0`, so it sees the scope
   everything ahead of it finished writing to. **`/mcp` answers `401`
   unless the request's scope has a `CurrentUserInterface` registered on
   it, or `MCP_HTTP_PUBLIC=true` is configured.** A rejected request is
   settled before `McpController` is constructed: no message is
   dispatched, no tool or resource runs, and the body is the framework's
   own `{"error": "Unauthenticated."}` — no `WWW-Authenticate`
   challenge, since the scheme belongs to the authentication middleware
   above rather than to this guard, and nothing distinguishing a missing
   identity from a closed endpoint.

   Presence is the test, and the interface is the whole boundary. A
   middleware registering only its own concrete user class publishes
   nothing portable for a tool or another package to depend on, and does
   not open the endpoint — register `CurrentUserInterface` too, as both
   auth packages do.

   ```{code-block} text
   :caption: .env — an endpoint that serves anonymous callers
   MCP_HTTP_PUBLIC=true
   ```

   `MCP_HTTP_PUBLIC` is a typed `Kinetis\Config` boolean read from the
   boot-time environment (see {doc}`config`), so a value that is not a
   recognized boolean throws `InvalidConfigValueException` rather than
   resolving either way.

   The stdio transport has no group, no guard, and no
   `MCP_HTTP_PUBLIC`: the local client that launched the process already
   owns it.

   ```{note}
   **Upgrading a deployment that pre-warms its cache.** A middleware
   group's membership is compiled data (see {doc}`caching`), and a
   published generation is only ever superseded by a cache *format*
   change, which a group gaining a member is not. A generation compiled
   by a `kinetis/mcp` without the guard therefore stays valid and keeps
   serving `/mcp` without it, so run `bin/kinetis build` in the deploy
   that upgrades the package. Development's live discovery, and a
   production deployment that compiles lazily against an empty
   `.kinetis-cache/`, both pick the guard up with nothing extra to do.
   ```

Global middleware wraps `/mcp` too, like every route — the group exists
for what should apply to this endpoint only. That includes
`MaxBodySizeMiddleware` (see {doc}`middleware`): `McpController` reads
the request body via `getContents()`, not a plain string cast, so an
oversized JSON-RPC body — with or without an honest `Content-Length`
header — gets the same `413` any other route gets, before `McpServer`
ever sees a decoded message.

## The protocol

Kinetis's `McpServer` implements the `2026-07-28` revision of MCP: a
fully stateless, per-request model. Every request carries its own
protocol version and capabilities in `params._meta`, and there is no
connection-level handshake — no `initialize`/`notifications/initialized`,
no `ping`. Discovering what a server offers is a mandatory `server/discover`
call instead.

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

`_meta.io.modelcontextprotocol/protocolVersion` and
`_meta.io.modelcontextprotocol/clientCapabilities` are required on
every request — a request missing either is `-32602 Invalid params`; a
request naming an unsupported version is the more specific `-32022
UnsupportedProtocolVersion`, whose `error.data` reports the version
this server actually supports.

Every result is wrapped in the spec's envelope automatically:

```{code-block} json
{"jsonrpc": "2.0", "id": 1, "result": {"resultType": "complete", "tools": [/* ... */]}}
```

### Request headers

A request over HTTP mirrors three body fields into headers, so an
intermediary (a load balancer, a gateway) can route or inspect a request
without parsing the body — required on every request:

```{code-block} text
POST /mcp HTTP/1.1
Content-Type: application/json
MCP-Protocol-Version: 2026-07-28
Mcp-Method: tools/call
Mcp-Name: get_weather

{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "get_weather", ...}}
```

`MCP-Protocol-Version` mirrors `_meta`'s own protocol version;
`Mcp-Method` mirrors `method`; `Mcp-Name` mirrors `params.name` on
`tools/call` or `params.uri` on `resources/read` (required for those two
methods only — every other method has no `name`/`uri` to mirror). A
value that isn't safe as a plain header (non-ASCII characters, control
characters) is sent Base64-encoded, wrapped as `=?base64?{...}?=`:

```{code-block} text
Mcp-Name: =?base64?SGVsbG8sIOS4lueVjA==?=
```

A header that's missing, or that doesn't match the corresponding body
value once decoded, is rejected with `400` and a JSON-RPC `-32020`
`HeaderMismatch` error — checked only once the body itself is a
well-formed JSON-RPC request; see the next section for what happens
when it isn't.

### Malformed requests

Every message — over HTTP, over stdio, or handed to `McpServer::handle()`
directly — passes through the same structural validation before
`method` is ever dispatched on. Invalid JSON syntax is `-32700 Parse
error`; syntactically valid JSON that isn't a well-formed request object
is `-32600 Invalid Request`, which covers a missing/wrong `jsonrpc`
member, a missing/non-string `method`, an `id` outside the supported
string/integer/null domain, and a top-level JSON array — this server
does not support batching, so an array body is rejected outright rather
than treated as anything else.

Only a *structurally valid* request without `id` is a notification and
gets no response at all; a structurally invalid message missing `id`
still gets an error response, with `id: null` since there was nothing
valid to echo back. Over HTTP this means a malformed body never becomes
`202 Accepted` — a `202` there always means a genuine notification was
accepted, never that something unreadable was silently dropped.

`params`, `_meta`, `_meta.` `io.modelcontextprotocol/clientCapabilities`,
and `tools/call`'s own `arguments` are all named objects — a present
value that's a JSON array, a scalar, or `null` instead is `-32602
Invalid params`, never silently treated as empty or absent (only
*omitting* the field entirely means "none given"). This is a genuine
wire-level distinction, not a stylistic preference: `{}` (an empty
object — valid) and `[]` (an empty array — invalid) decode to different
PHP shapes even when both are empty, and a real client sending either
one over the wire gets the response that shape actually means. The same
applies to a present `_meta.progressToken` of the wrong type: it's
rejected rather than silently disabling progress reporting, since a
client that explicitly asked for progress deserves an error, not quiet
non-reporting it can't tell apart from a tool that simply never reports
any.

A caller building a message directly in PHP — a test, or any other
embedder bypassing both transports — has no way to write that same
`{}`-vs-`[]` distinction in a bare array literal, since `[]` is the only
spelling for both. `Kinetis\Mcp\JsonObject` is the explicit escape
hatch: wrap a value in `new JsonObject([...])` to say, unambiguously,
"treat this as an object" — including a genuinely empty one,
`new JsonObject([])`, which `McpServer::handle()` and
`JsonRpcCodec::validateMessage()` both accept exactly like a real `{}`.
It also `json_encode()`s faithfully (as `{}` when empty, or every given
property otherwise) — safe to pass through the standard encode/decode
pair even though it's meant for the direct-array boundary, not the wire.

`McpServer::preflight()` is the one place all of this — envelope
validity plus every nested requirement above, including the
method-specific ones — is checked, side-effect-free, before any tool or
resource is invoked. `tools/call`'s `name` and `resources/read`'s `uri`
are *required*, non-empty strings here, not merely type-checked when
present: a missing or empty one is rejected at this same point, before
any header comparison or streaming decision, rather than surfacing only
once `callTool()`/`readResource()` themselves are reached. A `name`/`uri`
that's a well-formed but *unregistered* string still reaches the normal
dispatch-time lookup — preflight only checks shape, never registry
membership. `handle()` runs the full check unconditionally as its own
first step; over HTTP, `McpController` runs it explicitly too, *before*
the mirrored-header check and before deciding whether to open the SSE
progress stream — a malformed nested value is rejected on its own terms
there, never reported as a header mismatch, and never allowed to commit
the response to `text/event-stream` only to reject it one event later.

A structurally invalid envelope always produces a response, per the
"even when the malformed object omitted id" rule above — but once the
envelope itself is confirmed valid, `id`'s presence reliably tells apart
a request from a notification, and JSON-RPC 2.0 is explicit that a
notification's caller "would not be aware of any errors (like e.g.
Invalid params)" since there is no response to carry one. A notification
whose nested MCP content fails preflight is therefore neither answered
nor dispatched at all — no tool or resource ever runs, and over HTTP the
request gets a plain `202` (never a `400`, and never an SSE stream, even
with an otherwise-valid `progressToken`).

### Caching hints and server instructions

`server/discover`, `tools/list`, and `resources/list` results carry
`ttlMs` (how long a client may consider the result fresh, in
milliseconds) and `cacheScope` (`"public"`, since these describe the
server's own registered tools/resources — identical for every caller).
`resources/read` carries the same `ttlMs`, but `cacheScope: "private"` by
default, since a resource's own content can be caller-specific in a way
this server has no way to know about in general. `tools/call` never
carries either — it's an action, not a cacheable read.

```{code-block} json
{"jsonrpc": "2.0", "id": 1, "result": {"resultType": "complete", "tools": [/* ... */], "ttlMs": 3600000, "cacheScope": "public"}}
```

`server/discover`'s own result can also carry `instructions` — a short,
natural-language description of what this server's tools are for, shown
to an agent alongside its capabilities:

```{code-block} php
$server = new McpServer($registry, $dispatcher, instructions: 'This server manages orders and inventory.');
```

Omitted entirely when not given, rather than sent as an empty string.

## Progress notifications

A long-running tool can report progress on its own call — over **any**
transport, including stdio, with no special-casing needed there since
stdio is already one-message-per-line:

```{code-block} php
#[McpTool(name: 'slow_count', description: 'Reports progress three times')]
public function slowCount(ProgressReporter $progress): array
{
    for ($i = 1; $i <= 3; $i++) {
        sleep(1);
        $progress->report($i, total: 3, message: "step {$i}");
    }

    return ['done' => true];
}
```

`ProgressReporter` is recognized by its type and never counted as one of
the tool's own arguments. Calling `report()` when the caller never opted
in (no `_meta.progressToken` on the request) is always safe — it's simply
a no-op, so tool code never needs to check whether it's in a streaming
context before calling it.

Over HTTP specifically, a `tools/call` **request** — one carrying an `id`,
including `id: null`, since JSON-RPC treats a present `null` id as a
request rather than a notification — that also carries
`_meta.progressToken` gets a genuine `text/event-stream` response:
progress events arrive incrementally, as the tool calls `report()`, not
buffered until the end. Every other request still gets a single
buffered JSON response; this is additive, not a change to the default
shape. Streaming is request-only, deliberately: a `tools/call`
**notification** (`id` entirely absent) never opens the SSE stream, even
with an otherwise well-formed `progressToken` — it gets the same
`202 Accepted`, no-body response every notification gets, and the tool
still genuinely runs (JSON-RPC requires a server to process a
notification, only never reply to one) — its progress events simply have
nowhere to go, the same no-op `report()` already is for any caller that
never opted in.

```{note}
This is deliberately built with no Fiber/generator machinery at all —
`report()` just invokes a closure synchronously, inline, on the same call
stack as the tool method itself. Nothing here needs to suspend or resume
execution; it only needs a way to write output at a specific point during
an already-synchronous call.
```

## Error handling

A tool or resource *executing* and failing — a thrown exception, a failed
`Hydrator` validation — is reported as a normal result with `isError: true`
in its content, **not** a JSON-RPC transport error:

```{code-block} json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "content": [{"type": "text", "text": "{\"errors\":{\"email\":[\"must be a valid email address.\"]}}"}],
        "isError": true
    }
}
```

This is the actual MCP convention: an agent sees "the tool ran, but
failed," rather than a transport-level failure it has no way to
distinguish from a broken connection. Only genuine protocol-level problems
— an unknown method, an unknown tool name, a malformed request — become a
real JSON-RPC `error` response.

What the content carries depends on the failure. A failed validation
carries its real `errors` map, as above — that's the argument feedback an
agent needs to retry correctly. Any other exception carries the fixed
string `Tool execution failed.`, with the real exception going to the
logger instead — an unexpected failure's message can hold SQL error text,
file paths, or anything else internal, none of which belongs in a
response to whatever agent happens to be connected.

A resource method throwing is different: `readResource()` has no inner
try/catch of its own the way a tool call does, so the exception
propagates to `McpServer::handle()`'s own top-level catch and becomes a
JSON-RPC `-32603` error. That envelope's `message` is a fixed, generic
`"Internal error."` — never the caught exception's own message, for the
identical reason a tool's own fixed `"Tool execution failed."` string
is: the exception can carry SQL error text, a file path, or anything
else internal, and none of it belongs to whatever remote client is
connected. Any unexpected exception anywhere else in `handle()` (not
just a resource) is caught and redacted the same way, not only the
resource path.

The real exception, in both cases, goes to whatever
`Psr\Log\LoggerInterface` you pass to `McpServer`'s `logger` constructor
parameter (see {doc}`logging`) — defaulting to a `NullLogger`, since
`McpServer` is constructed directly rather than resolved through the
container; `bin/kinetis mcp:serve` already wires this through for you.
That logging call is itself best-effort: a logger implementation that
throws is caught and discarded, never allowed to replace the response
`handle()` already decided on or escape and crash a long-running stdio
process over what was only ever an observability failure.

### A disposal failure never suppresses an already-computed response

Both transports that create a per-message `RequestScope` — `bin/kinetis
mcp:serve`'s stdio loop, and the streamed HTTP response a `_meta.progressToken`
request gets — attempt to write the JSON-RPC response the message already
produced, then dispose that scope in a `finally` around the whole attempt.
That ordering is deliberately different from a naive `finally`-wraps-
everything shape: the disposal step itself is guaranteed never to throw
(any failure disposing is caught, logged separately through `AppScope`'s
own logger — the message's own scope is already disposed by then — and
discarded), which is exactly what makes it safe to run from inside a
`finally` at all; see {doc}`container`'s own general explanation of why an
ordinary `finally`-based dispose is unsafe everywhere else. Two outcomes
follow from this: a disposal failure can never suppress a response that
was successfully written, and never surfaces as a second JSON-RPC
message; and if the write itself genuinely fails — a closed or broken
stdio stream, or (over the streamed HTTP transport specifically) an
output-buffer handler installed further up the call stack throwing when
the final flush invokes it — that failure still propagates as the real
primary failure exactly as it always would have. This is not a path a
tool's own result can trigger: every JSON-RPC response `handle()` builds
is already `json_encode()`d, and any failure doing so, internally, is
already caught and converted to the ordinary `isError: true` result
before it ever reaches this write step — so the write here is always
re-encoding data already proven safe to encode. Disposal happening
underneath it changes nothing about that propagation, only guarantees the
scope (and every one of its dispose callbacks) is still cleaned up before
it happens. For the stdio transport, a disposal failure specifically
(not an output failure) still lets the read loop move on to the next line
normally — a disposal failure on one message is not a reason to stop
serving the rest of the connection. Notification ordering is unaffected
either way: nothing about disposal timing changes when
`notifications/progress` events are written, only when the scope backing
the call is torn down afterward.

## Exposing Kinetis's own docs as a resource

`Kinetis\Mcp\KinetisDocsResource` registers every page of this documentation
site as an MCP resource — `kinetis://docs/tutorial`,
`kinetis://docs/routing-validation`, and so on — so an agent working in
*your* codebase can read Kinetis's own docs the same way it reads your
app's resources, instead of relying on stale training data about the
framework:

Included automatically on both transports — the class lives under this
package's own scan root, so discovery finds it exactly the way it finds
your application's resources. Registering it explicitly
(`$registry->register(KinetisDocsResource::class)`) is only needed for a
hand-wired `McpRegistry` that never goes through discovery.

Each resource returns the actual `docs/*.md` source as `text/markdown` —
read from the monorepo when developing Kinetis itself, and fetched from
the published documentation otherwise — so there's nothing to keep in
sync as pages change.

### A standalone docs server for Claude Code

No Kinetis project needed for this — one command installs
`kinetis/framework` into its own directory and registers Kinetis's docs
as an MCP server in Claude Code directly:

```{code-block} bash
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/tools/setup-docs-mcp.sh | bash
```

Requires a running Docker daemon and the `claude` CLI already on your
machine — nothing else, no PHP or Composer of your own, and no `sudo`:
everything it does runs as your own user, writing only to
`~/.kinetis-mcp` and your own Claude Code configuration. Start a new
Claude Code session afterward to use it.

The registered server checks for a newer `kinetis/framework` release on
its own, at most once a day, so it stays current without needing to be
set up again.

## See also

- {doc}`routing-validation` — `Hydrator`/`JsonSchema`, the validation
  machinery MCP tool arguments share with HTTP request bodies.
- {doc}`logging` — registering the logger `McpServer` uses.
- {doc}`caching` — how tool/resource discovery is part of the AOT cache
  in production, avoiding live reflection entirely.
