# MCP Documentation Server

`kinetis/mcp-docs` owns the catalogue of this documentation and the
fetch behind it, and serves every page as an MCP resource — plus one
tool that returns a bounded line window of a page — so a coding agent
reads Kinetis's current documentation instead of answering from
training data. It is framework-agnostic: `kinetis/mcp-protocol` is the
only Kinetis package it depends on — not the framework, and not
{doc}`kinetis/mcp <mcp>` — and it knows nothing about your code. To let
an agent call your own application, see {doc}`mcp`.

```{note}
A project that already registers {doc}`orbitron` has these pages
already. Orbitron requires this package and publishes its
`kinetis://docs/*` resources and its window tool from that one
connection, so there is no second server to configure. Install and register `kinetis/mcp-docs` on
its own when you want the documentation without the harness — beside a
client, for any project or none.
```

## Set up on its own, for Claude Code or Codex

For Claude Code:

```{code-block} sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash
```

For Codex:

```{code-block} sh
curl -fsSL https://raw.githubusercontent.com/kinetis-dev/kinetis/main/packages/mcp-docs/setup.sh | bash -s codex
```

The script needs:

- a running Docker daemon;
- the `claude` or `codex` CLI on your `PATH`;
- network access to Docker Hub, Packagist and GitHub.

It needs no PHP, Composer or `sudo`: the install and the server both run
in the `composer:2` image as your own user.

The script installs the package into `~/.kinetis-mcp-docs`, checks that
the server answers through the exact command it registers, and
registers that command as `kinetis-docs` — at user scope in Claude Code.
Start a new session to use it. Set `KINETIS_MCP_DOCS_DIR` to install
elsewhere; the directory must be empty or an earlier install made by
this script. Running the script again reinstalls in place.

Each session start checks for a newer `1.x` release at most once a day,
and a failed check still starts the installed server.
{doc}`appendix-mcp-docs` covers the directory marker, locking and the
update lifecycle.

## Running it directly

Install the package with Composer, on PHP 8.4 or later:

```{code-block} sh
composer require kinetis/mcp-docs
php vendor/bin/kinetis-mcp-docs
```

Register `vendor/bin/kinetis-mcp-docs` with any MCP client that launches
a stdio server as a subprocess. It speaks MCP `2025-06-18`, reads one
JSON-RPC message per line on stdin, writes one response per line on
stdout, and exits when its input closes. Diagnostics go to stderr.

## Resources

Each page is one resource at `kinetis://docs/<slug>`, where the slug is
the page's file name: `kinetis://docs/routing-validation` is
{doc}`routing-validation`, and `kinetis://docs/queue-sql` is
{doc}`queue-sql`. `resources/list` returns every page, and
`resources/read` returns one:

```{code-block} sh
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kinetis://docs/routing-validation"}}' \
    | php vendor/bin/kinetis-mcp-docs
```

The response is one line whose `result.contents[0].text` is the page's
markdown, with `mimeType` `text/markdown`.

The list of pages is fixed in each release of the package; the server
does not discover pages. A page added to the site after your installed
release is not listed until the package is updated, and a URI outside
the list is refused.

## Reading a page in windows

Some pages run to a few thousand lines of markdown, which is more than
some agent clients accept in one tool result. `kinetis_read_doc`
returns one bounded window of a page instead of the whole thing:

```{code-block} sh
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"kinetis_read_doc","arguments":{"uri":"kinetis://docs/appendix-routing-validation","startLine":1,"lineCount":200}}}' \
    | php vendor/bin/kinetis-mcp-docs
```

It takes the page `uri`, an optional `startLine` (from 1, default 1)
and an optional `lineCount` (1 to 200, default 200). The result is one
JSON document reporting `status`, `uri`, `startLine`, `endLine`,
`hasMore` and `content`. Read on by calling again with `startLine` set
to the reported `endLine` plus one; concatenating the windows of one
page reproduces it byte for byte, as long as the page has not changed
on `main` between your calls.

A window ends at `lineCount` lines or 32 KiB of content, whichever
comes first, so `endLine` can fall short of what you asked for — read
it rather than assuming. `hasMore` and `endLine` describe the window
you got. Every call fetches the page again, so they describe that call
alone: nothing is cached, and no cursor or snapshot is held between
calls.

Reading the same URI as a resource returns the page whole, which stays
the simpler path for a page your client can take in one piece.

The full argument, refusal and bound contract is in
{doc}`appendix-mcp-docs`.

## How a page is read

Every read — a whole resource or one window — fetches the page from the
`main` branch of the `kinetis-dev/kinetis` repository over HTTPS and
returns its MyST markdown source as published — not rendered HTML, not
a summary. There is no source, branch or path to configure, no local
copy, and no cache, so a read returns what `main` holds at that moment.
That can describe behavior newer than the Kinetis release your project
pins.

A page that cannot be fetched answers a JSON-RPC error, and the URL and
reason go to stderr. The fetch's TLS, redirect, timeout and size limits
are in {doc}`appendix-mcp-docs`.

## See also

- {doc}`agent-workflow` — where an agent should start: task routing and
  the current-main/installed-version boundary.
- {doc}`orbitron` — the project-local harness that composes this package
  and serves these pages alongside its own tools.
- {doc}`mcp` — the MCP server for your own application's tools and
  resources.
- {doc}`appendix-mcp-docs` — installer, update, protocol and fetch
  mechanics.
