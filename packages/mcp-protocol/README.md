<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/mcp-protocol</strong>
  <br>
  <strong>MCP 2025-06-18 over JSON-RPC, as a framework-agnostic library</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/mcp-protocol"><img src="https://img.shields.io/packagist/v/kinetis/mcp-protocol?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-protocol"><img src="https://img.shields.io/packagist/dt/kinetis/mcp-protocol" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-protocol"><img src="https://img.shields.io/packagist/php-v/kinetis/mcp-protocol" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-protocol"><img src="https://img.shields.io/packagist/l/kinetis/mcp-protocol" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

The [Model Context Protocol](https://modelcontextprotocol.io) mechanics
three Kinetis servers share: JSON-RPC 2.0 envelopes, one MCP revision,
typed tool and resource descriptions, progress notifications, and a
checked newline-delimited stdio loop. It requires PHP and nothing else.

## Install

```sh
composer require kinetis/mcp-protocol
```

## What it owns

- **One revision, `2025-06-18`.** `initialize` always selects it,
  whichever revision the client asks for — the specification's rule is
  that a server answers with a version it supports and the client decides
  whether to continue.
- **Envelope validation.** One decode and structural-validation path for
  every transport, preserving JSON object-versus-array identity so `{}`
  and `[]` stay distinct all the way to a tool's arguments.
- **`initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`,
  `resources/read`**, and the notification rules around them.
- **Stdio framing.** Bounded reads, a 2 MiB payload cap, checked partial
  writes, and progress notifications ordered before the final response.

## What it does not own

No framework, container, HTTP transport, attribute, reflection, discovery,
documentation fetching, filesystem policy, application bootstrap, or
connection session. It stores nothing between messages, which is what lets
one instance serve a persistent stdio process and a stateless HTTP route at
the same time.

## Writing a server

A consumer implements `McpApplication` and hands it to `McpServer`:

```php
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ServerInfo;
use Kinetis\McpProtocol\StdioLoop;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;

final class Clock implements McpApplication
{
    public function tools(): array
    {
        return [new ToolDescription(
            'now',
            'Returns the current UTC time.',
            ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false],
        )];
    }

    public function resources(): array
    {
        return [];
    }

    public function callTool(string $name, stdClass $arguments, ProgressEmitter $progress, ?object $context): ToolResult
    {
        return ToolResult::text(new DateTimeImmutable('now', new DateTimeZone('UTC'))->format(DATE_ATOM));
    }

    public function readResource(string $uri, ?object $context): ResourceResult
    {
        throw new LogicException('This server publishes no resources.');
    }
}

$server = new McpServer(new ServerInfo('clock', '1.0.0'), new Clock());

new StdioLoop()->run($server, STDIN, STDOUT);
```

The server resolves `name` and `uri` against those two lists before it
calls anything, so `callTool()` and `readResource()` are only ever reached
for a name the consumer itself published. Capabilities are advertised from
the same lists: the server above offers `tools` and not `resources`.

A consumer needing a per-message unit of work — a container scope, a
transaction — implements `MessageHandler` around the server and passes its
own object as the opaque `$context`, which travels to `callTool()` and
`readResource()` untouched.

## Documentation

[Kinetis MCP guide](https://kinetis.dev/docs/mcp.html) ·
[Appendix: MCP Reference](https://kinetis.dev/docs/appendix-mcp.html)

## License

MIT
