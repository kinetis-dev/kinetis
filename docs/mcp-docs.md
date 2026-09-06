# MCP Documentation Server

````{note}
A standalone package, and not part of core. It depends on no Kinetis
package at all — including {doc}`kinetis/mcp <mcp>`.

```{code-block} sh
composer require kinetis/mcp-docs
```
````

`kinetis/mcp-docs` serves every page of this documentation site as an
MCP resource, so an agent working in any codebase can read Kinetis's own
documentation rather than answering from training data. It is the
counterpart to {doc}`mcp`, which exposes *your* application's tools and
resources: this package exposes only these pages, and installing it in a
Kinetis project is neither required nor useful.

## Setup

One command installs the server into its own directory and registers it
with Claude Code:

```{code-block} sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash
```

Pass `codex` to register it with Codex instead:

```{code-block} sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash -s codex
```

The script needs a running Docker daemon and the chosen client's CLI on
your `PATH` — no PHP or Composer of your own, and no `sudo`. It installs
`kinetis/mcp-docs` from Packagist into `~/.kinetis-mcp-docs` (override
with `KINETIS_MCP_DOCS_DIR`), verifies the server over a real handshake,
and registers it with the one client you named. Every Docker step runs
as your own user id and group id, so nothing it writes is owned by root.

The target directory must be empty, or already carry the marker file
the script writes into the installs it owns: a regular file holding
exactly the one line it writes there. The name alone proves nothing, so
a symlink, a directory or different content under that name is refused
rather than reused — a mistyped `KINETIS_MCP_DOCS_DIR` never has a
`composer.json` written over it.

The registered command is the package's own `start.sh`: it looks for a
newer release, at most once a day, and then hands stdin and stdout to
the server. A spawn inside that window starts immediately, a failed
check still starts the installed server, and only a successful update
moves the timestamp, so the next spawn retries rather than waiting out
the rest of the window. The check and any update it runs hold an
exclusive lock on the install directory, so a spawn arriving while
another one is updating waits for it and starts from the finished tree.

## Running it directly

The package ships one binary, which speaks JSON-RPC over stdin and
stdout:

```{code-block} sh
php vendor/bin/kinetis-mcp-docs
```

Register that command with any MCP client that launches a server as a
subprocess. It reads one message per line, writes one response per line,
and ends when its input closes. Diagnostics go to stderr; stdout carries
nothing but protocol frames.

## What it implements

Protocol revisions `2024-11-05`, `2025-03-26`, `2025-06-18` and
`2025-11-25`. `initialize` answers with the client's own version when it
is one of those, and with `2025-11-25` when it is not.

| Method | Behavior |
| --- | --- |
| `initialize` | Declares the `resources` capability and names the server. |
| `notifications/initialized` | Accepted, and answered with nothing, as a notification is. |
| `ping` | An empty result. |
| `resources/list` | The whole catalogue in one response; no cursor is ever issued. |
| `resources/read` | The named page's markdown as `text/markdown`. |

Anything else is `-32601`. A batch — a JSON array at the top level — is
`-32600`: it is not implemented, and it carries no id to answer under.
A malformed line is `-32700`, a malformed envelope or parameter is
`-32600`/`-32602`, a URI outside the catalogue is `-32002`, and a page
that could not be read is `-32603`, with the URL and the real reason
going to stderr rather than into the response.

`params` must be an object whenever it is present. A JSON array is a
params shape JSON-RPC itself allows, but every method here takes named
parameters, so an array, a scalar or an explicit `null` is `-32602`.

Every code above answers a *request*. A notification — `jsonrpc`, a
string `method`, and no `id` at all — draws no line back under any of
them, including for an unknown method or a `params` shape a request
would be refused for: JSON-RPC 2.0 leaves its sender no response to
read an error from, and a frame a client has no outstanding request to
match is one a strict client can desynchronize on. A message that fails
the envelope check is not a notification — a missing `id` says nothing
in an envelope that could not be read — and still answers with its
`-32600` or `-32700` under a null id.

## Resources

Each page is one resource, at `kinetis://docs/<slug>`, where the slug is
the page's own file name — `kinetis://docs/routing-validation` for
{doc}`routing-validation`, `kinetis://docs/queue-sql` for
{doc}`queue-sql`, and so on for every page in the sidebar.

The catalogue is a fixed list in the package, since the package ships no
copy of the documentation. A repository test pairs that list against
this site's own pages, so a page added here without an entry — or an
entry naming a page that no longer exists — fails the suite.

## How a page is read

`resources/read` fetches the page's published markdown from the
`kinetis-dev/kinetis` monorepo's `main` branch over HTTPS, and returns
it as-is: real MyST source, not rendered HTML or a summary. There is no
origin, ref or path to configure, no local copy to prefer, and nothing
cached between calls, so a read always returns what `main` carries at
that moment. The one consequence worth knowing: the server describes
`main`, which can be slightly newer than an older pinned install of the
framework.

The request verifies TLS, follows no redirect, is bounded by both an
idle timeout and a total deadline, and streams the body so a response
past 4 MB is abandoned rather than accumulated.

## See also

- {doc}`mcp` — the MCP server for your own application's tools and
  resources, over stdio and HTTP.
- {doc}`cli` — `kinetis mcp:serve`, that server's own stdio transport.
