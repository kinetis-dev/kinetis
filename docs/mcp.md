# Model Context Protocol (MCP)

Kinetis ships a native [Model Context Protocol](https://modelcontextprotocol.io)
server — expose tools and resources an AI agent can discover and call
using the same attributes and validation you already use for HTTP routes.

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
under [Transports](#transports) below).

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
php bin/kinetis mcp:serve
```

This is how Claude Desktop, Cursor, and most local MCP clients actually
talk to a server: launched as a subprocess, one JSON-RPC message per line
on stdin, one response per line on stdout. There's nothing to register —
any class anywhere under one of your own PSR-4 roots is found
automatically, the moment `#[McpTool]`/`#[McpResource]` appears on one of
its methods, with no required directory or namespace convention. See
{doc}`cli` for exactly how this discovery works, how to restrict it for a
large application, and how it interacts with production caching.

### Streamable HTTP

The same `McpServer`/`McpRegistry` also work over HTTP for free, via
`Kernel`'s opt-in `$mcp` constructor parameter:

```{code-block} php
use Kinetis\Http\Kernel;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\McpServer;

$mcp = new McpServer($registry, new McpDispatcher($app));
$kernel = new Kernel($app, $router, mcp: $mcp);
```

```{code-block} bash
curl -X POST http://localhost:8080/mcp \
    -H "Content-Type: application/json" \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

`GET /mcp` and `DELETE /mcp` both answer `405` — correct for both protocol
eras, [below](#protocol-eras) — rather than falling through to routing's
`404`. Earlier Streamable HTTP revisions used GET to open a
server-initiated stream and DELETE to terminate a session; Kinetis never
implements either.

### Securing the HTTP transport

Two independent layers, since global middleware — protecting authentication,
logging, whatever else already wraps every request — already covers `/mcp`
too (`Kernel`'s global pipeline covers its entire body, `/mcp` included):

1. **`Origin` validation, always on.** The Streamable HTTP specification
   requires validating the `Origin` header on every incoming connection to
   prevent DNS-rebinding attacks. `Kernel`'s `$mcpAllowedOrigins`
   constructor parameter is an exact list of allowed values, checked
   before anything else on this endpoint runs — empty by default, so any
   request carrying an `Origin` header at all is rejected `403` until you
   list which ones may reach it. A request with no `Origin` header (any
   non-browser client — `bin/kinetis mcp:serve`'s own stdio transport
   never sends one) is unaffected regardless.
2. **`#[AsMcpMiddleware]`, for middleware scoped to just this endpoint.**
   See {doc}`middleware`'s "Scoping middleware to `/mcp` or
   `/openapi.json`/`/docs` specifically" — a real authentication check
   (a bearer token, an API key) belongs here rather than in global
   middleware, if it should apply to `/mcp` only and not every other route.

```{code-block} php
$kernel = new Kernel($app, $router, mcp: $mcp, mcpAllowedOrigins: ['https://my-mcp-client.example']);
```

## Protocol eras

Kinetis's `McpServer` supports **both** MCP protocol eras side by side, in
the same class, with the older one's code path completely untouched by the
newer one:

- **Legacy (2025-03-26)** — the `initialize`/`notifications/initialized`
  connection handshake most real clients still speak today.
- **Modern (2026-07-28)** — a fully stateless, per-request model: every
  request carries its own protocol version and capabilities in
  `params._meta`, replacing connection-level negotiation with a mandatory
  `server/discover` call.

```{code-block} json
:caption: Legacy request

{"jsonrpc": "2.0", "id": 1, "method": "initialize"}
```

```{code-block} json
:caption: Modern request — carries its own version/capabilities per call

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

Which era a message belongs to is decided by the presence of an
`io.modelcontextprotocol/`-prefixed key in `_meta`, not merely by whether
`_meta` exists at all — a *legacy* client can send `_meta.progressToken`
(below) entirely on its own, and that alone doesn't make the request
modern.

A modern request's result is wrapped in the spec's envelope automatically:

```{code-block} json
{"jsonrpc": "2.0", "id": 1, "result": {"resultType": "complete", "tools": [/* ... */]}}
```

### Request headers

A modern request over HTTP mirrors three body fields into headers, so an
intermediary (a load balancer, a gateway) can route or inspect a request
without parsing the body — required on every modern-era request:

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
`HeaderMismatch` error — this check only runs for modern-era requests;
a legacy `2025-03-26` client never sends these headers at all.

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

Over HTTP specifically, a `tools/call` request carrying `_meta.progressToken`
gets a genuine `text/event-stream` response: progress events arrive
incrementally, as the tool calls `report()`, not buffered until the end.
Every other request still gets a single buffered JSON response; this is
additive, not a change to the default shape.

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
`-32603 Internal error` — logged through whatever `Psr\Log\LoggerInterface`
you pass to `McpServer`'s `logger` constructor parameter (see
{doc}`logging`). It defaults to a `NullLogger`, since `McpServer` is
constructed directly rather than resolved through the container; `bin/kinetis
mcp:serve` already wires this through for you.

## Exposing Kinetis's own docs as a resource

`Kinetis\Mcp\KinetisDocsResource` registers every page of this documentation
site as an MCP resource — `kinetis://docs/getting-started`,
`kinetis://docs/routing-validation`, and so on — so an agent working in
*your* codebase can read Kinetis's own docs the same way it reads your
app's resources, instead of relying on stale training data about the
framework:

Included automatically when running `mcp:serve` — nothing to opt into.
For the HTTP transport, where you construct `McpRegistry` yourself,
register it explicitly:

```{code-block} php
use Kinetis\Mcp\KinetisDocsResource;

$registry->register(KinetisDocsResource::class);
```

Each resource returns the actual `docs/*.md` source as `text/markdown`,
read directly from wherever `kinetis/framework` is installed, so there's
nothing to keep in sync as pages change.

## See also

- {doc}`routing-validation` — `Hydrator`/`JsonSchema`, the validation
  machinery MCP tool arguments share with HTTP request bodies.
- {doc}`logging` — registering the logger `McpServer` uses.
- {doc}`caching` — how `bin/kinetis mcp:serve` avoids re-reflecting every
  registered tool on every single call in production.
