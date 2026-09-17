# Appendix: MCP Documentation Server Reference

The mechanics behind {doc}`mcp-docs`: how the setup script decides it
may write to a directory, how installs and updates share that directory,
the methods and errors the server answers, and how a page fetch is
bounded. The wire itself belongs to `kinetis/mcp-protocol` and is
described in {doc}`appendix-mcp`. For setup and use, see {doc}`mcp-docs`.

## The install directory

`setup.sh` installs into `KINETIS_MCP_DOCS_DIR`, or `~/.kinetis-mcp-docs`
when it is unset. It accepts no argument, or `codex`, and nothing else.
Before writing anything it checks that the Docker daemon answers and
that the chosen client's CLI — `claude` or `codex` — is on `PATH`.

The directory must be absent, empty, or already carry the marker file
the script writes into every install it owns: `.kinetis-mcp-docs`, a
regular file holding exactly one fixed line. Ownership is that content,
not the name, so a symlink, a directory or different content under that
name is refused rather than reused, as is a path that exists and is not
a directory. A mistyped `KINETIS_MCP_DOCS_DIR` never has a
`composer.json` written into it.

Every Docker step runs the `composer:2` image as the invoking user's uid
and gid, with `HOME` and `COMPOSER_HOME` under `/tmp`, so nothing it
writes is owned by root and the host needs no PHP, Composer or `sudo`.

## Install, verify and register

One container holds an exclusive `flock` on `.update.lock` in the
install directory while it:

1. removes `composer.lock` and rewrites `composer.json` to require
   `kinetis/mcp-docs` `^1.0`;
2. runs `composer install`;
3. writes the current time to `.last-update-check`, only when the
   install succeeded.

The script then sends four messages through the command it is about to
register — `initialize`, `notifications/initialized`, `resources/list`,
and `resources/read` for `kinetis://docs/index` — and requires exactly
three responses carrying `serverInfo`, `resources` and `contents`. The
notification must draw nothing. The timestamp from step 3 turns that
run's update check into a no-op.

Registration names the server `kinetis-docs`. For Claude Code the script
removes an existing user-scope registration of that name and adds one at
user scope; for Codex it runs `codex mcp remove` and `codex mcp add`.
The registered command runs `sh vendor/kinetis/mcp-docs/start.sh` in an
interactive `composer:2` container over the install directory, so a
client session starts the server.

## Updates

`start.sh` runs from the installed package on every spawn, whether or
not the session reads a page. It takes the same `.update.lock` and reads
`.last-update-check`. When more than 86,400 seconds have passed, it runs
`composer update kinetis/mcp-docs --with-all-dependencies`, which stays
within `^1.0`, and records the time only when the update succeeded. A
spawn inside the window starts immediately; a failed update — no
network, say — starts the installed server anyway, and the next spawn
retries rather than waiting out the window.

A spawn that finds the lock held waits for it, so it starts from a
finished vendor tree and sees the timestamp the other process wrote. A
session spawned while setup runs waits for the install, and the first
spawn after it starts without a check of its own. The lock is an open
file descriptor, which the kernel releases if its holder dies, and
`start.sh` closes it before starting the server, so a long session never
holds off the next update. Composer's output goes to stderr, because
stdout carries protocol frames. An update replaces `start.sh` too, since
it runs from `vendor/`.

## Protocol

The server speaks MCP `2025-06-18` and no other revision, over
`kinetis/mcp-protocol`; {doc}`appendix-mcp`'s "Protocol revision" and
"Stdio framing" are the wire contract, shared with the application MCP
server. `initialize` always answers with `2025-06-18`, whichever revision
the client asked for. Its result declares an empty `resources` capability
— no `tools`, since the catalogue has none, and no `listChanged` or
`subscribe` — names the server `kinetis-mcp-docs` at the package's
version, and carries `instructions` telling the agent to read these pages
rather than answer from memory, naming `kinetis://docs/agent-workflow` as
the starting resource and warning that a served page can describe
behavior newer than the client's installed release. No method depends on
an earlier `initialize`.

| Method | Behavior |
|---|---|
| `initialize` | Selects `2025-06-18` and declares the `resources` capability. |
| `notifications/initialized` | Accepted and answered with nothing, as every notification is. |
| `ping` | An empty result. |
| `resources/list` | The whole catalogue in one response, each entry with `uri`, `name`, `description` and `mimeType` `text/markdown`. No cursor is ever issued, and a request carrying `cursor` is `-32602`. |
| `resources/read` | Requires a non-empty string `uri`, and returns one `contents` entry with that `uri`, `mimeType` `text/markdown` and the page's markdown as `text`. |

There are no tools, prompts or subscriptions.

### Errors

| Code | Answered when |
|---|---|
| `-32700` | The line is not valid JSON. |
| `-32600` | The JSON is not an object — a top-level array (a batch) included — or `jsonrpc` is not `"2.0"`, `method` is missing or empty, or `id` is not a string or an integer. |
| `-32601` | The method is not in the table, including `notifications/initialized` sent with an `id`. |
| `-32602` | `params` is present and not an object — an array, a scalar or `null` — or `initialize` lacks a valid `protocolVersion`, `capabilities` or `clientInfo`, `resources/read` lacks `uri`, or `resources/list` carries `cursor`. |
| `-32002` | `resources/read` names a URI outside the catalogue; `error.data.uri` carries it. No fetch is made. |
| `-32603` | The page could not be fetched or is not valid UTF-8. The message is `Could not read "<uri>".`; the URL and the real reason go to stderr. |

A parse error, a batch and an unreadable `id` answer under `id: null`;
any other error answers under the request's own `id`.

A notification — a valid envelope with no `id` — is never answered and
never dispatched, whatever its method or `params` shape: JSON-RPC 2.0
gives its sender no response to read, and a frame matching no outstanding
request can desynchronize a strict client. A message that fails the
envelope check is not a notification, and still answers with `-32600` or
`-32700` even without an `id`. A well-formed client *response* message is
ignored rather than answered: this server sends no requests.

## Stdio framing

Framing belongs to `kinetis/mcp-protocol` and is described once in
{doc}`appendix-mcp`'s "Stdio framing": bounded reads, a 2 MiB payload cap,
only `\r`/`\n` stripped, and every frame written whole. A write that
makes no progress ends this binary with exit code `1` and a message on
stderr rather than leaving a truncated frame. End of input ends the loop
and the process exits `0`. Nothing but JSON-RPC frames reaches stdout.

## Page fetch

`resources/read` requests
`https://raw.githubusercontent.com/kinetis-dev/kinetis/main/docs/<slug>.md`,
built from constants: there is no origin, ref or path to configure, no
local copy, and no cache between reads.

- TLS verifies the peer and the host name, at TLS 1.2 or later.
- Redirects are not followed, and any status other than `200` fails.
- A 10-second idle timeout and a 30-second total deadline bound the
  request.
- The body is streamed and abandoned once it passes 4 MiB, rather than
  accumulated.

Every failure is `-32603`, with the reason on stderr.

## The catalogue

The resource list is written out in `Kinetis\McpDocs\DocsCatalogue`,
because the package ships no copy of the documentation. A monorepo test
pairs that list against `docs/*.md` in both directions, so a page
without an entry, or an entry naming no page, fails the suite. A release
serves the list it was cut with, while each read fetches that page's
current content from `main`: a page renamed or removed on `main` after a
release stays listed there and answers `-32603` when read.

## See also

- {doc}`mcp-docs` — setup and use.
- {doc}`appendix-packages` — `Kinetis\McpDocs` in the package map.
