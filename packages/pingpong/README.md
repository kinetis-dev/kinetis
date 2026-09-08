<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/pingpong</strong>
  <br>
  <strong>A runnable ping-pong demo application for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/pingpong"><img src="https://img.shields.io/packagist/v/kinetis/pingpong?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/pingpong"><img src="https://img.shields.io/packagist/dt/kinetis/pingpong" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/pingpong"><img src="https://img.shields.io/packagist/php-v/kinetis/pingpong" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/pingpong"><img src="https://img.shields.io/packagist/l/kinetis/pingpong" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

A small, working application showing most of Kinetis at once:
MySQL + [`kinetis/migrations`](https://github.com/kinetis-dev/migrations) + [`kinetis/query-builder`](https://github.com/kinetis-dev/query-builder),
Redis-backed [`kinetis/queue`](https://github.com/kinetis-dev/queue), `Kinetis\Events`, a
`Kinetis\Console` command run on a schedule, a
[`kinetis/mcp`](https://github.com/kinetis-dev/mcp) tool an AI agent can
call for the per-scenario ping breakdown, and real-time browser
updates over [Soketi](https://soketi.app) — behind a monochrome-amber,
old-CRT-styled dashboard rendered with [`league/plates`](https://platesphp.com/).

A ping can be answered three ways: `POST /pong/direct` replies in the
same request; `POST /pong/queued` replies a few seconds later, from a
separate queue-worker process; a scheduled command replies entirely on
its own, every few seconds, with no request involved at all. The
dashboard watches all three happen live, over a WebSocket.

## Running it

```sh
docker run --rm -v "$PWD":/app -w /app composer:2 \
    create-project --no-install kinetis/pingpong my-app
cd my-app
cp .env.example .env
docker compose up --build
```

Then open [http://localhost:8080](http://localhost:8080). Docker is the
only thing you need — the containers install the dependencies and run
the app, so no PHP or Composer has to exist on the host. (`--no-install`
is what keeps it that way: it fetches the project without resolving
dependencies, which `docker compose up` then does inside the containers
it will run them in.)

The project is yours from that point on. `docker-compose.yml` mounts it
at `/app` and needs nothing outside it; MySQL, Redis and Soketi come up
alongside it as services of the same stack.

`app` runs under a genuine FrankenPHP persistent worker — Kinetis's
*primary optimization target* (persistent connection pooling, warm
route caching). One consequence worth knowing before you start editing
code: a FrankenPHP worker loads `public/index.php` (including all
route/command/tool discovery) exactly once at boot, so a code change is
invisible until the `app` container restarts — there's no PHP-FPM-style
"every request reboots the script" hot reload here. See
[the CLI docs](https://kinetis.dev/docs/cli.html) for the full
discovery/hot-reload tradeoff. Looking for that kind of instant-feedback
loop instead? See [`kinetis/skeleton`](https://github.com/kinetis-dev/skeleton), a much smaller demo
running on nginx + PHP-FPM for exactly that reason.

## Using this as a starting point

Start editing what you just created — every
piece (`bootstrap.php`, the migration, the repository, the job, the
scheduled command, the events, the broadcaster and its private-channel
authorizer, the MCP tool controller, `resources/views/dashboard.php`) is
a small, plain file meant to be read end to end. Kinetis itself has no
opinion on HTML
templating — `HtmlResponse::create()` just takes a string — so
`PingController::index()` shows one reasonable way to wire in a small
templating library ([`league/plates`](https://platesphp.com/)) instead
of building the page as one large string. The logo (`public/logo.svg`),
stylesheet (`public/dashboard.css`), and browser script
(`public/dashboard.js`) are plain static files served directly, not
template data — only the Soketi connection details are actually
dynamic, passed to `dashboard.js` through a `type="application/json"`
data island rather than any inline script of the template's own.

## Working on this package itself

This package is developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo
and published from it; `kinetis-dev/pingpong` is the split mirror the
commands above install from. Inside the monorepo, `composer.json` still
carries the `path` repositories that resolve every `kinetis/*` sibling
from its own checkout, so the stack needs the override that mounts them:

```sh
docker compose -f docker-compose.yml -f docker-compose.monorepo.yml up --build
```

Container paths are `/app` either way — that override adds mounts and
changes nothing else.

## Learn by building the same thing yourself

The [Tutorial](https://kinetis.dev/docs/tutorial.html) builds this exact
application from an empty directory, one working piece at a time —
useful for understanding *why* each file looks the way it does, not just
what it does.

## License

MIT — see [LICENSE](LICENSE).
