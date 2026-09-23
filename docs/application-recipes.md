---
orphan: true
myst:
  html_meta:
    robots: noindex
---

# Application Recipes

Routing guidance for an AI coding agent: compact routes for tasks that
recur across Kinetis applications. Each recipe names the pages that hold
the actual contract and routes to them rather than restating it — read
those before writing code. Start at {doc}`agent-workflow` if a task does
not match one of these. A human developer wants the linked guides
directly — {doc}`routing-validation`, {doc}`session`,
{doc}`query-builder`, and the rest each recipe below routes through.

## JSON HTTP endpoint with request DTO validation

- **Guides**: {doc}`routing-validation`, {doc}`appendix-routing-validation`.
- **Lifecycle/I-O**: the request DTO and its controller resolve fresh
  from the request scope on every request; nothing about one request's
  input persists past it.
- **Security/integrity**: validation attributes run before the
  controller does, so a malformed request never reaches application
  code — put every constraint on the DTO, not in checks scattered
  through the controller.
- **Verification**: a {doc}`testing` `TestClient` request for a valid
  payload and for each violation, asserting the `422` and its violation
  list.
- **Non-goal**: hand-written validation duplicating what a constraint
  attribute already expresses.

## Browser form with session, CSRF, and validation errors

- **Guides**: {doc}`session`, {doc}`routing-validation`, {doc}`middleware`,
  {doc}`views`.
- **Lifecycle/I-O**: the session is request-scoped through
  `SessionMiddleware`; see {doc}`container` when a value needs to
  outlive one request some other way.
- **Security/integrity**: `CsrfMiddleware` runs after `SessionMiddleware`
  in the pipeline; every state-changing form carries `csrfToken()`, and
  a missing or mismatched token is refused before the controller runs.
  A valid submission with an invalid field gets the default `422`
  `application/problem+json` response; an application that wants an
  HTML redirect or a re-rendered form instead must implement and bind
  its own `ValidationExceptionRendererInterface`, per {doc}`middleware`'s
  "Rendering validation failures".
- **Verification**: a `TestClient` submission with a missing or wrong
  token asserting the refusal, one with a valid token and an invalid
  field asserting the default `422` problem document, and — only when
  the application installs a custom renderer — a test of that renderer's
  own response instead of assuming automatic form rendering.
- **Non-goal**: a CSRF token as the only protection on a state change
  that also needs its own authorization check.

## Database-backed bounded list with pagination

- **Guides**: {doc}`query-builder`, {doc}`persistence`.
- **Lifecycle/I-O**: the link resolves from the shared connection
  `kinetis/database-bridge` registers on `AppScope` — under a
  persistent worker, its connection pool — not a request-scoped object;
  a query still suspends the calling Fiber rather than blocking the
  worker.
- **Security/integrity**: page size and any filter come through the
  same validated DTO as the rest of the request, and every value binds
  as a parameter rather than being interpolated into SQL.
- **Verification**: an integration test against a real database
  asserting the page boundary — the right rows, in order, with a stable
  cursor across an intervening write.
- **Non-goal**: an unbounded `SELECT`, or an `OFFSET` walk over a table
  the application does not control the size of.

## Transactional mutation with explicit failure behavior

- **Guides**: {doc}`persistence` — "Transactions", "When a write's
  outcome is unknown", "Unique violations".
- **Lifecycle/I-O**: one `TransactionGuard` per unit of work; every
  statement runs on the transaction it hands the callback, never the
  outer link.
- **Security/integrity**: a unique key settles a race instead of a
  read-then-write check, and a `COMMIT` that throws leaves the outcome
  unknown — never retried as though it were a rollback.
- **Verification**: a test that fails a statement mid-transaction and
  asserts nothing committed, plus one that exercises the
  unique-violation path.
- **Non-goal**: several related writes left as separate, individually
  committed statements outside one `transaction()` call.

## Application with a second database

- **Guides**: {doc}`persistence` — "Options and more connections";
  {doc}`appendix-database` — "Registering connections in
  `bootstrap.php`"; {doc}`orm` — "Other connections"; {doc}`query-builder`
  for a named link queried without the ORM.
