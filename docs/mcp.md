# Model Context Protocol (MCP)

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/mcp
```
````

`kinetis/mcp` lets an AI agent discover and call your application's
code over the [Model Context Protocol](https://modelcontextprotocol.io).
Mark a method with `#[McpTool]` or `#[McpResource]`, and the
`kinetis mcp:serve` stdio command and the `POST /mcp` HTTP route serve
it; installing the package registers both. The server implements the
`2025-06-18` protocol revision, over the JSON-RPC and stdio mechanics in
`kinetis/mcp-protocol`. {doc}`appendix-mcp` holds the wire contract
behind this page. To give an agent Kinetis's own documentation instead,
see {doc}`mcp-docs` — a separate package, served on its own or through
{doc}`orbitron`.

## Expose a tool and a resource

```{code-block} php
use Kinetis\Mcp\Attributes\McpResource;
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\In;

final readonly class ShippingTools
{
    private const array EUR_PER_KG = ['DE' => 4.5, 'FR' => 5.0, 'US' => 12.0];

    #[McpTool(name: 'quote_shipping', description: 'Quote the shipping price of a parcel')]
    public function quote(
        #[In(['DE', 'FR', 'US'])] string $country,
        #[GreaterThan(0)] int $grams,
    ): array {
        return [
            'country' => $country,
            'priceEur' => round(self::EUR_PER_KG[$country] * $grams / 1000, 2),
        ];
    }

    #[McpResource(
        uri: 'shipping://rates',
        name: 'shipping-rates',
        description: 'Shipping price per kilogram by destination country',
        mimeType: 'application/json',
    )]
    public function rates(): array
    {
        return self::EUR_PER_KG;
    }
}
```

Put the class under any production `autoload.psr-4` root in your
`composer.json`. Discovery finds every public method carrying either
attribute, with no directory convention and nothing to register. The
class resolves from the request's container like a controller, so it
constructor-injects the services it needs. A tool's return value
reaches the agent JSON-encoded as text. A resource that returns a
string is served as-is, and anything else is JSON-encoded, under its
`mimeType` (`text/plain` by default).
Tool names and resource URIs are unique across the application and its
packages; a duplicate fails registration with
`DuplicateDefinitionException`. {doc}`cli`'s "Restricting discovery"
bounds the scan, and production reads tools and resources from the cache
`kinetis build` compiles (see {doc}`caching`).

