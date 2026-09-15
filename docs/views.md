# Views

Kinetis deliberately has no template language of its own. There are already
excellent engines in the PHP ecosystem, so the framework chooses to **re-use,
not re-invent**. The view packages give controllers one small API while the
application selects pure PHP, Latte, or Twig at bootstrap.

## Choose one engine

Install the common contract and exactly one adapter:

```console
composer require kinetis/views kinetis/views-php
# or: composer require kinetis/views kinetis/views-latte
# or: composer require kinetis/views kinetis/views-twig
```

The adapter packages conflict with one another. Composer therefore refuses an
application that accidentally installs two engines and leaves its active one
ambiguous.

The conventional layout is:

```text
resources/
└── views/
    └── articles/
        └── index.php       # or index.latte / index.twig
public/
├── css/app.css
└── js/app.js
```

`resources/views` is a convention, not hidden global state. Bootstrap supplies
an absolute root explicitly, so a project may put templates elsewhere without
depending on the process's current working directory. Static assets belong in
`public/`, where the web server can serve them without running PHP.

## Wire the application

Pure PHP:

```php
use Kinetis\Views\AssetUrl;
use Kinetis\Views\Views;
use Kinetis\ViewsPhp\PhpViewEngine;

$app->instance(
    Views::class,
    new Views(new PhpViewEngine(
        __DIR__ . '/resources/views',
        new AssetUrl('/'),
    )),
);
```

Latte:

```php
use Kinetis\Views\AssetUrl;
use Kinetis\Views\ViewRuntime;
use Kinetis\Views\Views;
use Kinetis\ViewsLatte\LatteViewEngine;

$app->instance(
    Views::class,
    new Views(new LatteViewEngine(
        __DIR__ . '/resources/views',
        ViewRuntime::fromConfig(__DIR__, $config),
        new AssetUrl('/'),
    )),
);
```

Twig:

```php
use Kinetis\Views\AssetUrl;
use Kinetis\Views\ViewRuntime;
use Kinetis\Views\Views;
use Kinetis\ViewsTwig\TwigViewEngine;

$app->instance(
    Views::class,
    new Views(new TwigViewEngine(
        __DIR__ . '/resources/views',
        ViewRuntime::fromConfig(__DIR__, $config),
        new AssetUrl('/'),
    )),
);
```

`ViewRuntime::fromConfig()` follows Kinetis's `APP_ENV` rule. In development,
Latte and Twig compile from source without writing a disk cache. In production
(including an unset or unfamiliar `APP_ENV`), generated templates live beside
the AOT artifact in `.kinetis-cache/views/latte` or
`.kinetis-cache/views/twig`. The adapters own these directories; applications
do not configure vendor-specific cache paths or freshness flags.

This is worker-lifetime configuration: one immutable `Views` facade and one
engine are shared safely, while every call supplies a fresh data array. Do not
put request-specific values into a Twig global, a Latte extension, or an
application-scoped service used by a template.

## Render from a controller

Controllers depend only on the common package:

```php
use Kinetis\Views\Views;
use Psr\Http\Message\ResponseInterface;

final readonly class ArticleController
{
    public function __construct(
        private Views $views,
        private ArticleRepository $articles,
    ) {}

    public function index(): ResponseInterface
    {
        return $this->views->response('articles/index', [
            'articles' => $this->articles->latest(),
        ]);
    }
}
```

The logical name is extensionless and relative. The selected adapter adds
`.php`, `.latte`, or `.twig`. Absolute names, traversal, empty path segments,
and names that already carry an engine extension are rejected. A missing file
throws `ViewNotFoundException`; a template failure throws
`ViewRenderException` with the engine's original exception as its cause.

Use `render()` when a string is needed instead of an HTTP response:

```php
$html = $this->views->render('mail/welcome', ['user' => $user]);
```

## The one helper: `asset()`

Kinetis does not inject a global bag of URL, logger, container, or request
helpers. Hidden service access is particularly unsafe in persistent workers,
where an application-scoped object can accidentally retain request state.
The initial view contract includes only `asset()`: a deterministic URL join
with no I/O and no application state.