- **Lifecycle/I-O**: the bridge builds, injects and closes the default
  connection, and opens and closes a request-scoped `EntityManager` for
  the default ORM, and nothing else. A named link, and any `OrmFactory`
  built on it, is explicit application wiring, down to who closes each
  manager and link; {doc}`orm`'s "Other connections" states which
  cleanup covers what.
- **Security/integrity**: a named connection reads every key under its
  own name — for `reporting`, `DB_REPORTING_DRIVER` rather than
  `DB_DRIVER` — so nothing it needs comes from the default connection's
  settings. Only the default is injected
  by type: pass the named link or factory explicitly to the code that
  runs on it.
- **Verification**: an integration test against each real backend,
  including one in which a unit of work on the named connection throws
  and the next asserts that nothing from it committed or stayed open.
- **Non-goal**: one transaction across both databases. Each connection
  commits on its own.

## Application with more than one search engine or connection

- **Guides**: {doc}`search-engines` — "More than one engine or
  connection"; {doc}`appendix-search` — "Named connections", "Why the
  client is short-lived", and "OpenSearch client internals";
  {doc}`bootstrapping`; {doc}`container`.
- **Lifecycle/I-O**: construct each named connection with its engine factory
  and bind it under an application-owned identity in `AppScope`. Follow the
  engine-specific client lifetime in {doc}`appendix-search`: the two engines
  do not have the same sharing contract. Dropping those application bindings
  when `AppScope` is disposed releases the transports they own.
- **Security/integrity**: inject the exact application identity into each
  repository. Keep its connection name, endpoint, credentials, timeout and
  index together; an index name is not a connection identity. When both
  engine packages are installed, do not let their competing `SearchClient`
  bindings or package-bootstrap order select a repository's engine.
- **Verification**: run the package bootstraps in both orders and assert that
  only the unused default `SearchClient` changes. Against the two real
  engines, write distinct markers through each repository and assert each
  marker and index is absent from the other engine. Rejecting an invalid
  timeout under each named prefix proves the two configuration scopes do not
  collapse.
- **Non-goal**: a connection registry, request-selected endpoint, cross-engine
  transaction, failover policy or translated query language.

## Queue job with retry/idempotency considerations

- **Guides**: {doc}`queue`, the installed backend's own page
  ({doc}`queue-redis`, {doc}`queue-sql`, {doc}`queue-sqs`,
  {doc}`queue-rabbitmq`), {doc}`appendix-queue`, and
  {doc}`appendix-redis` when Redis owns the effect.
- **Lifecycle/I-O**: a job's constructor arguments are its whole state —
  nothing request-scoped from the code that pushed it survives to the
  worker that runs it.
- **Security/integrity**: delivery is at least once, so a handler that
  is not safe to run twice records its idempotency key and effect in one
  atomic operation. In a database, use a unique key in the same transaction.
  When one Redis node owns both values, use one Lua script that writes the
  marker and effect together. On the pushing side, `QueueInterface::push()`
  is not enlisted in a transaction the caller has open: the job is enqueued
  even if that transaction rolls back. `kinetis/queue-sql`'s
  `SqlQueue::pushOn()` is the one backend API that places the row on a
  transaction the caller supplies, so the enqueue commits with the work —
  see {doc}`queue-sql`.
- **Verification**: run the handler twice with the same identifier and
  assert one effect, and exercise the permanent-failure path at
  `maxAttempts`. If the work must eventually complete, verify the recovery,
  dead-letter or reconciliation path as well; retry exhaustion alone loses
  the queue's ability to make further progress.
- **Non-goal**: treating delivery as exactly-once. Leaving `maxAttempts`
  unset defers to the worker's `QUEUE_MAX_ATTEMPTS`, which defaults to
  `0` and gives up after the first failed attempt — no setting retries a
  job forever. A released job is held by the backend for the
  exponentially-backed-off delay `QUEUE_RETRY_BASE_DELAY_SECONDS`
  produces, so a retry lands later rather than at once.

## Outbound HTTP integration with deadline/cancellation/uncertain-write semantics

- **Guides**: {doc}`revolt-http-client`, {doc}`appendix-http-client`.
- **Lifecycle/I-O**: `withTimeout()` bounds the whole call, below
  whatever deadline is waiting on it, and `withMaxResponseBytes()`
  bounds the reply.
