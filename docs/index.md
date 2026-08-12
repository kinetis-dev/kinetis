# Kinetis

Kinetis is a PHP framework for building API-first applications on
persistent-worker runtimes — processes that boot once and keep serving
requests, rather than tearing everything down and rebuilding it from
scratch on every one. It targets the same class of application a modern
API is expected to be: typed request and response contracts, OpenAPI
described automatically rather than hand-maintained, genuinely
non-blocking under real concurrent load, and native support for AI agents
as first-class API clients, not an afterthought bolted on later.

Routing, validation, and OpenAPI generation are driven entirely by native
PHP attributes, read once and reflected against — not YAML, not XML, not
a separate config layer to keep in sync with the code:

```{code-block} php
:caption: A complete Kinetis controller

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

final readonly class UserController
{
    #[Post('/users', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }
}
```

A two-tier container makes request-scope isolation a hard, enforced
guarantee rather than a convention developers have to remember: `AppScope`
holds what the worker owns for its whole lifetime, `RequestScope` holds
what one request owns, and nothing crosses from the second into the first
without saying so explicitly.

Speed comes from two different places, not one. Concurrency runs on real
PHP Fibers over a Revolt event loop, with Revolt-native MySQL, Postgres,
and Redis clients — `concurrently()` means genuinely concurrent I/O, not
a blocking call wrapped in the appearance of async. And in production,
routing, validation, and OpenAPI generation are compiled ahead of time
(`kinetis build`), so a request pays reflection's cost once, at build
time, rather than on every single one it serves.

A native Model Context Protocol server ships in core, so an AI agent
calls an application's own tools and resources exactly the way any other
client does — the same attributes and validation already used for HTTP
routes.

FrankenPHP's worker mode is the primary target every one of those
decisions optimizes for first — a single PHP process serving thousands of
requests, interpreter and container both kept warm between them. The same
application code also runs correctly under classic PHP-FPM and AWS Lambda
(via Bref), because the runtime is an adapter Kinetis talks to, not an
assumption baked into the framework itself.

## Start here

````{grid} 1 2 2 2
:gutter: 3

```{grid-item-card} Getting Started
:link: getting-started
:link-type: doc

Install Kinetis, write your first controller, and run it under FrankenPHP.
```

```{grid-item-card} Tutorial
:link: tutorial
:link-type: doc

Build a real-time application from scratch — a database, a queue, a
scheduled command, and live updates over a WebSocket.
```

```{grid-item-card} Core Concepts
:link: core-concepts
:link-type: doc

The runtime-agnostic Kernel, the request lifecycle, and why persistent
workers change the rules.
```

```{grid-item-card} Container
:link: container
:link-type: doc

AppScope and RequestScope — and why Kinetis bans `static` properties.
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

Fiber-based concurrency over Revolt: `concurrently()`, `Async\Socket`, and
`Async\Timer`.
```
````

```{toctree}
:maxdepth: 2
:caption: Documentation
:hidden:

Home <self>
getting-started
tutorial
core-concepts
container
config
routing-validation
middleware
events
logging
runtime-adapters
concurrency
persistence
migrations
query-builder
queue
queue-sqs
queue-rabbitmq
storage
storage-s3
mailer
aws-sigv4
search-opensearch
auth
auth-jwt
mcp
caching
cli
testing
appendix
appendix-packages
appendix-ci
appendix-contributing
revolt-http-client
```

## Why Kinetis exists

Traditional PHP frameworks were designed for a world where every request
gets a fresh process — Apache/mod_php, then PHP-FPM. Nothing you allocate
survives past the response; there's no such thing as a memory leak that
outlives a request, because the process itself doesn't outlive the request.

FrankenPHP (and, more generally, the worker-mode pattern popularized by
Swoole and RoadRunner before it) changes that: a single PHP process handles
request after request, keeping the interpreter warm, skipping
bootstrap cost on every single one. That's a genuine performance win — and
it introduces an entirely new failure mode PHP frameworks built for
boot-and-die were never designed to prevent: **state leaking from one
request into the next**, silently, through anything that isn't explicitly
scoped to a single request.

Kinetis's answer isn't "be careful" — it's architectural:

- A **two-tier container** ({doc}`container`) that draws a hard, enforced
  line between "lives for the worker's lifetime" (`AppScope`) and "lives
  for one request" (`RequestScope`), with no silent promotion from one to
  the other.
- A **PHPStan rule that ships with the framework**, banning `static`
  properties in consumer code — the one thing a fresh container per
  request genuinely can't catch on its own.
- A **runtime adapter layer** ({doc}`runtime-adapters`) so the same
  application code runs correctly whether the process lives for
  milliseconds (PHP-FPM, a Lambda cold invocation) or for days
  (a FrankenPHP worker) — because Kinetis was built to target both from day
  one, not persistent workers exclusively.
- **AOT compilation** ({doc}`caching`) for the boot-and-die runtimes
  specifically, where reflection's per-request cost actually lives.
