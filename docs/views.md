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
use Kinetis\Views\Views;
use Kinetis\ViewsLatte\LatteViewEngine;

$app->instance(
    Views::class,
    new Views(new LatteViewEngine(
        __DIR__ . '/resources/views',
        new AssetUrl('/'),
        cacheDirectory: __DIR__ . '/var/cache/latte',
        autoRefresh: false,
    )),
);
```

Twig:

```php
use Kinetis\Views\AssetUrl;
use Kinetis\Views\Views;
use Kinetis\ViewsTwig\TwigViewEngine;

$app->instance(
    Views::class,
    new Views(new TwigViewEngine(
        __DIR__ . '/resources/views',
        new AssetUrl('/'),
        options: [
            'cache' => __DIR__ . '/var/cache/twig',
            'auto_reload' => false,
        ],
    )),
);
```

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

For engine-specific filters or extensions, configure the adapter during
bootstrap through `LatteViewEngine::engine()` or
`TwigViewEngine::engine()`. Keep those choices out of controllers so changing
the adapter does not change controller code.

## Local files and non-blocking I/O

Template loading and compilation use local filesystem I/O in all three engines.
That work is not an asynchronous network boundary and cannot be made
Revolt-aware by the view facade. In production, deploy templates with the
application, keep them on local storage, enable OPcache, and pre-create writable
Latte/Twig cache directories.

In a persistent worker, Latte and Twig load a compiled template class once per
process; neither engine performs a freshness check after that class is loaded,
regardless of `autoRefresh`/`auto_reload`. A deployment must therefore restart
workers. Under PHP-FPM and at a persistent worker's cold start, those flags do
control source freshness checks. Disable them only when the deployment replaces
the application and its compiled-template cache together; otherwise stale
templates are possible.

Do not render templates from network filesystems or fetch templates during a
request. Remote data belongs in non-blocking application services and should be
fully resolved before `render()` is called. Rendering is buffered: it returns
one complete string/response, not a streaming response, and the pure-PHP adapter
restores output-buffer depth if a template throws.
