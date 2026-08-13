# Getting Started

This page gets a minimal Kinetis application running under FrankenPHP,
end to end. If you just want to see what Kinetis code looks like, skip to
[Your first controller](#your-first-controller). If you'd rather start
from something already running than build one up by hand, `composer
create-project kinetis/skeleton my-app` gives you exactly that.

## Requirements

- PHP 8.4 or later
- [Composer](https://getcomposer.org)
- A PSR-4 autoload mapping for your own application code in
  `composer.json` — `RouteDiscovery` (below) finds your controllers by
  reading it directly, so without one there's nothing for it to scan.
- [FrankenPHP](https://frankenphp.dev) for the primary, persistent-worker
  deployment target — though everything in this guide also runs correctly
  under classic PHP-FPM, which Kinetis detects and falls back to
  automatically. See {doc}`runtime-adapters` for the full detection story.

## Installation

```{code-block} bash
composer require kinetis/framework
```

Kinetis ships as a single package (`kinetis/framework`) — there's no
`kinetis/http`, `kinetis/di`, `kinetis/routing` to assemble separately.

### Add a PSR-4 mapping for your own code

`RouteDiscovery::discover()` (used in [Wiring it up](#wiring-it-up) below)
finds your controllers by reading `composer.json`'s own `autoload.psr-4`
section directly — it doesn't scan the filesystem blindly, and it doesn't
matter which namespace you pick, only that one is actually declared. If
your project doesn't have one yet (a truly empty directory, rather than
an existing app), add one now, `App\` and `src/` in this guide's examples:

```{code-block} json
:caption: composer.json

{
    "require": {
        "kinetis/framework": "*"
    },
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

```{code-block} bash
composer dump-autoload
```

```{warning}
Skipping this step doesn't produce an error — `RouteDiscovery` finds zero
routes and logs a warning naming the exact gap (`Kinetis\Cache\NamespaceScanner`
found no PSR-4 root [...] — did you forget an "autoload": {"psr-4": ...}
entry in composer.json?`), but every request still resolves to a plain
`404 Not Found` regardless, since there's simply nothing registered to
match. If `POST /users` below 404s, this is the first thing to check —
look for that warning in your error log.
```

## Your first controller

Kinetis controllers are plain PHP classes. There's no base class to extend
and no interface to implement — routes are declared with attributes
directly on public methods:

```{code-block} php
:caption: src/Http/UserController.php

<?php

declare(strict_types=1);

namespace App\Http;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Query;
use App\Requests\CreateUserRequest;
use App\Responses\UserResponse;

final readonly class UserController
{
    #[Post('/users', status: 201)]
    public function store(#[Body] CreateUserRequest $data): UserResponse
    {
        return new UserResponse(name: $data->name, email: $data->email);
    }

    #[Get('/users')]
    public function index(#[Query] int $page = 1, #[Query] int $limit = 20): array
    {
        return ['page' => $page, 'limit' => $limit];
    }

    #[Get('/users/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
```

A few things worth noticing already, since they come up throughout this
documentation:

- `#[Body]` marks a parameter as bound to the decoded JSON request body —
  its type (`CreateUserRequest`) is a DTO class that gets **validated
  before the controller ever runs**. See {doc}`routing-validation`.
- `#[Query]` binds a query-string parameter, cast to the parameter's
  declared scalar type.
- `show()`'s `$id` parameter needs no attribute at all — Kinetis matches it
  against the `{id}` placeholder in the route path by name.
- The controller class itself is `final readonly` with no constructor here,
  but if it needed dependencies, they'd be constructor-injected from the
  container — see {doc}`container`.

The DTO referenced above is just as plain:

```{code-block} php
:caption: src/Requests/CreateUserRequest.php

<?php

declare(strict_types=1);

namespace App\Requests;

use Kinetis\Validation\Constraints\Email;
use Kinetis\Validation\Constraints\MinLength;

final readonly class CreateUserRequest
{
    public function __construct(
        #[MinLength(3)]
        public string $name,
        #[Email]
        public string $email,
    ) {}
}
```

If a request's body fails validation — a name under three characters, an
invalid email — Kinetis responds with a `422` and **every** failing field's
error, not just the first one it happens to hit:

```{code-block} json
:caption: POST /users with {"name": "Al", "email": "not-an-email"}

{
    "errors": {
        "name": ["must be at least 3 characters."],
        "email": ["must be a valid email address."]
    }
}
```

`UserResponse` — what `store()` above actually returns — is just as
plain; there's no base class or interface to implement for a response
DTO either, it's the return value's own public properties that become
the JSON body:

```{code-block} php
:caption: src/Responses/UserResponse.php

<?php

declare(strict_types=1);

namespace App\Responses;

final readonly class UserResponse
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
```

## Wiring it up

`public/index.php` is the one place every runtime adapter converges on. A
minimal one looks like this:

```{code-block} php
:caption: public/index.php

<?php

declare(strict_types=1);

use Kinetis\Container\AppScope;
use Kinetis\Http\Kernel;
use Kinetis\Http\Routing\RouteDiscovery;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Runtime\RuntimeDetector;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new AppScope();
$app->boot();

$router = RouteDiscovery::discover(ProjectRoot::detect(__DIR__));

$adapter = RuntimeDetector::detect();
$kernel = new Kernel($app, $router, isPersistent: $adapter->isPersistent());

$adapter->run($kernel->handle(...));
```

`RouteDiscovery::discover()` finds `UserController` on its own — any class
anywhere under one of your own PSR-4 roots is picked up automatically,
with no required directory or namespace convention and nothing to
register by hand. Discovery reaches a class through its PSR-4 file path,
so keep the standard autoloading layout: one class per file, the file
named for the class (see {doc}`cli` for how discovery works and how to
restrict the scan on a large application). `RuntimeDetector::detect()`
figures out which runtime it's running under — FrankenPHP, AWS Lambda, or
plain PHP-FPM — and returns the matching adapter, with zero configuration
on your part. The exact same `public/index.php` runs unmodified in all
three. See {doc}`runtime-adapters` for how that detection actually works
and what each adapter does differently.

```{tip}
`bin/kinetis build` pre-compiles routing (and everything else discovered
by namespace) ahead of a production deploy — see {doc}`caching`.
```

## Running it under FrankenPHP

A minimal `Caddyfile`:

```{code-block}
:caption: Caddyfile

{
    admin off
}

:8080 {
    root * public
    php_server {
        worker public/index.php
    }
}
```

```{warning}
The `worker` directive **must** point at the same file Caddy's `php_server`
directive would classically execute for an unmatched request — in this
setup, `public/index.php`. Caddy falls back to classically re-executing
`index.php` for any request path that doesn't match a real static file
*before* it ever routes to a worker pointed somewhere else. Point `worker`
at a different script and every request will silently keep
re-executing `index.php` from scratch instead of ever reaching your worker,
with no error to indicate why.
```

```{code-block} bash
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app dunglas/frankenphp:latest \
    frankenphp run --config Caddyfile
```

```{note}
FrankenPHP's worker mode keeps `public/index.php` — including route
discovery — loaded in memory across every request it serves. If you edit
a controller while this container is still running, restart it to see the
change; PHP has no way to redeclare an already-loaded class with new
content. For active local development where you're editing code
constantly, PHP-FPM's classic boot-and-die model (which Kinetis detects
and runs under automatically — no code changes needed) rebuilds this on
every single request instead, at the cost of paying discovery's cost
every time rather than once. See {doc}`runtime-adapters`.
```

```{code-block} bash
:caption: Try it

curl -X POST http://localhost:8080/users \
    -H "Content-Type: application/json" \
    -d '{"name": "John Doe", "email": "john@example.com"}'
# {"name":"John Doe","email":"john@example.com"}
```

Every registered route also gets a free, zero-config OpenAPI document and
Swagger UI — visit `http://localhost:8080/docs` right now, no annotations
beyond the attributes already on `UserController` needed. More on that in
{doc}`routing-validation`.

## Next steps

- {doc}`core-concepts` — why persistent workers change the rules, and how
  Kinetis's request lifecycle is built around that.
- {doc}`container` — the two-tier container, and the one PHPStan rule that
  keeps it an enforced guarantee instead of a convention.
- {doc}`routing-validation` — the full attribute vocabulary, validation
  constraints, and the OpenAPI generator.
- {doc}`auth` — opaque Bearer-token authentication middleware, for when
  you want your own token storage (and revocation).
- {doc}`auth-jwt` — stateless JWT authentication instead, verifying a
  signed token with no storage lookup at all.
