# Agent instructions

This is a Kinetis application. Kinetis is a PHP framework for
persistent-worker runtimes and non-blocking I/O, and its conventions are
not the ones you would guess from other PHP frameworks. `kinetis/orbitron`
is installed as a development dependency so that you can read this
project's own facts instead of assuming them: the Kinetis context
document, the `kinetis/*` versions actually installed here, a
deterministic check of the project layout, and the current Kinetis
documentation pages as `kinetis://docs/*` resources on that same
connection. Orbitron is the whole registration — there is no second
documentation server to set up.

This file is the whole agent contract for this project. `CLAUDE.md` and
`GEMINI.md` import it and add nothing.

## Initialize Orbitron before the first application task

Do this once per session, on the first request that would read, plan or
change application code. A question about this file, about Docker, or
about the setup itself does not need it.

The order matters, because starting the server has one: it runs inside
this project's `app` container, the client launches one server process
per session, and the client's own approval sits between the two. Steps 1
to 3 are preconditions. Step 1 is yours to do; steps 2 and 3 are the
user's, so when one of those does not hold, say so and wait rather than
working around it.

1. **The Compose stack is running.** `bin/orbitron-mcp` reaches the
   server through `docker compose exec`, which fails while the `app`
   container is down. Confirm `app` is running, and start the stack from
   this directory when it is not:

   ```sh
   docker compose ps
   docker compose up -d
   ```

2. **The client connected after that.** Ask the user to reload, restart
   or reconnect their client when the MCP configuration arrived or
   changed after their session started, when an earlier launch failed
   while the stack was down, or when the containers were recreated — a
   `docker compose up --build`, a `down`, or any recreation kills the
   live `docker compose exec` process the client is talking to, and the
   client does not relaunch it on its own. You cannot reconnect the
   server process you are running inside, and reading this file adds no
   server to a session that is already running.

3. **The server is approved.** Project trust and approval of the
   project-local `orbitron` server are the user's decision, in their
   client, under their client's policy. The checked-in `.mcp.json`,
   `.codex/config.toml` and `.gemini/settings.json` register a server
   and change no policy; you cannot grant this from here.

4. **The handshake.** Confirm the `orbitron` tools and resources are
   listed, then:

   - read `kinetis://orbitron/context`;
   - read `kinetis://docs/agent-workflow`;
   - call `orbitron_inspect`, and treat the versions it reports as the
     installed ones, in preference to any documentation describing
     Kinetis `main`;
   - call `orbitron_verify`.

5. **Report readiness and continue.** When every step succeeded and
   `orbitron_verify` reports `"status": "pass"`, say so in one line that
   begins `Orbitron ready`, names the installed `kinetis/framework`
   version and the verified namespaces, and then get on with what the
   user asked for:

   ```
   Orbitron ready — kinetis/framework <installed-version>, layout pass (App\, App\Tests\).
   ```

When any step is unavailable, disconnected, or fails — including an
`orbitron_verify` document whose `status` is `error` — do not begin the
application change. Instead:

- name the step that failed and quote the exact error or the document's
  `code` values;
- walk the user through the matching entry under "Diagnostics" in
  `README.md`;
- wait for the user.

Never present an ungrounded answer as a grounded one. Working from
recalled Kinetis knowledge while the handshake is broken is the failure
this contract exists to prevent, and a partial handshake is a broken
one.

## Working on the application

Once Orbitron is ready, every task runs the same way:

1. Route the task through the Orbitron documentation resource that
   covers it. `kinetis://docs/agent-workflow` names the routes;
   `resources/list` names every page.
2. Read the installed source under `vendor/kinetis/` for anything
   version-sensitive — a signature, a default, a config key, a failure
   code. Those pages are published from Kinetis `main` and can describe
   behavior newer than this project has installed; `orbitron_inspect`
   and the installed source are what is true here.
3. Make the smallest change that satisfies the task.
4. Run focused verification in the container:

   ```sh
   docker compose exec app vendor/bin/phpunit --filter SomeTest
   docker compose exec app vendor/bin/phpstan analyse
   ```

   `phpstan.neon` registers two Kinetis rules on top of level 8.
   `NoStaticPropertiesRule` flags a `static` property, which survives
   every request the worker goes on to handle; `NoBlockingIoRule` flags
   a call that waits synchronously instead of yielding, holding the
   whole event loop while it does. They are guardrails over two specific
   mistakes, not a proof that the change is correct on a persistent
   worker — step 5 is still yours to do.
5. Close the change out concisely: request-scoped state stays
   request-scoped, waits yield rather than block, credentials stay where
   they belong, and the documentation still matches the behavior.

At a material milestone — a feature finished, a dependency added, a
route or contract changed — re-read this project's `README.md` framing
and correct claims the work made demonstrably false. That is a
correctness pass, not permission to rewrite it for style.

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
