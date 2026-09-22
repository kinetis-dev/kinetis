# Orbitron

Kinetis does not hand you a generic dashboard or force your application
into a prebuilt scaffold. It gives you something more adaptable:
Orbitron, the development harness that equips your chosen AI coding
agent with this project's own Kinetis context, package inspection,
layout verification, controlled scaffolding, and the current Kinetis
documentation — all on one connection.

`kinetis/orbitron` is a development-only harness, not an agent: it
contains no model, talks to none, and generates no application of its
own. You bring the coding agent; Orbitron gives it the facts to build
against — the packages and versions actually installed in *this*
project, not whatever an agent recalls from training. None of what it
returns is evidence that your application code is correct —
{doc}`agent-correctness` and your own test suite are.

Reach it either way: any agent that can run a shell uses four commands
on `vendor/bin/kinetis`, and an agent that speaks MCP registers
`vendor/bin/kinetis-orbitron-mcp` and calls the same documents as tools,
plus this documentation as resources on that same connection. Both
adapt the same services, so neither can report something the other does
not. {doc}`appendix-orbitron` has the complete contract — every
command's schema, every MCP tool, and the exact files a project wires
in.

## Start a new project

```console
docker run --rm -v "$PWD":/app -w /app composer:2 \
    create-project --no-install kinetis/skeleton my-app
cd my-app
cp .env.example .env
docker compose up --build -d
```

`kinetis/skeleton` arrives with Orbitron already wired in — the MCP
server registration, the agent contract, everything below is already
checked in. From here:

1. **Open or reconnect your MCP-capable coding agent** in this
   directory — Claude Code, Codex, Gemini CLI, or any other client that
   reads `AGENTS.md` and can launch a stdio MCP server.
2. **Approve the project-local `orbitron` server** the first time your
   client asks. These checked-in files register a server; they change
   no trust or approval policy of their own, so your client's usual
   policy decides.
3. **Give your agent its first request.** On the earliest turn its
   `orbitron` tools and resources are available — ordinarily its first,
   once you have approved the server — it initializes Orbitron before
   touching anything else, then reports:

   ```
   Orbitron ready — kinetis/framework <installed-version>, layout pass (App\, App\Tests\).
   ```

   That line means the agent read this project's real installed
   versions and verified layout instead of guessing. Only after it does
   your request.

An agent with a shell does step 1 for you if the stack is not already
up. Steps 2 and 3 depend on you: an agent cannot reconnect the client
it is running inside, and it cannot grant its own approval — one that
cannot do either says so and waits rather than working around it.

## Add it to an existing project

```console
composer require --dev kinetis/orbitron
```

Then check in the same four pieces the skeleton ships: `bin/orbitron-mcp`
(a one-file Docker bridge, when the project runs in containers),
`AGENTS.md` (the whole agent contract), `CLAUDE.md`/`GEMINI.md` as
one-line imports of it, and one MCP configuration file per client
(`.mcp.json`, `.codex/config.toml`, `.gemini/settings.json`). None of
this widens any trust or approval — it registers a server and nothing
else. {doc}`appendix-orbitron`'s {ref}`equip-an-existing-project` has
every file verbatim.

A shell-only agent loses nothing: the same four documents are commands
under `vendor/bin/kinetis`, run inside the container that has them
installed:

```console
docker compose exec app vendor/bin/kinetis orbitron:context
docker compose exec app vendor/bin/kinetis orbitron:inspect
docker compose exec app vendor/bin/kinetis orbitron:verify
docker compose exec app vendor/bin/kinetis orbitron:scaffold
```

## Troubleshooting

| Symptom | Start here |
|---|---|
| The server fails to start | `docker compose up --build -d`, then reconnect your client — the stack has not completed its initial setup |
| The server was there and is gone | Docker stopped, or the client itself ended — reconnect it; restarting `app` alone does not cause this |
| The server shows as disconnected | Run the launcher by hand; a handshake reply means the client side — trust, approval, or a stale tool catalog — is what to check |
| `orbitron_verify` reports `error` | Your `composer.json` layout is outside what Orbitron admits — the failed check's `code` names why |
| A documentation read fails | The fetch to the documentation origin failed — every other document is local and unaffected |

{doc}`appendix-orbitron`'s {ref}`diagnostics` covers every symptom, with
the exact commands to run.

## See also

- {doc}`appendix-orbitron` — the complete contract: every command's
  schema and exit codes, the installed-source tools, the full MCP
  catalogue, and the trust boundary.
- {doc}`agent-workflow` — where your agent routes a task once Orbitron
  is ready.
- {doc}`mcp-docs` — the documentation server Orbitron composes for the
  `kinetis://docs/*` resources, installable on its own without the
  harness.