- **Security/integrity**: a `Transport` or `Timeout` failure on a
  non-idempotent write means the request may already have taken effect —
  look up the result, or use the API's own idempotency key, rather than
  resending it blind.
- **Verification**: a test against a mock HTTP client for the timeout
  and transport-failure branches, asserting the lookup path runs instead
  of a blind repeat.
- **Non-goal**: `withRetries()` on an endpoint whose `PUT`/`DELETE` is
  not actually idempotent.

## Broadcast update with truthful delivery semantics

- **Guides**: {doc}`broadcasting`.
- **Lifecycle/I-O**: triggering an event suspends the calling Fiber
  while the request to the broker is in flight; nothing about the
  broadcast is request-scoped state.
- **Security/integrity**: authorize every `private-`/`presence-` channel
  with `#[BroadcastChannel]`; an unauthorized subscription is refused
  before it reaches the broker.
- **Verification**: a test of the authorizer method's own logic — accept
  and reject cases — rather than a live broker.
- **Non-goal**: reading the trigger request's success as proof a
  subscribed client received the event. This package reports whether the
  broker accepted the event, not whether, or when, any client saw it.

## Application-owned MCP resource or tool

- **Guides**: {doc}`mcp`, {doc}`appendix-mcp`.
- **Lifecycle/I-O**: each message resolves and disposes its own request
  scope, the same way an HTTP request does, per {doc}`mcp`'s "Each
  message is its own request".
- **Security/integrity**: a tool's arguments validate exactly like a
  request DTO's fields, and nothing about the caller's identity persists
  past its own message.
- **Verification**: a unit test of the tool or resource method's own
  domain logic, plus a separate test that drives the registered
  definition through `McpServer::handle()` (see {doc}`appendix-mcp`'s
  "Malformed requests") to exercise `McpDispatcher` argument hydration,
  validation, and the MCP result envelope — a direct method call
  bypasses both.
- **Non-goal**: this documentation. `mcp-docs` serves only its own fixed
  page catalogue, whether reached directly or through Orbitron; an
  application's own tools and resources are `kinetis/mcp`'s job.

## Application on a non-default runtime adapter

- **Guides**: {doc}`runtime-adapters` — "Choose a runtime", "Choose an
  adapter explicitly"; {doc}`container`; {doc}`persistence` — "Driver
  selection"; {doc}`testing` — "Conformance-testing a runtime adapter";
  {doc}`appendix-testing` — "What each run proves".
- **Lifecycle/I-O**: `RequestScope` resolves fresh on every invocation
  under every adapter, persistent ones (FrankenPHP worker mode,
  RoadRunner, Lambda) included — request identity, request data and
  callbacks belong there and nowhere else. `AppScope` bindings, and any
  other state a process keeps outside `RequestScope` (a static, a
  global, a resident Fiber), can outlive one request on a persistent
  adapter and must never carry a request's identity, callbacks or data —
  see {doc}`container`'s `AppScope`/`RequestScope` split.
- **Security/integrity**: `DB_DRIVER=auto` selects the driver the chosen
  runtime supports — see {doc}`persistence`'s "Driver selection". Do not
  infer or force a driver value observed under one runtime onto another;
  let the runtime choose it, or set it deliberately per that section's
  two documented exceptions.
- **Verification**: {ref}`testing-reference-conformance`'s "What each
  run proves" states each adapter's own proof limit — Lambda's shared
  conformance run is in-process and proves the event conversion alone,
  RoadRunner's runs against a real spawned `rr serve` process, and
  FrankenPHP's and PHP-FPM's run under their real SAPI in CI. When the
  open question is process lifecycle itself — whether `RequestScope`
  actually resets between invocations on the chosen adapter — drive one
  bounded real invocation or request sequence through that adapter's own
  runtime rather than the in-process driver call; the conformance suite
  alone does not establish that for every adapter.
- **Non-goal**: treating a local or single-process run as proof of a
  managed platform's own process lifecycle guarantees.

## See also

- {doc}`agent-workflow` — establishing installed versions and routing a
  task here in the first place.
- {doc}`agent-correctness` — the review checklist once a change is made.
