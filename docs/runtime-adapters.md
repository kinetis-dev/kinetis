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
- {doc}`caching` — the production build step, and why it matters most
  under PHP-FPM.
- {doc}`appendix` — the exact internals of each built-in adapter.
