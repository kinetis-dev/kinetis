<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/mcp-docs</strong>
  <br>
  <strong>The Kinetis documentation, as an MCP server</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/mcp-docs"><img src="https://img.shields.io/packagist/v/kinetis/mcp-docs?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-docs"><img src="https://img.shields.io/packagist/dt/kinetis/mcp-docs" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-docs"><img src="https://img.shields.io/packagist/php-v/kinetis/mcp-docs" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/mcp-docs"><img src="https://img.shields.io/packagist/l/kinetis/mcp-docs" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

A [Model Context Protocol](https://modelcontextprotocol.io) server that
serves every page of the Kinetis documentation as a resource, so an
agent working in any codebase can read the framework's own docs instead
of answering from training data. It depends on no Kinetis package: the
pages are fetched as published markdown from the monorepo's `main`
branch.

For a server that exposes *your own* application's tools and resources,
install [`kinetis/mcp`](https://github.com/kinetis-dev/mcp) instead.

## Setup

One command installs the server into its own directory and registers it
with Claude Code:

```sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash
```

Pass `codex` to register it with Codex instead:

```sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash -s codex
```

It needs a running Docker daemon and the client's own CLI on your PATH —
no PHP or Composer of your own, and no `sudo`.

## Running it directly

```sh
composer require kinetis/mcp-docs
php vendor/bin/kinetis-mcp-docs
```

The binary speaks JSON-RPC over stdin and stdout, one message per line:
`initialize`, `notifications/initialized`, `ping`, `resources/list` and
`resources/read`, across protocol revisions `2024-11-05` through
`2025-11-25`. Register that command with any MCP client that launches a
server as a subprocess.

Requires PHP 8.4+. Full documentation:
[kinetis.dev/docs/mcp-docs.html](https://kinetis.dev/docs/mcp-docs.html).

## License

MIT — see [LICENSE](LICENSE).
