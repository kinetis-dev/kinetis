# Agent instructions

This is a Kinetis application. Kinetis is a PHP framework for
persistent-worker runtimes and non-blocking I/O, and its conventions are
not the ones you would guess from other PHP frameworks. `kinetis/orbitron`
is installed as a development dependency so that you can read this
project's own facts instead of assuming them: the Kinetis context
document, the `kinetis/*` versions actually installed here, a
deterministic check of the project layout, and the current Kinetis
documentation pages on that same connection — as `kinetis://docs/*`
resources, and as bounded line windows through `kinetis_read_doc`.
Orbitron is the whole registration — there is no second documentation
server to set up.

This file is the whole agent contract for this project. `CLAUDE.md` and
`GEMINI.md` import it and add nothing.

## Initialize Orbitron on the earliest turn it is available

Check, at the start of every turn, whether the `orbitron` tools and
resources are listed. This file cannot act between turns — it cannot
initialize the moment the connection appears, only when a turn next
runs — so the earliest turn on which they are listed is the trigger,
whichever turn of the session that turns out to be: the first, or a
later one, once the user has finished the stack's setup, reconnected
the client, or granted approval. Do this before anything else on that
turn.

While they are not yet listed, a request for setup help or to read
these instructions may still be answered from this file and the
project's `README.md`; every other request, and all application work,
stays blocked, and the next turn checks again before going any
further — do not assume a prior turn's absence still holds.

The order matters, because starting the server has one: it runs in a
disposable container derived from this project's `app` service, the
client launches one server process per session, and the client's own
approval sits between the two. Steps 1 to 3 are preconditions. Step 1 is
yours to do; steps 2 and 3 are the user's, so when one of those does not
hold, say so and wait rather than working around it.

1. **The stack has completed its initial setup.** `bin/orbitron-mcp`
   launches a disposable container built from the `app` service's own
   image, sharing its project and vendor mounts but not its process
   lifecycle. `docker compose up --build -d` must have completed at
   least once, so the image is available and its vendor mount is
   populated with dependencies. Confirm the stack is up, and run that
   command from this directory when it is not:

   ```sh
   docker compose ps
   docker compose up --build -d
   ```

2. **The client connected after that.** Ask the user to reload, restart
   or reconnect their client when the MCP configuration arrived or
   changed after their session started, or when an earlier launch was
   attempted before the stack's initial setup completed. Docker itself
   stopping, and the client's own termination, always end an Orbitron
   session; an `app` restart, recreation or rebuild does not, because the
   launcher no longer runs inside that container. A complete `docker
   compose down` is outside that guarantee either way: on Compose
   v5.5.1, a live session keeps the project network in use, so `down`
   can remove `app`, leave the session alive, and still exit nonzero
   over that network being in use. End the client session before
   running a complete `down` — you cannot reconnect the server process
   you are running inside, and reading this file adds no server to a
   session that is already running.

3. **The server is approved.** Project trust and approval of the
   project-local `orbitron` server are the user's decision, in their
   client, under their client's policy. The checked-in `.mcp.json`,
   `.codex/config.toml` and `.gemini/settings.json` register a server
   and change no policy; you cannot grant this from here.

4. **The handshake.** Confirm the `orbitron` tools and resources are
   listed, then:

   - read `kinetis://orbitron/context`;
   - read `kinetis://docs/agent-workflow`;
   - call `orbitron_inspect`, confirm the `checkoutRoot` it reports
     equals `pwd -P` in this directory, and treat the versions it
     reports as the installed ones, in preference to any documentation
     describing Kinetis `main`;
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

## Stay in the checkout the session started in

The `orbitron` server reads the checkout its client was launched from,
for the whole session. After the handshake, do not switch to or create
another checkout or worktree for application work: a different checkout
is a different Orbitron project. To work there, end the session, launch
the client from that checkout, and repeat the handshake — context,
inspect, verify — before editing. The `checkoutRoot` `orbitron_inspect`
reports is the physical host path of the checkout whose
`bin/orbitron-mcp` started the server, and it must equal `pwd -P` in the
checkout you are editing; when they differ, the session is reading
another checkout, and no application change begins. The `projectRoot`
it reports is `/app`, the server's own view of every checkout, and
`orbitron:inspect` run inside the container reports `/app` for both
roots: neither identifies the host checkout.

## Working on the application

Once Orbitron is ready, every task runs the same way:

1. Route the task through the Orbitron documentation page that covers
   it. `kinetis://docs/agent-workflow` names the routes;
   `resources/list` names every page. Prefer `kinetis_read_doc` for a
   page's content — it takes the same `uri` and returns at most 200
   lines and 32 KiB per call, continuing from `endLine + 1` — and read
   the `kinetis://docs/*` resource when you want the page whole and the
   client can take it.
2. Call the installed-source tools for anything version-sensitive — a
   signature, a default, a config key, a failure code — rather than
   searching for the same fact under `vendor/`. Those pages are
   published from Kinetis `main` and can describe behavior newer than
   this project has installed; `orbitron_inspect` and the installed
   source are what is true here. `vendor/` is a Docker volume rather
   than a directory on the host, so the tools are also the way to reach
   it at all. They accept any package this project installed, not only
   `kinetis/*`, so an exact third-party dependency's behavior is settled
   the same way; `orbitron_inspect` names the `kinetis/*` packages and
   `composer.lock` names every other.

   - **First.** Read the package's own `composer.json` for its
     description, requirements, autoload roots and `extra.kinetis`.
   - **Known file.** Derive it from the class name and that autoload
     map, then call `orbitron_search_package_source` for the symbol and
     `orbitron_read_package_source` for a window around a line it
     reports.
   - **Known package, unknown file.** Call
     `orbitron_search_package_source_tree` for a literal string across
     the package, or a directory under it, and read a window around a
     match it reports. Its `hasMore: true` means narrow the query or the
     path; its `package_search_oversize` refusal means narrow the path,
     most commonly to `src`.
   - **Layout.** Call `orbitron_list_package_source` — `.` for the
     package's install root — when the directory layout itself is what
     you need.
   - For a window or a file search, a success reporting `hasMore: true`
     is not a refusal. Continue with `startLine` set to `endLine + 1`
     for a window, or to the last reported match line plus one for a
     search, until the needed evidence is in view or `hasMore` is
     `false`.
   - Read `vendor/<vendor>/<package>` inside the container only
     when no step above yields a file, or
     when a call returns an exact refusal —
     a `status: error` result naming why, such as `package_unknown` —
     that cannot serve the needed evidence; when that happens, record
     what you tried and why before falling back.
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
and correct application-specific claims the work made demonstrably false,
such as its routes, dependencies or runtime behavior. Preserve the
reusable Kinetis and Orbitron setup reference unless the implementation
invalidated it. This is a correctness pass, not permission to rewrite it
for style.

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