Pure PHP receives an invokable `$asset` variable:

```php
<link rel="stylesheet" href="<?= $asset('css/app.css') ?>">
```

Latte and Twig receive a function:

```html
<link rel="stylesheet" href="{asset('css/app.css')}">
```

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
```

With the default `new AssetUrl('/')`, all three produce `/css/app.css`.
A root-relative prefix such as `/static` or an HTTPS CDN base is also accepted.
Asset paths stay relative and cannot contain traversal, a query, or a fragment.
The helper does not check the filesystem, fingerprint files, or read a build
manifest.

Data keys use template-variable names. `asset`, `GLOBALS`, and `this` are
reserved. This keeps the same data contract across all three engines and
prevents one engine from shadowing a helper or PHP runtime variable even if
another engine would happen to resolve the collision differently.

## Escaping and engine customization

Latte and Twig auto-escape ordinary HTML output. Pure PHP deliberately follows
PHP's own rules: escape untrusted text with `htmlspecialchars()` and use
context-appropriate encoding for attributes, URLs, JavaScript, and JSON.
The adapter does not guess the output context.

```php
<h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
```

Request-bound values such as a CSRF token reach a template the same way as any
other data: the controller passes them in the data array. {doc}`session` shows
a controller passing the token and a form rendering it.

For engine-specific filters or extensions, configure the adapter during
bootstrap through `LatteViewEngine::engine()` or
`TwigViewEngine::engine()`. Keep those choices out of controllers so changing
the adapter does not change controller code. Twig constructor options remain
available for engine behavior such as `strict_variables`; `cache` and
`auto_reload` are reserved for `ViewRuntime`.

## Warm and clear the cache

The common package contributes two commands for whichever adapter the
application bound:

```console
php vendor/bin/kinetis views:warm
php vendor/bin/kinetis views:clear
```

`views:warm` empties the selected adapter's cache, recursively scans the whole
configured view root for `.latte` or `.twig` files, and compiles them in stable
logical-name order. It does not follow symbolic links. Clearing first is
intentional: with production freshness checks disabled, asking either vendor to
load an existing compiled file could preserve a template from the previous
deployment. A compile error fails the command instead of reporting a partial
cache as ready.

Pure PHP has no generated view cache, so both commands succeed with a zero
count. In development, Latte and Twig warming is likewise a successful zero-
work operation; `views:clear` still removes production artifacts left in the
project cache directory.

Both commands run the normal package and application bootstrap so they compile
the exact configured engine, functions, extensions, and root that workers use.
Run them with the application's complete deployment environment. Bootstrap
registrations should remain lazy: binding a connection factory is appropriate,
opening a database connection merely because bootstrap ran is not.

In production, build both cache layers before starting or restarting workers:

```console
php vendor/bin/kinetis build
php vendor/bin/kinetis views:warm
```

After first installing or updating `kinetis/views`, run `kinetis build` so the
production AOT command registry includes the package-provided view commands.

## Local files and non-blocking I/O

Template loading and compilation use local filesystem I/O in all three engines.
That work is not an asynchronous network boundary and cannot be made
Revolt-aware by the view facade. In production, deploy templates with the
application, keep them on local storage, enable OPcache, and make
`.kinetis-cache/` writable to the deploy command.

In a persistent worker, Latte and Twig load a compiled template class once per
process; neither engine performs a freshness check after that class is loaded,
even when disk caching is disabled. Development therefore provides source
loading, not in-process class replacement: a persistent-runtime development
supervisor must restart workers when a template changes. A boot-per-request
runtime such as ordinary PHP-FPM sees the new source on the next request. A
production deployment must warm the new cache and restart workers; a CLI cache
operation cannot unload classes from a separate serving process.

Do not render templates from network filesystems or fetch templates during a
request. Remote data belongs in non-blocking application services and should be
fully resolved before `render()` is called. Rendering is buffered: it returns
one complete string/response, not a streaming response, and the pure-PHP adapter
restores output-buffer depth if a template throws.
