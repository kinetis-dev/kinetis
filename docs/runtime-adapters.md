# Runtime Adapters

Kinetis runs unmodified on three different kinds of PHP hosting, and picks
the right one automatically — you don't configure this yourself:

```{code-block} php
$adapter = Kinetis\Runtime\RuntimeDetector::detect();
```

| Deployment | What Kinetis does |
|---|---|
| FrankenPHP (worker mode) | One long-running process serves request after request — Kinetis's primary target. |
| Plain PHP-FPM | The classic model: one request in, one response out, then the script ends. Fully supported, not an afterthought. |
| AWS Lambda (via Bref) | A separate install, `kinetis/bref-adapter` — see below. |

`public/index.php` calls `RuntimeDetector::detect()` once, and the exact
same file works correctly under all three — nothing in your application
code needs to know or care which one is actually running it.

## Running under FrankenPHP

This is the deployment Kinetis is built around: a single PHP process that
boots once and serves thousands of requests, keeping everything warm
between them. See {doc}`getting-started` for a complete, working
`Caddyfile`.

```{warning}
**A deployment gotcha worth knowing about:** Caddy's `php_server`
directive falls back to classically re-executing `index.php` for any
request path that doesn't match a real static file, *before* it ever
routes to a configured worker. The `worker` directive in your `Caddyfile`
must point at that **same** `index.php` — pointing it at a different
script means every request silently keeps falling through to the classic
fallback, never once reaching your worker, with no error to indicate why.
```

### Sizing FrankenPHP's worker threads

FrankenPHP's `worker` directive accepts an explicit thread count:

```{code-block}
:caption: Caddyfile

worker {
    file public/index.php
    num 64
}
```

or the shorthand form, `worker public/index.php 64`. Left unset, it
defaults to roughly **2x your available CPU cores** — a number tuned for
CPU-bound work, not for the kind of I/O-bound workload (database calls,
outbound HTTP requests) most real applications actually spend most of
their time on.

**This number matters more than it might look, and it's easy to
undertune.** Each worker thread processes exactly one HTTP request at a
time, start to finish — `frankenphp_handle_request()` is a blocking call
that only returns once that request's response has been fully sent, then
picks up the next one. Kinetis's own `Kinetis\Async`/`concurrently()`
layer (see {doc}`concurrency`) provides real, genuine concurrency *within*
one request's own work — but it doesn't change this: a thread that's
mid-request, even one suspended on a Fiber waiting for a database
response, isn't available to pick up a second, unrelated incoming
request. Cross-request concurrency is bounded by thread count here, the
same way it's bounded by PHP-FPM's own worker-process count under that
adapter — not something Kinetis's async layer can substitute for.

Concretely: an application doing real database/API work per request
should size `num` well above the CPU-based default — closer to your
expected concurrent request volume than to your core count. Undersizing
it doesn't produce errors; it produces queueing that looks, from the
outside, exactly like the application itself being slow. If most of your
routes finish quickly with little I/O, the default is probably fine as a
starting point — measure under realistic load rather than guessing
either way.

The same "each worker thread is its own independent execution context"
fact has a second, sharper consequence if you're using
`kinetis/persistence`'s `SqlConnectionFactory`: `bootstrap.php` runs once
*per worker thread*, so each one builds its own separate database
connection pool. Oversizing `num` without correspondingly *undersizing*
each pool's `maxConnections` can exhaust your database's own connection
limit — see {doc}`persistence`'s "Sizing `maxConnections` under worker
mode" section.

## Running under PHP-FPM

Nothing to configure — Kinetis detects a plain PHP-FPM environment
automatically and falls back to it whenever FrankenPHP isn't available.
Every request reruns the whole `public/index.php` script from scratch,
since PHP-FPM doesn't keep anything in memory between requests. See
{doc}`caching` for what changes about that in production, and why it
matters more here than under FrankenPHP.

## Running on AWS Lambda

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/bref-adapter
```
````

Once installed, detection picks it up automatically — nothing else to
configure. It needs one extra dependency beyond what core ships with (for
parsing file uploads), which is why it's a separate install rather than
bundled by default.

## Writing your own adapter

If you need to target something else entirely, implement this interface
and Kinetis will drive it the same way it drives the three built-in ones:

```{code-block} php
namespace Kinetis\Runtime;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface RuntimeAdapterInterface
{
    /**
     * @param callable(ServerRequestInterface): ResponseInterface $handler
     */
    public function run(callable $handler): void;

    public function isPersistent(): bool;
}
```

`isPersistent()` tells Kinetis whether to force a memory cleanup pass at
the end of every request — worth doing in a long-running process, pure
waste in one that's about to exit anyway.

You can also construct any adapter directly if you want to force a
specific one instead of relying on automatic detection:

```{code-block} php
$adapter = new Kinetis\Runtime\Adapters\FpmAdapter();
```

## See also

- {doc}`core-concepts` — why your application code never needs to know
  which adapter is running it.
- {doc}`concurrency` — what `Kinetis\Async`/`concurrently()` actually
  provides, and what it doesn't.
- {doc}`caching` — the production build step, and why it matters most
  under PHP-FPM.
- {doc}`appendix` — the exact internals of each built-in adapter.
