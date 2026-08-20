# Kinetis

<style>
article[role="main"] section > p > strong:only-child { color: #D97706; }
@media (prefers-color-scheme: dark) {
  body:not([data-theme="light"]) article[role="main"] section > p > strong:only-child { color: #F59E0B; }
}
body[data-theme="dark"] article[role="main"] section > p > strong:only-child { color: #F59E0B; }
</style>

*Persistent Workers, Non-Blocking I/O.*

**Optimized for long-running processes, but equally snappy on PHP-FPM and serverless environments. One codebase — any runtime.**

- Typed requests and responses.
- OpenAPI generated from your code rather than maintained beside it.
- Genuinely concurrent non-blocking I/O.
- Native Model Context Protocol (MCP) server, one install away.

## What you get

**Write an endpoint, get the rest**

- Routes, request binding, and validation declared with native PHP
  attributes — no YAML, no XML, no config file to keep in step with the
  code.
- Typed DTOs in and out. A malformed request is a `422` with every field
  error at once, before your controller runs.
- OpenAPI 3.1 and a Swagger UI generated from the same
  attributes. Nothing to write, nothing to update.
- Controllers, commands, event listeners, and middleware found
  automatically anywhere under your own namespace.

**True request-level concurrency**

- Real concurrency on PHP Fibers: run four queries, an HTTP call, and a
  cache read side by side and wait once.
- Native MySQL, MariaDB, and Postgres drivers that suspend instead of
  blocking, with connection pooling and TLS.
- A Revolt-native HTTP client, Redis (cluster) client, and filesystem layer, so
  nothing in the request path quietly blocks the others.

**More functionality in separate packages**

- **Data** — a fluent query builder with pagination, database migrations,
  and request-scoped transaction safety.
- **Background work** — queues on Redis, SQL, Amazon SQS, or RabbitMQ,
  with retries, delays, priorities, and graceful shutdown.
- **Auth** — bearer tokens or JWT, password hashing, refresh tokens, and
  per-user revocation.
- **Web essentials** — sessions with CSRF protection, rate limiting, CORS,
  security headers, and a body-size cap, all opt-in middleware.
- **Infrastructure** — file storage on local disk or S3, mail, OpenSearch,
  AWS request signing, and OpenTelemetry tracing that spans your queue
  workers too.

**Built for agents, not adapted for them**

- A native Model Context Protocol server (`kinetis/mcp`), over stdio or
  HTTP — installing the package is the whole setup.
- Tools and resources declared with attributes and validated exactly like
  HTTP routes — one definition, two audiences.

## Long-running or classic, same code

Kinetis targets FrankenPHP's worker mode first: one warm process serving
request after request. **The same application code runs unchanged under
classic nginx and PHP-FPM** — and on AWS Lambda via Bref — because the
runtime is an adapter Kinetis talks to, not an assumption baked into the
framework. Deploy the way you deploy today, and move to a worker when it 
suits you.

## Built to a standard you can check

Every change runs through
[GitHub Actions](https://github.com/kinetis-dev/kinetis) before it merges:
PHPUnit, PHPStan, Psalm with taint analysis, mutation testing via
Infection, and integration tests against real MySQL, MariaDB, Postgres,
Redis, RabbitMQ, and LocalStack rather than mocks.
[SonarQube Cloud](https://sonarcloud.io/project/overview?id=kinetis-dev_kinetis)
rates the project A for security, reliability, and maintainability, with
test coverage above 90%. The benchmarks are
[public and reproducible](benchmarks.md), infrastructure included.

## Start here

````{grid} 1 2 2 2
:gutter: 3

```{grid-item-card} Tutorial
:link: tutorial
:link-type: doc

Start here. Install Kinetis, write a controller, then build a real-time
application on it — a database, a queue, a scheduled command, live
updates over a WebSocket, and a tool an AI agent can call.
```

```{grid-item-card} Core Concepts
:link: core-concepts
:link-type: doc

The runtime-agnostic Kernel, the request lifecycle, and why persistent
workers change the rules.
```

```{grid-item-card} Routing & Validation
:link: routing-validation
:link-type: doc

Attribute-based routes, typed DTOs, constraint validation, and zero-config
OpenAPI.
```

```{grid-item-card} Concurrency
:link: concurrency
:link-type: doc

Run a database query, an HTTP call, and a cache read side by side inside
one request, instead of one after another.
```
````


```{toctree}
:maxdepth: 2
:caption: Start here
:hidden:

Home <self>
tutorial
core-concepts
```

```{toctree}
:maxdepth: 2
:caption: Building an application
:hidden:

container
config
routing-validation
middleware
events
cli
testing
```

```{toctree}
:maxdepth: 2
:caption: Working with data
:hidden:

persistence
query-builder
migrations
```

```{toctree}
:maxdepth: 2
:caption: Background work
:hidden:

queue
queue-sqs
queue-rabbitmq
```

```{toctree}
:maxdepth: 2
:caption: Authentication and sessions
:hidden:

auth
auth-jwt
session
```

```{toctree}
:maxdepth: 2
:caption: Runtime and performance
:hidden:

runtime-adapters
concurrency
caching
performance-tuning
benchmarks
```

```{toctree}
:maxdepth: 2
:caption: Observability
:hidden:

logging
telemetry
```

```{toctree}
:maxdepth: 2
:caption: Integrations
:hidden:

storage
storage-s3
mailer
revolt-http-client
search-opensearch
aws-sigv4
```

```{toctree}
:maxdepth: 2
:caption: AI agents
:hidden:

mcp
```

```{toctree}
:maxdepth: 2
:caption: Reference
:hidden:

appendix
appendix-packages
appendix-ci
appendix-contributing
```
