# Agent Correctness Review

A checklist to run over a change before calling it done. Each item names
what to look for and routes to the page that holds its actual contract,
rather than restating it — read the linked page before deciding.

## Unconditional Kinetis invariants

These hold regardless of adapter, driver, or use case.

- **Request state leakage.** Nothing read from a request — its body,
  headers, identity, or an object built from them — is written to an
  `AppScope`-bound service, a static property, or a resident Fiber, and
  nothing from one request is read back on a later one. {doc}`container`,
  {doc}`core-concepts`.
- **Application-scope capture.** A request-scoped object — a
  `TransactionGuard`, an `EntityManager`, a `Session`, a
  `CurrentUserInterface` — is never bound in `AppScope` or held
  somewhere that outlives the request it was resolved for. The default
  async database link/pool and other concurrency-safe application
  clients are `AppScope` services by design; this rule targets objects
  whose own contract ties them to one request, not every service a
  request happens to use. {doc}`container`.
- **Cross-request or cross-Fiber resources.** A request- or
  transaction-owned resource — a `TransactionGuard`, an `EntityManager`,
  a borrowed transaction, a session, an identity — opened for one
  request or Fiber is not handed to another; a resident Fiber holding
  one across messages is the same leak as a static. This does not reach
  a client or pool whose own contract supports concurrent sharing, such
  as the default database link. {doc}`container`, {doc}`concurrency`.
- **Blocking I/O.** A call inside a Fiber-concurrent path — a database
  query, an HTTP request, a filesystem read — goes through a driver that
  suspends rather than blocks, or is flagged and audited as the
  exception to that rule. {doc}`concurrency`.
- **Deadlines, cancellation, and backpressure.** An outbound call has an
  explicit timeout below whatever is waiting on it, and nothing waits on
  an unbounded response body. {doc}`revolt-http-client`.
- **Bounded results and buffers.** A list read from a database or an
  external API pages or streams rather than loading an unbounded result
  into memory. {doc}`query-builder`, {doc}`revolt-http-client`.
- **Retries, idempotency, and unknown delivery.** A write whose outcome
  is unknown — a dropped connection, a failed `COMMIT`, a `Transport` or
  `Timeout` HTTP failure — is looked up rather than blindly resent, and a
  handler that can run more than once, which includes every queue job,
  has no duplicate effect. {doc}`persistence`, {doc}`revolt-http-client`,
  {doc}`queue`.
- **Direct dependencies.** A new dependency points toward the contract
  it consumes; application code does not reach past that contract into
  an optional or runtime-specific implementation it does not need.
- **Compiled-cache freshness.** A change to a route, an MCP tool or
  resource, a command, an event listener, or a validation plan needs
  `kinetis build` before a production deploy that pre-warms
  `.kinetis-cache/`. {doc}`caching`.
- **Validation, authorization, CSRF, concurrency control, and
  transactions.** Every mutating boundary validates its input,
  authorizes the actor, checks CSRF where a browser session is involved,
  settles a race with an explicit integrity mechanism — a unique
  constraint, an atomic conditional write, or an appropriate lock or
  isolation policy inside the transaction, never a transaction by itself
  — rather than a read-then-write check, and commits related writes as
  one unit. {doc}`routing-validation`, {doc}`authorization`,
  {doc}`session`, {doc}`persistence`.
- **Documentation drift.** A guide, README, `.env.example`, or example
  that describes the changed behavior is updated in the same change,
  not left for later.
- **Current-main versus installed-version assumptions.** A claim read
  from these pages governs a decision only after it is checked against
  the project's own installed version and source. {doc}`agent-workflow`.

## Questions that depend on the adapter, driver, or use case

These have no single universal answer; read the linked page for the
specific choice in the project.

- **Exact timeout and pool defaults** differ by database driver and by
  queue backend. {doc}`persistence`, {doc}`appendix-queue`.
- **Redelivery and visibility-timeout behavior** differ by queue
  backend: Redis and SQL reserve atomically, SQS is best-effort, and
  RabbitMQ redelivers on connection drop. {doc}`queue`.
- **Request-lifecycle and boot timing** differ by runtime adapter —
  FrankenPHP, PHP-FPM, RoadRunner, or Lambda. {doc}`runtime-adapters`,
  {doc}`appendix-runtime`.
- **Whether concurrency actually overlaps** depends on the driver and
  the workload, not on using `concurrently()` alone — a blocking call
  inside it still blocks the worker. {doc}`concurrency`.
- **Broadcast delivery to a subscriber** depends on the chosen broker —
  Soketi, Reverb, or Pusher; this framework's own contract ends at
  whether the trigger request reached it. {doc}`broadcasting`.

## Working habits that keep the checklist honest

- **A passing verification is not a ready application.**
  `orbitron_verify` answers one question — whether the project's
  Composer layout is the narrow one Orbitron supports — and a layout
  pass says nothing about request isolation, non-blocking I/O, route
  uniqueness or any behavior. The same holds for `phpstan.neon`'s
  `NoStaticPropertiesRule` and `NoBlockingIoRule`: two guardrails, not a
  proof. Report readiness as what was actually established.
  {doc}`orbitron`, {doc}`testing`.
- **Read the driver a task depends on, not the contract above it.** Which
  database driver `DB_DRIVER=auto` selects decides where the blocking
  boundary is: the native drivers suspend on query I/O but still block
  on a mysqli connect and a libpq host lookup, and PDO blocks
  throughout. When a change depends on that boundary, read the installed
  driver's source rather than inferring it from `MysqlLink`.
  {doc}`persistence`, {doc}`concurrency`.
- **Prove overlap where overlap is the requirement.** `concurrently()`
  running without error is not evidence that anything overlapped — a
  blocking call inside it still serializes the whole loop. Where
  concurrency is a property the change has to deliver, measure it:
  `Kinetis\Testing\LoopLiveness::turnedDuring()` for whether a wait
  yields, and a real timed probe against the real backend for whether
  two operations actually ran side by side. {doc}`concurrency`,
  {doc}`testing`.
- **Tell a mistyped command from a wrong document.** A command that
  fails is first a claim about what you typed. Re-read the invocation
  against the page before concluding the page is wrong, and change
  documentation only once the documented invocation itself is the thing
  that fails.
- **Ask the route table rather than reading controllers.**
  `kinetis routes:list` runs the same discovery a boot runs and prints
  the global middleware pipeline outermost to innermost, then every
  discovered route's method, path, status, controller and own
  middleware, sorted by path and then method. It settles "did my
  `#[RoutePrefix]` apply", "is this path already taken" and "what wraps
  my controller" without reading a single class. Those rows are a sorted
  listing, not a matching order — which route wins a request is
  {doc}`routing-validation`'s own rule. {doc}`cli`.
- **At a material milestone, re-read the top-level framing.** When a
  feature lands, a dependency is added, or a route or contract changes,
  scan the project's own `README.md` and the package READMEs the change
  touched for claims it made demonstrably false — a capability described
  as absent that now exists, a count, a list, a setup step. Correct
  those. This is a correctness pass, not permission to rewrite prose
  that is merely not how you would have put it.

## See also

- {doc}`agent-workflow` — the workflow this checklist closes.
- {doc}`application-recipes` — routing recipes for common tasks.
