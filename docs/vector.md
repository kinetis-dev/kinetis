---
orphan: true
myst:
  html_meta:
    robots: noindex
---

# Vector

Routing guidance for an AI coding agent working on a Kinetis application
that may also use `kinetis/vector`, the optional schema-driven admin
package. Vector is not part of every Kinetis project.

**Before anything else**, call `orbitron_inspect` where Orbitron is
registered, or read the project's own `composer.lock`, and confirm
`kinetis/vector` is actually installed. When it is absent, nothing on
this page applies — an application with no admin package has no Vector
correctness surface, and installing Vector because this page exists is
not a reason: install it only when the task or the user actually asks
for an admin.

## The version boundary

This page is fetched from `main` on every read, the same as every page
{doc}`agent-workflow` routes through, so it can describe a Vector newer
than the one installed. Once `orbitron_inspect` confirms the package,
read the *installed* `kinetis/vector` package's own `README.md` and
source — with `orbitron_read_package_source`,
`orbitron_search_package_source`, `orbitron_search_package_source_tree`
and `orbitron_list_package_source`, or the project's own
`vendor/kinetis/vector` when Orbitron is unavailable — for the exact
contract this page only routes to. The separately installed
`@kinetis/vector-ui` (a standalone deployment's own npm package) is
JavaScript neither this server nor Orbitron's installed-source tools
reach; inspect its `package.json`, lockfile and source with the
project's own file-reading tools instead.

## Task routing

Each item names where the installed README documents the contract; read
that section before writing code.

- **Install, migrations, and Vector's own connection** — `vector:install`
  (idempotent, re-run after every upgrade), `migrate`, and
  `VECTOR_DB_CONNECTION` when Vector's tables live apart from the
  application's default connection: the README's "Installation" and
  "Multi-database applications".
- **Resources, fields, records, lists, forms, actions, dashboards,
  settings, audit** — a `VectorResource` subclass's `fields()`,
  `actions()`, `widgets()` and a `SettingsGroup` compile into the
  descriptor at build time: the README's "Resources", "Settings groups"
  and "The REST contract".
- **Authentication/authorization and concealment** — the `vector`
  middleware group and `VectorAccessInterface` decide who enters at all;
  a resource's `policy()`, `scope()` and `viewField()` decide what an
  admitted user sees: the README's "Authentication and authorization"
  and "Policies".
- **REST and application MCP** — every route sits under `/vector` in the
  `vector` group; `vector_resources`, `vector_describe`, `vector_query`
  and the write tools call the same services under the same identity:
  the README's "The REST contract" and "MCP tools".
- **Files/images plus CSV/XLSX import/export** — upload/download
  endpoints, format detection from bytes, and XLSX's job-only path: the
  README's "Files and images" and "XLSX exports".
- **Synchronous versus queued work, terminal job state, and reaping** —
  which actions and exports run inline versus in a job, how a job claims
  its row, and how a stalled row is reaped: the README's "Background
  jobs" and "Lifecycle guarantees and limits".
- **Hosted versus standalone UI and runtime extensions** — the two
  deployment modes and the three customisation tiers: the installed
  `docs/deployment-modes.md` and the README's own deployment section.
- **Named/multiple database, queue and storage topology** — an entity on
  a named connection, Vector's own tables on another connection, and the
  `VECTOR_QUEUE`/`VECTOR_QUEUE_CONNECTION`/`VECTOR_STORAGE_CONNECTION`
  keys: the README's "Multi-database applications" and "Configuration
  keys".

## Correctness boundaries this campaign established

- **Definitions are compiled and request-neutral.** The descriptor
  compiles once — at `kinetis build`, or on first boot in development —
  and is immutable; a resource's compile-time methods return the same
  data for every request. {doc}`caching`.
- **Request-time identity and policy remain request-scoped.**
  `CurrentUserInterface`, a policy decision, and an impersonation are
  resolved fresh per request, or reconstructed fresh per job by
  `ActorResolverInterface`; none of them is cached on `AppScope` or held
  in a resident Fiber. {doc}`container`.
- **Vector's own database connection is explicit.** `VECTOR_DB_CONNECTION`
  is a deliberate setting (default: the application's default
  connection), never inferred from an administered entity's own
  connection.
- **ORM relationships and atomic transactions do not span databases.** A
  relation the ORM would have to map across two connections fails at
  build time, and each connection commits on its own — Vector's own rows
  (audit, action log, jobs) commit separately, after the entity write
  they describe. {doc}`orm`, {doc}`persistence`.
- **Queue acceptance is not business completion.** A queued action,
  export or import answers only that the job was pushed; the job's own
  terminal state, not the push, is what proves the work happened.
  {doc}`queue`.
- **Actor reconstruction and terminal job state must be proven.** A job
  runs as the admin `ActorResolverInterface` rebuilds from an id, and a
  worker that dies mid-job leaves the row `running` until
  `vector:jobs:reap` closes it as `stalled` — neither is established by
  a passing push.
- **Files, spreadsheets, queues and UI modes are optional capabilities.**
  The descriptor's own `capabilities` (`uploads`, `exportFormats`,
  `queue`) report what is actually configured; do not assume uploads,
  XLSX or queued work from a field or action declaration alone.
- **Hosted/standalone authentication and CSP are separate deployment
  concerns.** A bearer token on another origin and a session cookie with
  CSRF under the hosted shell are not interchangeable, and the hosted
  shell's Content-Security-Policy is the deploying application's own
  header, not something Vector supplies.

## Verification checklist

- **Boot/compile** — the application boots, or `kinetis build` compiles,
  with every resource, action, widget and settings group the change
  touches; a deliberate build-time mistake (an unmapped field, an
  unregistered relation target) fails with the class and field named.
- **Authorization and concealment** — a user a resource policy or
  `viewField()` restricts gets the same refusal through the REST route
  and the MCP tool alike, and a concealed field is absent everywhere:
  the descriptor, records, audit, exports and filters/sorts naming it.
- **Real backend ownership** — a database, queue or storage claim is
  checked against the connection, queue name or filesystem Vector uses,
  rather than inferred from configuration; see
  {doc}`agent-correctness`'s "Read installed source to settle a fact".
- **Failure/settlement semantics** — a queued action, export or import
  is verified to its own terminal state (finished, failed, or
  stalled-then-reaped), never to the push alone.
- **Persistent request isolation** — nothing about one request's
  identity, impersonation or record selection is read back on a later
  request or a resident Fiber; see {doc}`agent-correctness`'s "Request
  state leakage".
- **The chosen UI mode** — hosted or standalone, whichever the change
  actually touched — only when the change touches UI behavior.

## See also

- {doc}`agent-workflow` — establishing installed versions and reading
  installed source before any claim on this page governs a decision.
- {doc}`application-recipes` — the framework-level recipes Vector's own
  database, queue, storage and MCP wiring sit on top of.
- {doc}`agent-correctness` — the review checklist this page's
  correctness boundaries draw from.
- {doc}`persistence`, {doc}`orm`, {doc}`queue`, {doc}`storage`,
  {doc}`authorization`, {doc}`mcp`, {doc}`caching`, {doc}`container` — the
  Kinetis foundations Vector runs on; none of them documents Vector's
  own resource, field or job contract.
