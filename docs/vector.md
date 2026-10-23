---
orphan: true
myst:
  html_meta:
    robots: noindex
---

# Vector

Operating and routing guidance for an AI coding agent working on a
Kinetis application that installs `kinetis/vector`, the optional
schema-driven admin package. This is not a human tutorial: a developer
wants the [Vector manual](https://kinetis.dev/vector/docs/) instead.
Read {doc}`agent-workflow` first; this page adds what Vector needs on
top of it.

## Confirm the package is installed

Call `orbitron_inspect` where {doc}`orbitron` is registered, or read the
project's own `composer.lock`, and confirm that `kinetis/vector` is
installed. When it is not, nothing on this page applies. This page is
no reason to install Vector: install it only when the task or the user
asks for an admin.

## Where the guidance comes from

This page is `kinetis://docs/vector`, read with `kinetis_read_doc` like
every page {doc}`agent-workflow` routes through. The Vector manual it
links to is published on the web, not served as `kinetis://docs/*`
resources. Both describe `main`, so either can describe a newer Vector
than the one installed.

The installed package is the authority for the exact contract:

1. **Read `kinetis/vector`'s own `composer.json`** with
   `orbitron_read_package_source` for its requirements, its
   `Kinetis\Vector\` autoload root under `src/`, and `extra.kinetis`.
2. **Read its `README.md`** for the installed version's setup and
   feature summary.
3. **Settle a signature, a default, a config key or a failure code in
   `src/`** with `orbitron_search_package_source_tree` when the file is
   unknown, `orbitron_search_package_source` when it is known, and a
   window around the reported line with `orbitron_read_package_source`.
   `orbitron_list_package_source` lists a directory when the layout is
   what you need. The descriptor's JSON schema is
   `resources/schema/descriptor.schema.json`; its `version` is the
   contract number, and the admin refuses a descriptor of any other.

Without Orbitron, read the same files under the project's own
`vendor/kinetis/vector`.

`@kinetis/vector-ui`, the npm package a standalone admin is built from,
is JavaScript that neither documentation server nor Orbitron's
installed-source tools reach. Read its `package.json`, the lockfile and
its source with your own file-reading tools. The installed
`kinetis/vector` pins the `@kinetis/vector-ui` release it expects in
`resources/ui.json` and publishes it in the descriptor as
`ui.expected`; keep a standalone admin's `@kinetis/vector-ui` at that
release, with the `nuxt` version its peer range requires. Another
release can lack what the API now requires, such as the `record` part of
an upload; the admin shows a banner naming both versions, and refuses a
descriptor of another contract version outright.

## Workflow

1. **Confirm the installed version** of `kinetis/vector` and of every
   Kinetis package the task touches.
2. **Route the task** through the list below to the manual page that
   covers it, and read that section.
3. **Read the installed source** for anything version-sensitive.
4. **Make the smallest change.** A resource, dashboard, settings group,
   policy or action is ordinary application code, discovered from its
   attribute where it has one; there is no registry to edit.
5. **Run the verification checklist** below, then review the change
   against {doc}`agent-correctness`.

## Task routing

- **Install and migrations.** `vendor/bin/kinetis vector:install`
  publishes Vector's migrations only, and is idempotent: run it again
  after every upgrade, then `migrate`. `--connection=<name>` puts
  Vector's tables on a named connection, read at runtime from
  `VECTOR_DB_CONNECTION`. It also says when the served admin UI is
  missing, has no `vector-bundle.json`, or is not the pinned release.
  [Getting started](https://kinetis.dev/vector/docs/getting-started.html#install),
  [Databases](https://kinetis.dev/vector/docs/databases.html#vector-s-own-tables).
- **The hosted UI bundle.** `vendor/bin/kinetis vector:install-ui`
  installs the pinned `@kinetis/vector-ui` release from the npm registry
  into `VECTOR_UI_DIR` (`public/vector/ui` by default), checked against
  the registry's sha512 `dist.integrity` and the bundle's
  `vector-bundle.json` before anything served changes. Run it after
  every upgrade too, then restart the workers: a worker reads
  `index.html` once, when it boots. `--version`, `--from` (a tarball,
  with `--integrity`, or a built `dist/static` directory) and `--force`
  choose what to install; `--check` verifies the served directory
  offline. Exit codes are 0 (done or in sync), 1 (refused or failed) and
  2 (usage). `VECTOR_UI_REGISTRY` and `VECTOR_UI_REGISTRY_TOKEN` name a
  private registry. Neither command needs Node.
  [Hosted](https://kinetis.dev/vector/docs/deployment.html#hosted),
  [Upgrading](https://kinetis.dev/vector/docs/deployment.html#upgrading),
  [What vector:install-ui checks](https://kinetis.dev/vector/docs/deployment.html#what-vector-install-ui-checks),
  [Private registries and air-gapped installs](https://kinetis.dev/vector/docs/deployment.html#private-registries-and-air-gapped-installs).
- **Resources, fields, forms, lists, records, actions, dashboards,
  settings and audit.** A `#[Resource]` class extending `VectorResource`
  declares `fields()`, `actions()` and `widgets()`; a `#[Dashboard]`
  class extending `VectorDashboard` and a `#[Settings]` class extending
  `SettingsGroup` add dashboards and settings groups. All of them compile
  into the descriptor. A list's `q` matches the resource's `searchable()`
  fields, a searchable `belongsTo` by its target's title, and the fields
  `fullText()` names by whole words through a full-text index the
  application creates. `withoutCount()` pages by number without a COUNT
  and `cursorPaging()` pages the admin's list by cursor; an uncounted
  page answers `total` and `lastPage` as `null` with `hasMore`. An
  action's `openUrl()` opens only an http(s), `mailto:` or relative URL,
  and its `redirect()` only a path of the admin.
  [Resources](https://kinetis.dev/vector/docs/resources.html),
  [Search](https://kinetis.dev/vector/docs/resources.html#search),
  [Paging](https://kinetis.dev/vector/docs/lists.html#paging),
  [Action responses](https://kinetis.dev/vector/docs/actions.html#responses),
  [Fields](https://kinetis.dev/vector/docs/fields.html),
  [Forms](https://kinetis.dev/vector/docs/forms.html),
  [Lists](https://kinetis.dev/vector/docs/lists.html),
  [Records](https://kinetis.dev/vector/docs/records.html),
  [Actions](https://kinetis.dev/vector/docs/actions.html),
  [Dashboards and widgets](https://kinetis.dev/vector/docs/dashboards.html),
  [Settings](https://kinetis.dev/vector/docs/settings.html),
  [Audit trail and impersonation](https://kinetis.dev/vector/docs/audit.html).
- **Authentication, authorization and field concealment.** The
  application's authentication middleware joins the `vector` middleware
  group and registers `Kinetis\Http\CurrentUserInterface`; Vector's
  `VectorAccessMiddleware` then asks the application's
  `VectorAccessInterface` whether that user may enter the admin at all.
  What an admitted user sees is the resource's `policy()` class (a
  `ResourcePolicyInterface`), its `scope()` for rows, and the policy's
  `viewField()` for fields.
  [Authentication](https://kinetis.dev/vector/docs/authentication.html#the-vector-middleware-group),
  [Authorization](https://kinetis.dev/vector/docs/authorization.html),
  [Field visibility](https://kinetis.dev/vector/docs/authorization.html#field-visibility).
- **The REST API and the MCP tools.** Every route is under `/vector`.
  The MCP tools — `vector_resources`, `vector_describe`, `vector_query`,
  `vector_get`, `vector_create`, `vector_update`, `vector_delete`,
  `vector_run_action` and `vector_widget` — call the same services and
  policies under the caller's identity. Over HTTP (`POST /mcp`) the
  authentication middleware joins the `mcp` group as well as `vector`.
  `GET /vector/openapi.json` describes, as OpenAPI 3.1, the resources,
  fields and actions the caller may see; it is served only where
  `OPENAPI_ENVIRONMENTS` names `APP_ENV`, as the framework's own
  [OpenAPI documentation](routing-validation.md#openapi-documentation)
  is, and answers `404` elsewhere.
  [REST API](https://kinetis.dev/vector/docs/rest-api.html),
  [OpenAPI document](https://kinetis.dev/vector/docs/rest-api.html#openapi-document),
  [MCP tools](https://kinetis.dev/vector/docs/mcp.html),
  [Serving them](https://kinetis.dev/vector/docs/mcp.html#serving-them),
  {doc}`mcp`.
- **Files, imports and exports.** Uploads are checked by their bytes,
  never their name, and stored privately under a generated key.
  Uploading needs permission to create, or to update the record named in
  the upload's `record` part; listing the resource is not enough.
  Vector never deletes an upload when a value changes;
  `vector:files:prune`, run by a scheduler, deletes those no record or
  audit entry holds. Export is CSV or Excel (XLSX), and every XLSX export
  runs as a job; import is CSV only, and always a job.
  [Files and images](https://kinetis.dev/vector/docs/files.html),
  [Cleaning up unused files](https://kinetis.dev/vector/docs/files.html#cleaning-up-unused-files),
  [Export and import](https://kinetis.dev/vector/docs/lists.html#export-and-import),
  [Excel exports](https://kinetis.dev/vector/docs/jobs.html#excel-exports),
  [Imports](https://kinetis.dev/vector/docs/jobs.html#imports).
- **Jobs: queued or inline, terminal state, reaping.** Actions that
  declare `queued()`, exports above the resource's `exportLimit()`, every
  XLSX export and every import run on the application's queue as the
  admin who started them; everything else runs in the request.
  [Background jobs](https://kinetis.dev/vector/docs/jobs.html),
  [Delivery](https://kinetis.dev/vector/docs/jobs.html#delivery),
  [Stalled jobs](https://kinetis.dev/vector/docs/jobs.html#stalled-jobs),
  [How an action runs](https://kinetis.dev/vector/docs/actions.html#how-it-runs).
- **Hosted or standalone UI, and the customization tiers.** Standalone
  is a Nuxt application extending `@kinetis/vector-ui` on its own
  origin, signing in with a bearer token, and is upgraded with the
  package and rebuilt; hosted is the bundle `vector:install-ui` installs
  and the PHP application serves at `/vector/ui` behind its session.
  Customization has three tiers: descriptor and settings, runtime
  extensions, and the application's own build.
  [Deployment](https://kinetis.dev/vector/docs/deployment.html),
  [Customizing the UI](https://kinetis.dev/vector/docs/customizing-the-ui.html),
  [Keeping the layer at the pinned release](https://kinetis.dev/vector/docs/customizing-the-ui.html#keeping-the-layer-at-the-pinned-release).
- **Database, queue and storage topology.** An entity on a named
  connection, Vector's own tables on another (`VECTOR_DB_CONNECTION`),
  Vector's jobs on a named queue (`VECTOR_QUEUE`) or queue connection
  (`VECTOR_QUEUE_CONNECTION`), and uploads on a named storage connection
  (`VECTOR_STORAGE_CONNECTION`).
  [Databases](https://kinetis.dev/vector/docs/databases.html),
  [Where the jobs go](https://kinetis.dev/vector/docs/jobs.html#where-the-jobs-go),
  [Configuration](https://kinetis.dev/vector/docs/configuration.html#environment-keys).
- **Exact shapes and bounds.** Every route, envelope and error is in the
  [REST API](https://kinetis.dev/vector/docs/rest-api.html), every
  environment key in
  [Configuration](https://kinetis.dev/vector/docs/configuration.html),
  and every enforced bound in
  [Limits](https://kinetis.dev/vector/docs/limits.html).

## Correctness boundaries

- **Definitions are compiled and request-neutral.** The descriptor is
  compiled by `kinetis build` (or at boot, in development) from every
  resource, settings group and dashboard, and is immutable. A
  resource's definition methods return the same data for every request;
  what varies per request belongs in its request-time hooks.
  [The descriptor](https://kinetis.dev/vector/docs/core-concepts.html#the-descriptor),
  [Request-time hooks](https://kinetis.dev/vector/docs/resources.html#request-time-hooks),
  {doc}`caching`.
- **Identity and policy stay request-scoped.** `CurrentUserInterface`
  is what the application's authentication middleware registers for
  each request, and policies, hooks, actions and widgets are built by
  the request container. The `VectorAccessInterface` implementation is
  bound on the application scope and answers for every request, so it
  holds no request state. A job carries only the actor's id, and
  `ActorResolverInterface` rebuilds the user in the worker.
  [Request scope](https://kinetis.dev/vector/docs/core-concepts.html#request-scope),
  {doc}`container`.
- **Vector's own database connection is explicit.**
  `VECTOR_DB_CONNECTION` names it, `default` when unset; it is never
  inferred from an administered entity's connection.
  [Vector's own tables](https://kinetis.dev/vector/docs/databases.html#vector-s-own-tables).
- **Relations and transactions do not span databases.** The ORM refuses
  a relation across two connections at build time, and each connection
  commits on its own: Vector's own rows (action log, audit, jobs) commit
  on its connection after the entity changes they describe.
  [Entities on several connections](https://kinetis.dev/vector/docs/databases.html#entities-on-several-connections),
  {doc}`orm`, {doc}`persistence`.
- **A queued job is not finished work.** A queued action, export or
  import answers only that the job was pushed. Its row moves through
  `queued`, `running`, and then `done` or `failed`; a job is pushed for
  one attempt and claims its row with one conditional write, so a
  duplicate delivery does not run twice.
  [Delivery](https://kinetis.dev/vector/docs/jobs.html#delivery),
  {doc}`queue`.
- **The actor and the terminal state have to be proven.** Without
  `kinetis/queue` and an `ActorResolverInterface` binding, queued actions
  and imports answer `403` and large exports are refused. A worker that
  dies mid-job leaves the row `running`; once it has reported no progress
  for the configured minutes it is answered as `stalled`, and
  `vector:jobs:reap`, run by a scheduler, marks it `failed`.
  [Stalled jobs](https://kinetis.dev/vector/docs/jobs.html#stalled-jobs).
- **Uploads, export formats and the queue are optional capabilities.**
  The descriptor's `capabilities` (`uploads`, `exportFormats`, `queue`)
  report what this deployment has configured; a change that needs one
  does not assume it.
  [Requirements](https://kinetis.dev/vector/docs/jobs.html#requirements),
  {doc}`storage`.
- **Hosted and standalone authentication, and the CSP, are deployment
  concerns.** Standalone sends `Authorization: Bearer …` from another
  origin and needs CORS; hosted sends the session cookie with
  `X-CSRF-Token` and needs a login page (`VECTOR_LOGIN_URL`). One
  middleware may accept both. The hosted shell runs under the
  application's own `SECURITY_CSP` with its nonce, or under
  `VECTOR_SHELL_CSP` for that page alone.
  [Deployment](https://kinetis.dev/vector/docs/deployment.html),
  [Content Security Policy](https://kinetis.dev/vector/docs/deployment.html#content-security-policy),
  [One API, both modes](https://kinetis.dev/vector/docs/authentication.html#one-api-both-modes).

## Verification checklist

- **Boot and compile.** The application boots, or `kinetis build`
  compiles, with every resource, action, widget, dashboard and settings
  group the change touches; `vendor/bin/kinetis vector:list` prints what
  compiled. A deliberate definition mistake fails naming the class and
  field.
  [Checked at build](https://kinetis.dev/vector/docs/resources.html#checked-at-build).
- **Authorization and concealment.** A restricted user gets the same
  refusal through REST and MCP, and a field `viewField()` hides is absent
  everywhere: the descriptor, records, the audit trail, exports and MCP
  results, with a filter, sort, grouping, column or written value naming
  it refused as unknown.
  [Field visibility](https://kinetis.dev/vector/docs/authorization.html#field-visibility),
  {doc}`authorization`.
- **The real backend.** A claim about the database, queue or storage is
  checked against the connection, queue or filesystem Vector actually
  uses: "Read installed source to settle a fact" in
  {doc}`agent-correctness`.
- **Settlement.** Queued work is followed to its terminal state — `done`,
  `failed`, or stalled and then reaped as `failed` — never the push
  alone.
- **Request isolation.** No identity, impersonation or record selection
  is read back on a later request or in a resident Fiber: "Request state
  leakage" in {doc}`agent-correctness`.
- **The UI mode.** Hosted or standalone is exercised only when the
  change touches UI behavior. Where the hosted UI is served,
  `vendor/bin/kinetis vector:install-ui --check` exits 0 once the
  installed bundle is the pinned release.

## See also

- {doc}`agent-workflow` — the version boundary and the installed-source
  tools this page builds on.
- {doc}`agent-correctness` — the review checklist the boundaries above
  draw from.
- {doc}`application-recipes` — the framework recipes Vector's database,
  queue, storage and MCP wiring sit on.
- {doc}`orbitron` — the project-local harness that serves this page.
- {doc}`persistence`, {doc}`orm`, {doc}`queue`, {doc}`storage`,
  {doc}`authorization`, {doc}`mcp`, {doc}`caching`, {doc}`container` —
  the Kinetis foundations Vector runs on.
- The [Vector manual](https://kinetis.dev/vector/docs/), the user guide
  every route above links into.
