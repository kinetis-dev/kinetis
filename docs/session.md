# Sessions & CSRF

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/session
```
````

Cookie-backed sessions and CSRF protection for browser applications —
the counterpart to the token-based {doc}`auth` and {doc}`auth-jwt`
packages. One store interface, three storage drivers, and two route
middlewares.

## Configuration

Installing the package is the only setup step; `SESSION_DRIVER` turns
it on:

| Key | Default | Purpose |
|---|---|---|
| `SESSION_DRIVER` | — | `file`, `cache`, or `sql`. Unset means the package binds nothing. |
| `SESSION_LIFETIME` | `7200` | Seconds a session stays readable, counted from its last write. |
| `SESSION_COOKIE` | `kinetis_session` | The cookie name. A `__Host-`/`__Secure-` prefix is honoured — see [below](#cookie-name-prefixes). |
| `SESSION_SAMESITE` | `Lax` | The cookie's `SameSite` attribute. |
| `SESSION_SECURE` | `true` | The cookie's `Secure` attribute — set `false` only for non-TLS local development. |
| `SESSION_FILES_DIR` | system temp | The `file` driver's directory. |

The three drivers:

- **`file`** — one JSON file per session, no backing service; suited
  to local development. An expired file is deleted the next time it is
  read; files for sessions never touched again stay until `session:gc`
  sweeps them (see below).
- **`cache`** — sessions through the PSR-16 `CacheInterface` binding.
  With `REDIS_HOST`/`REDIS_URL` configured and `kinetis/cache-redis`
  installed, that means **Redis-backed sessions with zero further
  code** — cluster mode and TLS included, since the same binding
  already provides both. No garbage collection is needed with this
  driver: the backend expires entries itself — a Redis session key
  simply disappears when its TTL lapses. A `NullSimpleCache` binding is
  rejected at construction: a session store that never stores would
  mean logins that silently don't stick.
- **`sql`** — a `kinetis_sessions` table, using the database
  connection `DB_CONNECTION` provides. The table is not created
  automatically; it ships as ready-to-copy {doc}`migrations` stubs:

  ```{code-block} text
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.mysql.php.stub
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.pgsql.php.stub
  ```

  An expired session is invisible to reads but its row stays in the
  table until `session:gc` deletes it (see below).

### Cookie name prefixes

Every session cookie is sent `HttpOnly`, `Path=/`, with no `Domain`, and
`Secure` unless you turn `SESSION_SECURE` off. Those are attributes you
*request*; a cookie name prefix is what makes the browser *enforce*
them:

```{code-block} text
:caption: .env
SESSION_COOKIE=__Host-kinetis_session
```

`__Secure-` tells the browser to refuse the cookie unless it is marked
`Secure`. `__Host-` refuses it unless it is `Secure`, `Path=/`, and
carries no `Domain` — which pins it to exactly one host, so a
compromised sibling subdomain cannot overwrite your session cookie.
Kinetis already writes cookies that satisfy both, so either prefix works
as soon as `SESSION_SECURE` is on, with nothing else to change.

Prefer `__Host-` unless you genuinely need the cookie shared across
subdomains.

```{note}
The prefix has to reach the browser to mean anything, so a prefixed name
with `SESSION_SECURE=false` is refused at startup rather than sent. A
browser would drop such a cookie on every response, which presents as
sessions that never persist and says nothing about why. For non-TLS
local development, drop the prefix along with `Secure`.

Matching is case-sensitive, as the specification defines it: `__host-`
is an ordinary name that no browser enforces anything about.
```

## Garbage collection

The `file` and `sql` drivers keep expired sessions in storage until
something deletes them. The `session:gc` command is that something:

```{code-block} sh
php vendor/bin/kinetis session:gc
```

It deletes every expired session from whichever store is bound and
prints how many were removed. Nothing runs it implicitly — schedule it
with whatever the deployment already uses (cron, a Kubernetes CronJob,
an EventBridge rule), the same way any other {doc}`cli` command is
scheduled. Once a day is plenty for most applications; expired
sessions are already invisible to reads either way, so the schedule
only controls how long dead data lingers, never correctness.

With the `cache` driver there is nothing to schedule: the backend
expires entries on its own (Redis drops a session key the moment its
TTL lapses), and `session:gc` says so and exits `0`. A custom store
joins the command by implementing
`GarbageCollectableStoreInterface` — one method, `gc(): int`.

## Using the session

`SessionMiddleware` is **route middleware, never global** — it
registers the request's `Session` on the request's own scope, and only
route middleware resolves through that scope. Attach it to a controller
and inject `Session`:

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;

#[Middleware(SessionMiddleware::class)]
final readonly class PreferencesController
{
    public function __construct(private Session $session) {}

    #[Get('/theme/{name}')]
    public function choose(string $name): array
    {
        $this->session->set('theme', $name);

        return ['theme' => $name];
    }

    #[Get('/theme')]
    public function current(): array
    {
        return ['theme' => $this->session->get('theme', 'light')];
    }
}
```

`get()`/`set()`/`has()`/`remove()`/`all()` are the surface;
`flash($key, $value)` stores a value that survives exactly one
following request, read back with `flashed($key)` — the classic
post-redirect-get companion. Values must be JSON-serializable: stores
encode with JSON, never PHP's native `serialize()`, so a crafted
payload can never become an object-injection vector.

Loading is lazy and persisting is conditional: a route that never
touches its session performs no storage round trip and sends no
`Set-Cookie`, so attaching the middleware broadly costs nothing on
session-free requests. The cookie is always `HttpOnly`; `Secure` and
`SameSite` come from configuration.

**Call `regenerate()` whenever privilege changes** — especially on
login. It gives the session a fresh id, keeps its data, and destroys
the old id's payload, so a session id an attacker planted before login
stops working. `destroy()` is logout: payload gone, cookie expired.

## CSRF protection

`CsrfMiddleware` enforces a synchronizer token on state-changing
methods — GET/HEAD/OPTIONS pass untouched. Stack it after
`SessionMiddleware` (declaration order is execution order):

```{code-block} php
#[Middleware(SessionMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
final readonly class OrderController
{
    // ...
}
```

The token comes from `Session::csrfToken()` — render it into a form's
`_token` field or hand it to a client that then sends the
`X-CSRF-Token` header. A missing or mismatched token is a `403`;
comparison uses `hash_equals()`, so it is not vulnerable to timing
attacks.

JSON requests use the header: Kinetis decodes JSON bodies inside the
dispatcher, so a `_token` field inside a JSON body is not seen by this
middleware — only form-encoded bodies carry `_token`.

## Concurrency: last-write-wins

No store locks. PHP's native session handler locks the session file,
serializing a browser's parallel requests against each other; that
would conflict with the concurrent-worker model the whole framework is
built around. Concurrent requests sharing one session are
last-write-wins — which is why session data should stay small and
low-contention (an auth reference, the CSRF token, flash data), not a
shared mutable workspace.

## Custom stores

`SessionStoreInterface` is three methods — `read`, `write`, `destroy` —
and anything implementing it can be bound in `bootstrap.php` to replace
what `SESSION_DRIVER` would have picked:

```{code-block} php
$app->bind(SessionStoreInterface::class, static fn (): MyStore => new MyStore(...));
```

## See also

- {doc}`auth` / {doc}`auth-jwt` — token-based authentication, the
  API-first counterpart to cookie sessions.
- {doc}`persistence` — the SQL contracts the `sql` driver builds on.
- {doc}`middleware` — how route middleware and middleware groups work.
