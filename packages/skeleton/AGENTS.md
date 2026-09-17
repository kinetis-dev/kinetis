# Agent instructions

This is a Kinetis application. Kinetis is a PHP framework for
persistent-worker runtimes and non-blocking I/O, and its conventions are
not the ones you would guess from other PHP frameworks. `kinetis/orbitron`
is installed as a development dependency so that you can read this
project's own facts instead of assuming them: the Kinetis context
document, the `kinetis/*` versions actually installed here, and a
deterministic check of the project layout.

This file is the whole agent contract for this project. `CLAUDE.md` and
`GEMINI.md` import it and add nothing.

## Initialize Orbitron before the first application task

Do this once per session, on the first request that would read, plan or
change application code. A question about this file, about Docker, or
about the setup itself does not need it.

1. Confirm the `orbitron` MCP server is connected and its tools are
   listed.
2. Read the resource `kinetis://orbitron/context`.
3. Call `orbitron_inspect`. Treat the versions it reports as the
   installed ones, in preference to any documentation describing
   Kinetis `main`.
4. Call `orbitron_verify`.

When all four steps succeed and `orbitron_verify` reports
`"status": "pass"`, report readiness in one line that begins
`Orbitron ready`, names the installed `kinetis/framework` version and
the verified namespaces, and then continue with what the user asked
for:

```
Orbitron ready — kinetis/framework 1.11.3, layout pass (App\, App\Tests\).
```

When any of the four steps is unavailable, disconnected, or fails —
including an `orbitron_verify` document whose `status` is `error` — do
not begin the application change. Instead:

- say which step failed and quote the exact error or the document's
  `code` values;
- walk the user through the matching entry under "Diagnostics" in
  `README.md`;
- wait for the user.

Never present an ungrounded answer as a grounded one. Working from
recalled Kinetis knowledge while the handshake is broken is the failure
this contract exists to prevent, and a partial handshake is a broken
one.

The server is already registered: `.mcp.json`, `.codex/config.toml` and
`.gemini/settings.json` are checked in. What is not yours is the
boundary around it — project trust and server approval are the user's
decision, in their client, under their client's policy. You cannot
change that from here, and reading this file cannot register or
reconnect anything: a Markdown file adds no server to a session that is
already running. When the configuration arrived after their session
started, or their tool catalog has not picked the server up yet, the
reload or restart is theirs to do.

## Scaffolding

`orbitron_scaffold_apply` is the only Orbitron tool that writes. It
creates two fixed files — `src/Http/HealthController.php` and
`tests/Http/HealthControllerTest.php` — and nothing about them is
configurable.

Call `orbitron_scaffold_plan` first and show the user the plan it
returns. Call `orbitron_scaffold_apply` only when the user has asked for
that health endpoint. Do not reach for it because a health endpoint
looks useful, and do not use it as a warm-up for an unrelated task.

Every other file in this application you write yourself, as ordinary
application code.

## This project

- Production code is `App\` in `src/`; tests are `App\Tests\` in
  `tests/`. Controllers, commands, listeners and MCP tools are
  discovered from their attributes — there is no registry to edit.
- The application runs in Docker. Run commands inside the container:
  `docker compose exec app vendor/bin/phpunit`,
  `docker compose exec app vendor/bin/kinetis`.
- Reach for the guides the context document links to for anything the
  document itself does not answer.
