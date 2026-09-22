# Agent Correctness Review

Review guidance for an AI coding agent: a checklist to run over a change
before calling it done. Each item names what to look for and routes to
the page that holds its actual contract, rather than restating it — read
the linked page before deciding. A human reviewer runs the same review
by reading those same linked pages.

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
  has no duplicate effect. Exhausting `maxAttempts` is a terminal outcome,
  not eventual delivery; when correctness depends on completion, the design
  includes a permanent-failure recovery, dead-letter or reconciliation path.
  {doc}`persistence`, {doc}`revolt-http-client`, {doc}`queue`.
- **Direct dependencies.** A new dependency points toward the contract
  it consumes; application code does not reach past that contract into
  an optional or runtime-specific implementation it does not need. Every
  package whose namespace production code names is declared directly in
  `require`, and every package named only by tests or development tooling
  is declared directly in `require-dev`; transitive availability is not
  a dependency contract.
- **Sensitive response caching.** A response that carries a CSRF token,
  bearer credential, authenticated identity, or session-establishing or
  session-ending cookie has an explicit cache policy such as
  `Cache-Control: no-store`. Apply it at the narrow response boundary;
  do not infer protection from the presence of `Set-Cookie`, TLS, or a
  referrer policy, and do not disable caching globally when public
  responses contain nothing sensitive.
- **Credential transport and logging.** A bearer credential is not put in
  a URL path or query string unless every proxy and application access log
  that can retain the request target has an explicit, verified redaction
  policy. Prefer a header or request body at the exchange boundary, and
  account for browser history and referrer propagation as well as
  application logs; TLS does not remove any of those copies.
- **MCP identity is transport-specific.** The HTTP `mcp` middleware group
  and its identity guard do not run over stdio. A tool that depends on an
  authenticated caller injects `CurrentUserInterface` even when it also
  needs a concrete user for provider-specific claims: without
  authentication the interface is unresolvable and fails closed, while an
  autowirable concrete user can become a new, disconnected object.
  {doc}`mcp`, {doc}`auth-jwt`.
- **Compiled-cache freshness.** A change to a route, an MCP tool or
  resource, a command, an event listener, or a validation plan needs
  `kinetis build` before a production deploy whenever `.kinetis-cache/`
  is shipped with the release or image, or persists across production
  boots — whether pre-warmed or lazily compiled and published on an
  earlier boot. Do not rely on lazy compilation to refresh an existing
  valid artifact: while a structurally valid artifact remains, production
  loads it without comparing it against current source. {doc}`caching`.
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
- **Catch failures from the object actually called.** A Kinetis adapter's
  mapped exceptions govern calls through that adapter. Code that calls a
  concrete vendor client directly must use that client's own failure
  vocabulary, verified against its installed source. {doc}`agent-workflow`,
  {doc}`appendix-search`.
- **Read installed source to settle a fact, not to inventory a package.**
  Use Orbitron's directory listing, literal search, and bounded window for
  the exact version-sensitive signature, default, failure code, or vendor
  behavior that governs the change. Once that fact is established, return
  to the application; continuing through unrelated source adds review cost
  without strengthening the evidence. {doc}`agent-workflow`.
- **Test at the boundary that owns the behavior.** `TestClient` dispatches
  after the runtime adapter and `send()` preserves a hand-built PSR-7 request
  exactly. It can test application behavior against the post-adapter shape,
  but it cannot prove how repeated wire headers, cookies or request identity
  are converted. Use a runtime-conformance driver or one bounded request
  through the real adapter for that claim. {doc}`appendix-testing`.
- **Prove overlap where overlap is the requirement.** `concurrently()`
  running without error is not evidence that anything overlapped — a
  blocking call inside it still serializes the whole loop. Prove a wait
  yields with `Kinetis\Testing\LoopLiveness::turnedDuring()`. Prove
  backend overlap with observable simultaneous backend state or a causal
  barrier against the real service; elapsed time can corroborate that
  evidence but is not proof by itself. For MySQL, a test-only session can
  hold a table lock while `information_schema.PROCESSLIST` confirms every
  expected query is waiting at once, then release the lock in bounded
  cleanup. {doc}`concurrency`, {doc}`testing`.
- **Run one suite at a time against one backend.** Overlapping runs
  against a shared database, broker or object store interleave their
  writes and produce failures that name the code under test but belong
  to the schedule. Serialize the runs, or give each one state nothing
  else reaches — {ref}`testing-serialized-shared-state`.
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
  touched for application-specific claims it made demonstrably false — a
  capability described as absent that now exists, a count, a list, a
  setup step. Preserve reusable framework and tooling setup reference
  unless the change invalidated it. This is a correctness pass, not
  permission to rewrite prose that is merely not how you would have put
  it.

## See also

- {doc}`agent-workflow` — the workflow this checklist closes.
- {doc}`application-recipes` — routing recipes for common tasks.
