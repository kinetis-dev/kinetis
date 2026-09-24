---
orphan: true
myst:
  html_meta:
    robots: noindex
---

# Agent Workflow

Operating and routing guidance for an AI coding agent working in a
project that uses Kinetis — not a human tutorial. This is the entry
point for that agent: start here, then follow the routes below into the
guide each task actually needs. A human developer wants
{doc}`tutorial` or {doc}`core-concepts` instead.

## Where these pages come from

A project that registers {doc}`orbitron` reaches them as
`kinetis://docs/*` resources on that one connection, alongside
Orbitron's own context and tools; a client that registers
{doc}`mcp-docs` on its own reaches the same resources from that server.
Either way `kinetis/mcp-docs` owns the catalogue and the fetch, the
resource URIs are identical, and the same `kinetis_read_doc` tool reads
one bounded line window of a page — the way to read a page — while
`kinetis_search_doc` finds the lines of one page that contain a literal
string. The whole-page resources are there for when the complete page is
what you need.

## The version boundary

Every page is fetched from `main` at read time, with no local copy and
no cache — so a page can describe behavior newer than the release the
project has installed. A page served here is current documentation, not
proof of the installed version's behavior.

Before a version-sensitive claim governs a decision:

1. **Establish the installed version.** Call `orbitron_inspect` where
   Orbitron is registered, and before editing confirm that its
   `checkoutRoot` equals `pwd -P` in the checkout you edit — a mismatch
   means the session reads another checkout; see Orbitron's
   {ref}`agent contract <the-instructions>`. Otherwise read the
   project's own `composer.lock` or run
   `composer show kinetis/<package>`. Never assume it matches `main`.
2. **Read the matching installed source** when the exact behavior
   matters — a signature, a default, a failure code, a config key. Where
   Orbitron is registered, call `orbitron_read_package_source` with the
   package name and the relative path — any file under that package's
   install root, a root-mapped class beside `composer.json` included —
   and an optional line window; it reads the exact installed source live, on every call. It
   accepts any package this project really installed, so the same call
   settles a third-party dependency's behavior — the AMQP client behind
   a queue backend, say — as well as a `kinetis/*` one; `composer.lock`
   is where a non-Kinetis name comes from, since `orbitron_inspect`
   reports the `kinetis/*` inventory only.
   Read the selected package's own `composer.json` first for its
   description, requirements, PSR-4 roots and `extra.kinetis`.
   When the file is known but the line is not, call
   `orbitron_search_package_source` for a literal string in that file
   and read a window around a line it reports; derive the file from the
   class and that autoload map. When the package is known but the file
   is not, call `orbitron_search_package_source_tree` for a literal
   string across the package, or a directory under it, and read a
   window around a match it reports. Its `hasMore: true` means narrow
   the query or the path; its `package_search_oversize` refusal means
   narrow the path, most commonly to `src`. List a directory with
   `orbitron_list_package_source` — `.` for the package root — when the
   layout itself is what you need. Only when none of those yields a
   file, or a call is refused, open the file under the project's own
   `vendor/<vendor>/<package>` directly — as a shell-only agent does in
   every case. Documentation and interfaces
   alone do not prove installed behavior; the installed source does.

Neither documentation server reads application code. `mcp-docs` has no
file access at all: its two tools read a window of, or search, the same
published page its resources serve. Orbitron's `orbitron_inspect` and
`orbitron_verify` report only Composer's installed metadata and the
project's `composer.json`; its two scaffold tools additionally read the
fixed scaffold paths, and `orbitron_scaffold_apply` writes them.
`orbitron_read_package_source` reads one bounded window of one real
installed, non-root package's own source,
`orbitron_search_package_source` searches one such file for a literal
string, `orbitron_search_package_source_tree` searches one bounded
directory tree of such a package for a literal string, and
`orbitron_list_package_source` lists the direct children of one
directory of such a package; all four stay under the install root of
one installed package, and none reaches anything else. No tool on
either server reads
the *application's* own source — its controllers, its tests, its
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
3. **Read the current guidance** on those pages rather than answering
   from memory: call `resources/list`, then `kinetis_read_doc` with a
   page URI from line 1, and continue from the line it reports only
   while the section the recipe named, or a named unknown, is still
   unresolved. To locate a named unknown in a page you already know,
   call `kinetis_search_doc` with its URI and the literal term, then
   read a window around a line it reports. Read a page whole with
   `resources/read` when the complete page is what you need.
4. **Inspect the matching installed source** for anything
   version-sensitive, as above.
5. **Make the smallest change** that satisfies the task under the
   guidance just read. Once the recipe's required contracts and the
   installed versions are established, implement — any further read
   must answer a named unknown.
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
