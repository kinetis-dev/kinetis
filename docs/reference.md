# Reference

The guides show how to build and operate an application. These pages
describe the contracts and internal workings behind them. Open a guide
first, then use its linked reference when you need exact boundaries,
failure behavior or advanced wiring.

## Application setup and request handling

- {doc}`appendix-configuration` — configuration resolution, scoped keys
  and the complete key catalogue.
- {doc}`container` — AppScope, RequestScope, binding resolution and
  disposal.
- {doc}`appendix-routing-validation` — route matching, binding,
  validation and OpenAPI schema rules.
- {doc}`appendix-middleware` — pipeline order and built-in middleware
  contracts.
- {doc}`appendix-sessions` — cookie, CSRF, expiry and store lifecycle.
- {doc}`appendix-authentication` — bearer header, JWT keys, validation
  and failure contracts.

## Data and background work

- {doc}`appendix-database` — database drivers, sessions, transactions,
  options and connection registration.
- {doc}`appendix-query-builder` — query shapes, dialect behavior and
  advanced builder operations.
- {doc}`appendix-redis` — transport deadlines, Cluster routing and
  cache mechanisms.
- {doc}`appendix-queue` — delivery, worker lifecycle and backend
  mechanisms.

## Integrations

- {doc}`appendix-http-client` — URL, header, deadline, response and
  transport contracts.
- {doc}`appendix-aws-sigv4` — signed-origin, credential, canonical
  request and failure contracts.
- {doc}`appendix-storage` — local publication, path confinement,
  symlinks and S3 failure behavior.
- {doc}`appendix-search` — search configuration, client results and
  transport failure vocabulary.
- {doc}`appendix-mcp` — protocol, HTTP headers, errors and request
  lifecycle for application tools and resources.
- {doc}`appendix-mcp-docs` — documentation server install, update,
  protocol and fetch contracts.
- {doc}`appendix-orbitron` — Orbitron's command schemas, installed-source
  tools, MCP catalogue, project wiring and trust boundary.

## Runtime and verification

- {doc}`appendix-runtime` — request-body staging, forwarded headers,
  runtime adapters, Fiber scheduling and compiled artifacts.
- {doc}`appendix-testing` — test-client request construction, loop
  liveness and runtime conformance.
- {doc}`appendix-observability` — tracing export, Fiber scope ownership
  and fingerprint construction.

## Repository and release details

- {doc}`appendix` — framework namespace and file layout.
- {doc}`appendix-packages` — satellite package layout.
- {doc}`appendix-ci` — what the CI jobs verify.
- {doc}`appendix-contributing` — working in the monorepo.

## AI agent guidance

Operating instructions for an AI coding agent, not human tutorials —
each routes to the guide that holds the actual contract.

- {doc}`agent-workflow` — the entry point: the current-main/installed-version
  boundary, and where to route a task.
- {doc}`application-recipes` — compact routes for recurring application
  tasks.
- {doc}`agent-correctness` — the review checklist to run before calling
  a change done.
