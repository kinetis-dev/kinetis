<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/skeleton</strong>
  <br>
  <strong>The smallest possible runnable Kinetis application</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/v/kinetis/skeleton?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/dt/kinetis/skeleton" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/php-v/kinetis/skeleton" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/skeleton"><img src="https://img.shields.io/packagist/l/kinetis/skeleton" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

One controller, one route, a welcome page — nginx + PHP-FPM, so a code
change takes effect on your very next request with no container
restart. Meant to be copied and grown from, not run as-is.

It also arrives ready for AI-driven development. There is no generic
dashboard and no prebuilt scaffold to grow out of: instead
[`kinetis/orbitron`](https://github.com/kinetis-dev/orbitron) is
installed as a development dependency, wired to your own MCP-capable
coding agent, so the agent builds against the Kinetis packages and
versions this project actually has — and reads the current Kinetis
documentation over the same connection.

## Running it

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 \
    create-project --no-install kinetis/skeleton my-app
cd my-app
cp .env.example .env
docker compose up --build
```

Then open [http://localhost:8080](http://localhost:8080). Docker is the
only thing you need — the containers install the dependencies and run
the app, so no PHP or Composer has to exist on the host. (`--no-install`
is what keeps it that way: it fetches the project without resolving
dependencies, which `docker compose up` then does inside the container
it will run them in.)

The project is yours from that point on. `docker-compose.yml` mounts it
at `/app`, and every path the containers read is inside it.

## Building it with an AI coding agent

Start the stack, open your agent in this directory, and describe what
you want built. `AGENTS.md` is the contract it works to: its first
application task in a session begins by initializing Orbitron, so it
works from this project's real installed versions and verified layout
rather than from recalled framework trivia.

That initialization follows the order starting the server needs:

1. **Bring the stack up** — `docker compose up -d`, with the `app`
   service running. The MCP server lives in that container.
2. **Reload, restart or reconnect the client** when this configuration
   arrived or changed after the session started, when an earlier launch
   failed while the stack was down, or when the containers were
   recreated. A client launches one server process per session, and
   `docker compose exec` dies with the container it entered — nothing
   the agent can do from inside that session brings it back.
3. **Approve the project-local `orbitron` server** under your client's
   own policy; see [Trust and approval](#trust-and-approval).
4. The agent confirms the `orbitron` tools and resources, reads
   `kinetis://orbitron/context` and `kinetis://docs/agent-workflow`,
   calls `orbitron_inspect` and calls `orbitron_verify`.
5. It reports readiness in one line and gets on with your request:

   ```
   Orbitron ready — kinetis/framework <installed-version>, layout pass (App\, App\Tests\).
   ```

An agent with a shell does step 1 itself. Steps 2 and 3 are yours: it
cannot reconnect the server process it is running inside, and it cannot
grant its own approval. When any step fails it is required to stop, name
the step, quote the exact error, and bring you to
[Diagnostics](#diagnostics) rather than guess. A refusal to start is the
contract working.

### What is checked in for that

Already correct for whatever path you cloned into:

- `AGENTS.md` — the agent contract, and the only place it is written.
  `CLAUDE.md` and `GEMINI.md` are one-line imports of it.
- `bin/orbitron-mcp` plus one config file per client — `.mcp.json`,
  `.codex/config.toml` and `.gemini/settings.json`. Each registers one
  stdio MCP server named `orbitron`, launched as `./bin/orbitron-mcp`.

`bin/orbitron-mcp` runs `docker compose exec -T app php
vendor/bin/kinetis-orbitron-mcp` against this project's own directory.
The server lives in the container next to the code it reports on, so
your host still needs no PHP and no Composer, and the agent needs no
absolute path.

That one server is the whole registration. The Kinetis documentation
pages arrive on the same connection as `kinetis://docs/*` resources,
fetched by [`kinetis/mcp-docs`](https://kinetis.dev/docs/mcp-docs.html)
from inside it — there is no second server to configure, and
`kinetis://docs/agent-workflow` is where the agent starts. Those pages
are published from Kinetis `main`, so `orbitron_inspect` and the
installed source under `vendor/kinetis/` stay the authority for anything
version-sensitive.

### What you get from the archive

`composer create-project` hands you everything in this package except
three files, which are how it is published rather than part of an
application: `.gitattributes`, `composer.lock` and
`docker-compose.monorepo.yml`. What that leaves is the application and
the means to check it — `tests/` with the welcome controller's test,
`phpunit.xml`, and `phpstan.neon` at level 8 with two Kinetis rules
registered.

### Verifying a change

Both tools run inside the container, over the project at `/app`:

```sh
docker compose exec app vendor/bin/phpunit
docker compose exec app vendor/bin/phpstan analyse
```

`phpstan.neon` runs at level 8 and adds two rules a general-purpose
analyser has no reason to carry.
`NoStaticPropertiesRule` flags a `static` property, which survives every
request the worker goes on to handle; `NoBlockingIoRule` flags a call
that waits synchronously instead of yielding, holding the event loop and
everything else on it until it returns.

They are guardrails over two specific mistakes, not a complete proof of
persistent-worker correctness. `AGENTS.md` asks for a closing pass by
hand as well — request-scoped state stays request-scoped, waits yield,
credentials stay where they belong, and the documentation still matches
the behavior — and, at a material milestone, a re-read of this README's
framing for claims the work made false.

### Trust and approval

The checked-in files register a server and configure nothing else: no
credential, no trust override, no preapproved tool. Whatever trust and
approval policy your client already runs under is what applies, and it
differs by client — Claude Code prompts about a project-scoped
`.mcp.json` in an interactive session (its documented non-interactive
and policy-managed modes can behave differently), Codex reads
`.codex/config.toml` only for a trusted project, and Gemini CLI may
ignore workspace settings in an untrusted workspace, where an omitted
server `trust` leaves that server's default `false`.

Reload or restart the client when this configuration was added or
changed after the current session started, or when its tool catalog has
not picked the server up yet.

### Client differences

The launcher and the contract are the same everywhere. What differs is
where a client looks and what its own trust boundary is:

| Client | Reads instructions from | Project MCP configuration |
|---|---|---|
| Claude Code | `CLAUDE.md` (imports `AGENTS.md`) | `.mcp.json`, subject to its project-server prompt in an interactive session |
| Codex | `AGENTS.md` | `.codex/config.toml`, read for a trusted project |
| Gemini CLI | `GEMINI.md` (imports `AGENTS.md`) | `.gemini/settings.json`, subject to workspace trust |
| Any other MCP-capable client | `AGENTS.md` | register `./bin/orbitron-mcp` as a stdio server named `orbitron` |

A client that reads none of those three file names still works: point it
at `AGENTS.md` yourself and register the launcher the way it registers
any stdio server.

### Without MCP

The four documents are commands too, so an agent that can only run a
shell — or you, reading them yourself — has all of them:

```sh
docker compose exec app vendor/bin/kinetis orbitron:context
docker compose exec app vendor/bin/kinetis orbitron:inspect
docker compose exec app vendor/bin/kinetis orbitron:verify
docker compose exec app vendor/bin/kinetis orbitron:scaffold
```

`orbitron:scaffold` previews; `orbitron:scaffold --apply` is the only
one of them that writes anything, and what it writes is two fixed files.
See the [Orbitron guide](https://kinetis.dev/docs/orbitron.html) for
every document's shape and exit code.

### Diagnostics

**The container is not running.** `bin/orbitron-mcp` fails immediately
with a `docker compose exec` error, and the client shows the server as
failed to start. Bring the stack up from this directory and restart the
client:

```sh
docker compose up -d
docker compose ps
```

**The containers were recreated.** `docker compose up --build`,
`down`, or any change that recreates `app` kills the `docker compose
exec` process your client is holding, and the client does not relaunch
it. The stack is healthy and the server is gone: restart or reconnect
the client.

**The server shows as disconnected.** Run the launcher yourself — it is
an ordinary command, and a working server answers a handshake on stdin:

```sh
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"manual","version":"0"}}}' \
    | ./bin/orbitron-mcp
```

A JSON-RPC result naming `kinetis-orbitron-mcp` means the bridge and the
server are both fine, and what remains is client-side: its trust and
approval policy, or a tool catalog that has not picked the server up
since the configuration arrived.

**The reported packages are stale.** Orbitron reads Composer's installed
inventory once per server process, and your client launches one server
per session. So after a dependency change —

```sh
docker compose exec app composer require kinetis/orm
```

— restart the client. That launches a fresh server, which reads the new
inventory.

**`orbitron_verify` reports an error.** The `code` in each failed check
names the outcome. This project ships the layout Orbitron admits — one
`autoload.psr-4` prefix mapped to `src/`, one `autoload-dev.psr-4`
prefix mapped to `tests/` — so an error here means `composer.json` has
moved away from it. The
[Orbitron guide](https://kinetis.dev/docs/orbitron.html) lists every
code.

## Using this as a starting point

Start editing what you just created. The whole app is
`src/Http/WelcomeController.php` (one route) and `public/index.php`,
which is the Composer autoloader plus one
`Kinetis\Runtime\HttpStartup::run()` call — the framework owns the
startup program itself. Add your own controllers anywhere under `App\`;
Kinetis discovers them automatically.

Looking for a larger, more realistic example — a database, a queue,
scheduled commands, real-time updates? See
[`kinetis/pingpong`](https://github.com/kinetis-dev/pingpong).

## Working on this package itself

This package is developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo
and published from it; `kinetis-dev/skeleton` is the split mirror the
commands above install from. Inside the monorepo, `composer.json` still
carries the `path` repositories that resolve `kinetis/framework`,
`kinetis/orbitron`, `kinetis/mcp-docs` and `kinetis/mcp-protocol` from
sibling checkouts, so the stack needs the override that mounts them:

```sh
docker compose -f docker-compose.yml -f docker-compose.monorepo.yml up --build
```

Container paths are `/app` either way — that override adds mounts and
changes nothing else.

## License

MIT — see [LICENSE](LICENSE).