A call's arguments are one JSON object with a member per parameter. The
tool's published `inputSchema` is generated from the parameters and
their constraint attributes — the ones an HTTP request body uses (see
{doc}`routing-validation`) — so the agent sees `country` as one of three
strings and `grams` as an integer above zero. Values keep their JSON
types: `grams` accepts `2500`, not `"2500"`. A class-typed parameter
receives a JSON object hydrated and validated as that DTO. A missing
argument, an unknown key and a broken rule come back together in one
tool result with `isError: true`, which the agent can correct its call
from (see [Error handling](#error-handling)). A parameter declares one
named type; a union is refused at registration. {doc}`appendix-mcp`'s
"Argument binding" has the complete rules.

## Run over stdio

```{code-block} sh
php vendor/bin/kinetis mcp:serve
```

A local MCP client registers this command and launches it as a
subprocess: one JSON-RPC message per line on stdin, one response per
line on stdout, until stdin closes. `initialize` always answers with
`2025-06-18`, whichever revision the client asked for, and the client
decides whether to continue (see {doc}`appendix-mcp`'s "Protocol
revision").

stdio has no origin check, no authentication and no `MCP_HTTP_PUBLIC`.
The process runs with the application's configuration and belongs to
whoever launched it, so give the command only to a client you trust with
the application.

HTTP middleware does not run on this transport. A tool that requires an
authenticated caller must therefore constructor-inject
`CurrentUserInterface`, even if it also injects a concrete user for
provider-specific claims. Without authentication the interface cannot be
resolved and the tool fails closed; a concrete autowirable user by itself
can instead become a new, disconnected object.

## Serve over HTTP

`POST /mcp` is an ordinary route of your application, served by the
same runtime as the rest of it (see {doc}`runtime-adapters`). It answers
`401` to every request until you decide how callers are identified.
`GET` and `DELETE` answer `405`.

### Securing the HTTP transport

**Establish the caller's identity.** `/mcp` runs the `mcp` middleware
group, whose last member lets a request through only when a middleware
ahead of it has registered `Kinetis\Http\CurrentUserInterface` on the
request's scope. The authentication middleware in `kinetis/auth` and
`kinetis/auth-jwt` both do, so joining the group with an empty subclass
is the whole integration:

```{code-block} sh
composer require kinetis/auth
```

```{code-block} php
use Kinetis\Auth\BearerAuthMiddleware;
use Kinetis\Http\Attributes\AsMiddlewareGroup;

#[AsMiddlewareGroup('mcp')]
final readonly class McpAuthMiddleware extends BearerAuthMiddleware {}
```

`BearerAuthMiddleware` accepts no token on its own. It hands the
`Authorization: Bearer` credential to the `UserProviderInterface` your
application binds, and issuing and storing tokens is the application's
job (see {doc}`auth`). Extend `JwtAuthMiddleware` instead to verify
signed JWTs (see {doc}`auth-jwt`), or join the group with a middleware of
your own that registers `CurrentUserInterface`; registering only a
concrete user class leaves the endpoint closed. A tool
constructor-injects `CurrentUserInterface` and receives the caller of
that message. If the tool also needs claims from `auth-jwt`, inject
`JwtUser` alongside the interface rather than replacing it; the interface
keeps the same tool closed when it is reached over stdio, where this
middleware group does not run.

With that middleware in place, a request carries a token your
`UserProviderInterface` resolves, plus the protocol-version header:

```{code-block} bash
curl -X POST http://localhost:8080/mcp \
    -H "Authorization: Bearer $MCP_TOKEN" \
    -H "Content-Type: application/json" \
    -H "MCP-Protocol-Version: 2025-06-18" \
    -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}'
```

`MCP-Protocol-Version` is the transport's one header, and an MCP client
sets it itself. `initialize` may omit it; every later message must carry
it, and must carry exactly `2025-06-18` — anything else is `400` (see
{doc}`appendix-mcp`'s "Streamable HTTP"). Configure the token as the
client's `Authorization` header. Both authentication middleware read the
credential from that header only, so a token never belongs in the
endpoint URL, where proxies and access logs record it.

**Allow anonymous callers.** An endpoint meant for anonymous
callers, with no authentication middleware in the group, says so:

```{code-block} text
:caption: .env
MCP_HTTP_PUBLIC=true
```

Every tool and resource is then callable by anyone who can reach
`/mcp`. A value that is not a boolean is a configuration error and
leaves the endpoint closed.

**Browser clients: list the origin.** A request carrying an `Origin`
header is refused with `403` unless that exact origin is listed:

```{code-block} text
:caption: .env
MCP_ALLOWED_ORIGINS=https://agent.example.com
```

The list is comma-separated and empty by default. Requests without an
`Origin` header — command-line clients and server-to-server calls — are
unaffected. This check guards against DNS rebinding and is not CORS: a
page served from another origin also needs the application's global
`CorsMiddleware` to admit that origin and allow the `Authorization` and
`MCP-Protocol-Version` request headers (see {doc}`middleware`).

The origin check runs first, your middleware next, and the identity
check last; {doc}`appendix-mcp`'s "The `mcp` middleware group" gives the
priorities and exact responses. A deployment that pre-warms
`.kinetis-cache/` runs `kinetis build` whenever the group's members
change, including a `kinetis/mcp` upgrade — see "Compiled group
membership" there.

## Each message is its own request

Both transports handle every message on a fresh request scope. The tool
class and its dependencies resolve from it, every request-scope
initializer runs on it — so `kinetis/database-bridge`'s
`TransactionGuard` covers a tool as it covers a controller — and it is
disposed once the response is written. The caller's identity and
anything else a middleware or tool registers on the scope never reach
the next message, over stdio as over HTTP. A disposal failure is logged
and never replaces a response already written; see
{doc}`appendix-mcp`'s "Request scope and disposal".

## Progress

```{code-block} php
use Kinetis\Mcp\Attributes\McpTool;
use Kinetis\Mcp\ProgressReporter;

// A method on a tool class whose constructor injects $orders and $index.
#[McpTool(name: 'reindex_orders', description: 'Rebuild the search index for every order')]
public function reindexOrders(ProgressReporter $progress): array
{
    $ids = $this->orders->allIds();
    $done = 0;

    foreach ($ids as $id) {
        $this->index->reindex($id);
        $progress->report(++$done, total: count($ids));
    }

    return ['reindexed' => $done];
}
```

The server injects `ProgressReporter`; it is not an argument and does
not appear in `inputSchema`. A client asks for progress by sending
`_meta.progressToken` with its `tools/call`. Over stdio, each `report()`
writes a `notifications/progress` line before the result. Over HTTP, that
request is answered as `text/event-stream`: progress events as they are
reported, then the result as the final event. A stream has already sent
status `200`, so an error arrives inside that final event. Without a
token, `report()` does nothing, so a tool calls it unconditionally.
{doc}`appendix-mcp`'s "Progress streaming" has the wire shapes.

## Error handling

A tool that runs and fails returns an ordinary result with
`isError: true`, so an agent sees a failed tool rather than a broken
connection. A failed validation carries every violation — path, code,
message and parameters — for the agent to correct its call. Calling
`quote_shipping` with `"grams": 0` returns:

```{code-block} json
{
    "jsonrpc": "2.0",
    "id": 2,
    "result": {
        "content": [{"type": "text", "text": "{\"errors\":[{\"path\":[\"grams\"],\"code\":\"greater_than\",\"message\":\"must be greater than 0.\",\"parameters\":{\"threshold\":0}}]}"}],
        "isError": true
    }
}
```

Any other exception a tool throws returns the fixed text
`Tool execution failed.`, and the exception goes to the application's
logger (see {doc}`logging`). A resource method that throws answers the
JSON-RPC error `-32603` with the message `Internal error.`, also logged.
No exception message reaches the client.

Problems with the request itself — invalid JSON, an unknown method, an
unregistered tool name or resource URI, a malformed parameter — are
JSON-RPC error responses. {doc}`appendix-mcp`'s "Error catalogue" lists
each code, and "HTTP status codes" its status.

## See also

- {doc}`appendix-mcp` — protocol, headers, errors, middleware order and
  scope disposal.
- {doc}`cli` — `mcp:serve` and discovery.
- {doc}`auth` and {doc}`auth-jwt` — identifying `/mcp` callers.
- {doc}`middleware` — middleware groups and `CorsMiddleware`.
- {doc}`routing-validation` — the validation tool arguments share with
  HTTP request bodies.
- {doc}`caching` — tools and resources in the compiled cache.
- {doc}`mcp-docs` — Kinetis's own documentation as MCP resources and
  bounded page windows, from that package's server or from
  {doc}`orbitron`.
