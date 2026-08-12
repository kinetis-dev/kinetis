<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/pingpong</strong>
  <br>
  <strong>A runnable ping-pong demo application for Kinetis</strong>
</p>

---

A small, working application showing most of Kinetis at once:
MySQL + [`kinetis/migrations`](../migrations) + [`kinetis/query-builder`](../query-builder),
Redis-backed [`kinetis/queue`](../queue), `Kinetis\Events`, a
`Kinetis\Console` command run on a schedule, and real-time browser
updates over [Soketi](https://soketi.app) — behind a monochrome-amber,
old-CRT-styled dashboard rendered with [`league/plates`](https://platesphp.com/).

A ping can be answered three ways: `POST /pong/direct` replies in the
same request; `POST /pong/queued` replies a few seconds later, from a
separate queue-worker process; a scheduled command replies entirely on
its own, every few seconds, with no request involved at all. The
dashboard watches all three happen live, over a WebSocket.

## Running it

```sh
git clone https://github.com/kinetis-dev/kinetis.git
cd kinetis/packages/pingpong
cp .env.example .env
docker compose up --build
```

This package lives inside the `kinetis` monorepo, not a separate
`kinetis-pingpong` repository — `packages/pingpong/` is exactly the
directory these commands land you in.

Then open [http://localhost:8080](http://localhost:8080). No PHP or
Composer needed on the host — everything, including dependency
installation, runs inside the containers.

`app` runs under a genuine FrankenPHP persistent worker — Kinetis's
*primary optimization target* (persistent connection pooling, warm
route caching). One consequence worth knowing before you start editing
code: a FrankenPHP worker loads `public/index.php` (including all
route/command/tool discovery) exactly once at boot, so a code change is
invisible until the `app` container restarts — there's no PHP-FPM-style
"every request reboots the script" hot reload here. See
[the CLI docs](https://docs.kinetis.dev/cli.html) for the full
discovery/hot-reload tradeoff. Looking for that kind of instant-feedback
loop instead? See [`kinetis/skeleton`](../skeleton), a much smaller demo
running on nginx + PHP-FPM for exactly that reason.

## Using this as a starting point

Copy `packages/pingpong/` out into a new project, point its
`composer.json` at a real `kinetis/framework` install instead of the `path`
repository this monorepo uses internally, and modify from there — every
piece (`bootstrap.php`, the migration, the repository, the job, the
scheduled command, the events, the Soketi publisher,
`resources/views/dashboard.php`) is a small, plain file meant to be read
end to end. Kinetis itself has no opinion on HTML templating —
`HtmlResponse::create()` just takes a string — so `PingController::index()`
shows one reasonable way to wire in a small templating library
([`league/plates`](https://platesphp.com/)) instead of building the page
as one large string. The logo (`public/logo.svg`), stylesheet
(`public/dashboard.css`), and browser script (`public/dashboard.js`) are
plain static files served directly, not template data — only the Soketi
connection details are actually dynamic, passed to `dashboard.js`
through a `type="application/json"` data island rather than any inline
script of the template's own.

## Learn by building the same thing yourself

The [Tutorial](https://docs.kinetis.dev/tutorial.html) builds this exact
application from an empty directory, one working piece at a time —
useful for understanding *why* each file looks the way it does, not just
what it does.

## License

MIT — see [LICENSE](LICENSE).
