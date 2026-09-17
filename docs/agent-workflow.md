# Agent Workflow

This is the entry point for an AI agent working in a project that uses
Kinetis. Start here, then follow the routes below into the guide each
task actually needs.

## The version boundary

As {doc}`mcp-docs` explains, every page this server serves is fetched
from `main` at read time, with no local copy and no cache — so a page
can describe behavior newer than the release your project has
installed. A page served here is current documentation, not proof of
your installed version's behavior.

Before a version-sensitive claim governs a decision:

1. Read the installed version from the project's own `composer.lock` or
   `composer show kinetis/<package>` — never assume it matches `main`.
2. When the exact behavior matters — a signature, a default, a failure
   code, a config key — open the matching file under the project's own
   `vendor/kinetis/<package>` and read it directly. Documentation and
   interfaces alone do not prove installed behavior; the installed
   source does.

`mcp-docs` knows nothing about the project it runs beside: it has no
tool, no file access, and no way to read `composer.lock` or `vendor/`
itself. That inspection is the calling agent's own job, done with its
own file-reading tools. To let an agent call the *project's own*
application code — its controllers, its data, its queues — the project
installs `kinetis/mcp` (see {doc}`mcp`); this server never does that.

## Workflow

1. **Establish exact installed versions** of Kinetis and every satellite
   package the task touches.
2. **Identify the relevant packages** — framework core plus whichever
   satellites the task needs, such as a queue backend, the database
   bridge, or the HTTP client. {doc}`application-recipes` routes common
   tasks to their packages and pages directly.
3. **Read the current guidance** on those pages through `resources/list`
   and `resources/read`, rather than answering from memory.
4. **Inspect the matching installed source** for anything
   version-sensitive, as above.
5. **Make the smallest change** that satisfies the task under the
   guidance just read.
6. **Run focused verification** — the project's own tests and whatever
   test client the framework provides, scoped to what changed.
7. **Review the change** against {doc}`agent-correctness` before calling
   it done: lifecycle, I/O, security, dependency, documentation, and
   cache closure.

## See also

- {doc}`application-recipes` — routing recipes for common application
  tasks.
- {doc}`agent-correctness` — the review checklist.
- {doc}`mcp-docs` — this server's own setup and resource contract.
- {doc}`mcp` — exposing a project's own application as MCP tools and
  resources.
