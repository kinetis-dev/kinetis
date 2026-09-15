# Sessions & CSRF

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/session
```
````

Cookie-backed sessions and CSRF protection for browser applications —
the counterpart to the token-based {doc}`auth` and {doc}`auth-jwt`
packages. {doc}`appendix-sessions` holds the lifecycle and store
contracts behind this page.

## Choose a driver

`SESSION_DRIVER` turns the package on; unset, it binds nothing.

```{code-block} text
:caption: .env
SESSION_DRIVER=file
```

| Key | Default | Purpose |
|---|---|---|
| `SESSION_DRIVER` | — | `file`, `redis`, or `sql`. Any other value stops the application at boot. |
| `SESSION_LIFETIME` | `7200` | Seconds a session stays readable after its last write. Every write restarts both the cookie's `Max-Age` and the store's expiry. |
| `SESSION_COOKIE` | `kinetis_session` | The cookie name. See [Cookie security](#cookie-security). |
| `SESSION_SAMESITE` | `Lax` | `Strict`, `Lax`, or `None`. `None` requires `SESSION_SECURE`. |
| `SESSION_SECURE` | `true` | The cookie's `Secure` attribute. |
| `SESSION_FILES_DIR` | `<system temp>/kinetis-sessions` | The `file` driver's directory. |

- **`file`** — one JSON file per session and no backing service; suited
  to local development on one host.
- **`redis`** — stores sessions through the Redis cache the application
  already configures. Needs `kinetis/cache-redis` and `REDIS_URL`,
  `REDIS_HOST`, or `REDIS_CLUSTER` with `REDIS_CLUSTER_SEEDS`. Expiry is
  the key's TTL.
- **`sql`** — a `kinetis_sessions` table on the connection
  `kinetis/database-bridge` binds from `DB_CONNECTION`. Create the table
  from a {doc}`migrations` stub:

  ```{code-block} text
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.mysql.php.stub
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.pgsql.php.stub
  ```

`SESSION_SECURE` defaults to `true`, so a browser sends the cookie back
only over HTTPS. For plain-HTTP local development, set
`SESSION_SECURE=false` there and nowhere else.

## Read and write session data

`SessionMiddleware` is **route middleware, never global**: it registers
the request's `Session` on the request scope, which only route
middleware resolves through. `CsrfMiddleware` follows it. The controller
below renders a form on `GET` and changes state only on `POST`:

```{code-block} php
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\RedirectResponse;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;
use Kinetis\Validation\Constraints\In;
use Kinetis\Views\Views;
use Psr\Http\Message\ResponseInterface;

final readonly class ThemeForm
{
    public function __construct(
        #[In(['light', 'dark'])]
        public string $theme,
    ) {}
}

