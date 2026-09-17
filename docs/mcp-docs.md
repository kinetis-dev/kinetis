# MCP Documentation Server

`kinetis/mcp-docs` is an MCP server that serves every page of this
documentation as a resource, so a coding agent reads Kinetis's current
documentation instead of answering from training data. It runs beside
your MCP client, not inside your application: it depends on no Kinetis
package, including {doc}`kinetis/mcp <mcp>`, exposes no tools, and
knows nothing about your code. To let an agent call your own
application, see {doc}`mcp`.

## Set up for Claude Code or Codex

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
a stdio server as a subprocess. It speaks protocol revisions
`2024-11-05` through `2025-11-25`, reads one JSON-RPC message per line on
stdin, writes one response per line on stdout, and exits when its input
closes. Diagnostics go to stderr.

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

## How a page is read

Every `resources/read` fetches the page from the `main` branch of the
`kinetis-dev/kinetis` repository over HTTPS and returns its MyST
markdown source as published — not rendered HTML, not a summary. There
is no source, branch or path to configure, no local copy, and no cache,
so a read returns what `main` holds at that moment. That can describe
behavior newer than the Kinetis release your project pins.

A page that cannot be fetched answers a JSON-RPC error, and the URL and
reason go to stderr. The fetch's TLS, redirect, timeout and size limits
are in {doc}`appendix-mcp-docs`.

## See also

- {doc}`agent-workflow` — where an agent should start: task routing and
  the current-main/installed-version boundary.
- {doc}`mcp` — the MCP server for your own application's tools and
  resources.
- {doc}`appendix-mcp-docs` — installer, update, protocol and fetch
  mechanics.
