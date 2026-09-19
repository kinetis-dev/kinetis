# Agent Workflow

This is the entry point for an AI agent working in a project that uses
Kinetis. Start here, then follow the routes below into the guide each
task actually needs.

## Where these pages come from

A project that registers {doc}`orbitron` reaches them as
`kinetis://docs/*` resources on that one connection, alongside
Orbitron's own context and tools; a client that registers
{doc}`mcp-docs` on its own reaches the same resources from that server.
Either way `kinetis/mcp-docs` owns the catalogue and the fetch, and the
resource URIs are identical.

## The version boundary

Every page is fetched from `main` at read time, with no local copy and
no cache — so a page can describe behavior newer than the release the
project has installed. A page served here is current documentation, not
proof of the installed version's behavior.

Before a version-sensitive claim governs a decision:

1. **Establish the installed version.** Call `orbitron_inspect` where
   Orbitron is registered; otherwise read the project's own
   `composer.lock` or run `composer show kinetis/<package>`. Never
   assume it matches `main`.
2. **Read the matching installed source** when the exact behavior
   matters — a signature, a default, a failure code, a config key. Where
   Orbitron is registered, call `orbitron_read_package_source` with the
   package name and the relative path — `composer.json`, `README.md`, or
   a file beneath `src/`, `bin/` or `resources/` — and an optional line
   window; it reads the exact installed source live, on every call.
   When the file is known but the line is not, call
   `orbitron_search_package_source` for a literal string in that file
   and read a window around a line it reports. Derive the file from the
   class and that package's own `composer.json` autoload map, or search
   the package's `README.md` for the option or term to find the file;
   neither tool lists a directory or searches a package, so an unknown
   file is not something either can refuse. Only when neither yields a
   file, or a call is refused, open the file under the project's own
   `vendor/kinetis/<package>` directly — as a shell-only agent does in
   every case. Documentation and interfaces alone do not prove installed
   behavior; the installed source does.

Neither documentation server reads application code. `mcp-docs` has no
tool and no file access at all. Orbitron's `orbitron_inspect` and
`orbitron_verify` report only Composer's installed metadata and the
project's `composer.json`; its two scaffold tools additionally read the
fixed scaffold paths, and `orbitron_scaffold_apply` writes them.
`orbitron_read_package_source` reads one bounded window of one installed,
non-root `kinetis/*` package's own source, and
`orbitron_search_package_source` searches one such file for a literal
string; neither reaches anything else. None of the six tools reads the
*application's* own source — its controllers, its tests, its
configuration — so that inspection is still the calling agent's own,
done with its own file-reading tools when Orbitron's installed-source
tools are unavailable. To let an agent call the project's
own application code — its controllers, its data, its queues — the
project installs `kinetis/mcp` (see {doc}`mcp`); neither documentation
server does that.

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
- {doc}`orbitron` — the project-local harness: installed versions,
  layout verification, and these pages on one connection.
- {doc}`mcp-docs` — the catalogue and fetch behind these resources, and
  how to register it on its own.
- {doc}`mcp` — exposing a project's own application as MCP tools and
  resources.