#[Middleware(SessionMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
final readonly class PreferencesController
{
    public function __construct(
        private Session $session,
        private Views $views,
    ) {}

    #[Get('/preferences')]
    public function edit(): ResponseInterface
    {
        return $this->views->response('preferences/edit', [
            'theme' => $this->session->get('theme', 'light'),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    #[Post('/preferences')]
    public function update(#[Body] ThemeForm $form): ResponseInterface
    {
        $this->session->set('theme', $form->theme);

        return RedirectResponse::to('/preferences', 303);
    }
}
```

`get()`, `set()`, `has()`, `remove()` and `all()` are the data surface.
`flash($key, $value)` stores a value readable through `flashed($key)` on
the following request only — the companion to the redirect above. Values
must be JSON-serializable: every store saves the session as JSON, and a
read returns plain arrays and scalars.

The store is read on first use and written only when something changed.
A route that never touches its session costs no round trip and sends no
`Set-Cookie`, so attaching the middleware to a whole controller is
cheap. The session is written after the handler returns; a request that
throws leaves the stored session and the cookie unchanged.

## CSRF in a browser form

The controller passes the token as ordinary view data, and the view
renders it, escaped, into a hidden `_token` field of a `POST` form:

```{code-block} php
:caption: resources/views/preferences/edit.php

<p>Current theme: <?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?></p>

<form method="post" action="/preferences">
    <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <select name="theme">
        <option value="light">Light</option>
        <option value="dark">Dark</option>
    </select>
    <button type="submit">Save</button>
</form>
```

Latte and Twig escape `{$csrfToken}` and `{{ csrfToken }}` on their
own; {doc}`views` covers escaping in each engine.

- **Order.** Declare `SessionMiddleware` before `CsrfMiddleware`;
  declaration order is execution order. Reversed, every state-changing
  request answers `500` naming the mistake.
- **Safe methods.** `GET`, `HEAD` and `OPTIONS` pass without a token
  check, so they must not change application state: another site can
  make a browser send them with the user's cookie. The only session
  write the `GET` above can cause is creating the token on its first
  render.
- **Every other method** needs the token, either as `_token` in a
  form-encoded or multipart body or as the `X-CSRF-Token` header. A
  missing or wrong token answers `403` (`CSRF token mismatch.`), and the
  handler never runs.
- **No tokens in URLs.** The middleware never reads the query string,
  and a URL leaks through logs, browser history and `Referer`.
- **Call `csrfToken()` where the response carries the token.** Its first
  call creates the token and persists the session, so call it when
  rendering a form or a page script, not on every request.

## CSRF from JavaScript

Render the token into the page, then send it in the `X-CSRF-Token`
header:

```{code-block} php
:caption: in the layout's head

<meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
```

```{code-block} javascript
const token = document.querySelector('meta[name="csrf-token"]').content;

await fetch('/preferences', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
    },
    body: JSON.stringify({theme: 'dark'}),
});
```

The same route accepts this request, since `#[Body]` hydrates
`ThemeForm` from JSON as well as from form fields. A JSON request sends
the token in the header only: the middleware reads `_token` from
form-encoded and multipart bodies, never from a JSON document.

## Regenerate on privilege change

Call `regenerate()` on login and on every other privilege change, then
redirect:

```{code-block} php
$this->session->regenerate();
$this->session->set('userId', $user->id);

return RedirectResponse::to('/dashboard', 303);
```

It gives the session a fresh id and a fresh CSRF token and keeps the
application data, so neither an id nor a token planted before login
carries into the authenticated session. A page rendered before the call
holds a token that now answers `403`; the redirect renders the new one.
Call `destroy()` on logout: the stored data is removed and the cookie
expired.

## Cookie security

Every session cookie is `HttpOnly`, `Path=/`, and carries no `Domain`.
In production, keep `SESSION_SECURE=true` and name the cookie with the
`__Host-` prefix:

```{code-block} text
:caption: .env (production)
SESSION_COOKIE=__Host-kinetis_session
```

A browser then refuses the cookie unless it is `Secure`, `Path=/` and
host-only, so a compromised sibling subdomain cannot overwrite it.
`__Secure-` enforces `Secure` alone. Keep `SESSION_SAMESITE=Lax` unless
the application needs `Strict`; `None` requires `SESSION_SECURE`.

A combination a browser would reject — a prefix or `SameSite=None`
without `Secure`, an unknown `SameSite` value, or an invalid cookie
name — fails when the middleware is constructed, before any cookie is
sent. For plain-HTTP development, drop the prefix along with `Secure`.
{doc}`appendix-sessions` explains the prefix rules.

## Garbage collection

The `file` and `sql` drivers keep expired sessions in storage until
`session:gc` deletes them:

```{code-block} sh
php vendor/bin/kinetis session:gc
```

It deletes every expired session from the bound store and prints how
many were removed. Nothing runs it implicitly — schedule it with
whatever the deployment already uses (cron, a Kubernetes CronJob, an
EventBridge rule), the same way as any other {doc}`cli` command. Once a
day suits most applications: expired sessions are already invisible to
reads, so the schedule only controls how long dead data lingers.

With the `redis` driver there is nothing to schedule: a key disappears
when its TTL lapses, and `session:gc` says so and exits `0`.

## Concurrent requests

Stores take no locks. Concurrent requests sharing one session are
last-write-wins, so keep session data small and low-contention — an
auth reference, the CSRF token, flash data — rather than a shared
workspace. Logout and regeneration are the exception: once either has
committed, an overlapping request cannot write the old id back.

To replace the built-in drivers, bind your own `SessionStoreInterface`;
see {ref}`custom-stores`.

## See also

- {doc}`appendix-sessions` — expiry, cookie prefixes, id verification,
  the CSRF check lifecycle, terminal writes, and custom stores.
- {doc}`views` — rendering and escaping view data.
- {doc}`auth` / {doc}`auth-jwt` — token-based authentication, the
  API-first counterpart to cookie sessions.
- {doc}`middleware` — how route middleware and middleware groups work.
- {doc}`telemetry` — a span per session store call.
