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
| `SESSION_DRIVER` | — | `file`, `redis`, or `sql`. Unset means the package binds nothing. |
| `SESSION_LIFETIME` | `7200` | Seconds a session stays readable, counted from its last write — the browser cookie's own `Max-Age` and the backend's storage TTL both restart together on every write, never just one. |
| `SESSION_COOKIE` | `kinetis_session` | The cookie name. A `__Host-`/`__Secure-` prefix is honoured — see [below](#cookie-name-prefixes). |
| `SESSION_SAMESITE` | `Lax` | The cookie's `SameSite` attribute: `Strict`, `Lax`, or `None`, matched regardless of casing. `None` requires `SESSION_SECURE`. |
| `SESSION_SECURE` | `true` | The cookie's `Secure` attribute — set `false` only for non-TLS local development. |
| `SESSION_FILES_DIR` | `<system temp>/kinetis-sessions` | The `file` driver's directory. |

Both cookie settings are checked when the middleware is constructed, not
when a cookie is first written. A `SESSION_SAMESITE` outside those three
values, or `None` without `SESSION_SECURE`, stops the application rather
than sending a header a browser would ignore or a cookie it would drop.

The three drivers:

- **`file`** — one JSON file per session, no backing service; suited
  to local development. An expired file is deleted the next time it is
  read; files for sessions never touched again stay until `session:gc`
  sweeps them (see below).
- **`redis`** — sessions through the `CacheInterface` binding
  `AppScope::boot()` already creates from your Redis configuration, so
  the application keeps one client, and cluster mode and TLS come with
  it. Needs `kinetis/cache-redis` installed and Redis configured —
  `REDIS_URL`, `REDIS_HOST`, or `REDIS_CLUSTER` with
  `REDIS_CLUSTER_SEEDS` — and names both in the error otherwise. No
  garbage collection is needed here: the key's own TTL expires it.
- **`sql`** — a `kinetis_sessions` table, using the database
  connection `DB_CONNECTION` provides. The table is not created
  automatically; it ships as ready-to-copy {doc}`migrations` stubs:

  ```{code-block} text
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.mysql.php.stub
  vendor/kinetis/session/resources/migrations/create_kinetis_sessions_table.pgsql.php.stub
  ```

  An expired session is invisible to reads but its row stays in the
  table until `session:gc` deletes it (see below).

**A session is live only while its expiry is strictly in the future** —
`expires_at > now` for `sql`, the identical boundary for `file`'s own
`expiresAt`. A session expiring at exactly the current second is already
expired on both, not one second short of it. `redis` has no boundary of
its own to state: expiry is the key's TTL.

**`$lifetimeSeconds` (`SESSION_LIFETIME`) is checked the same way
everywhere the package uses it, regardless of driver** — it must be a
positive number of seconds, and adding it to the current Unix timestamp
must stay inside PHP's integer range. Every driver applies both, `redis`
included, even though it never computes an absolute timestamp of its
own. `SESSION_LIFETIME` is checked at middleware construction — before
the handler ever runs — so a misconfigured value never lets a request
perform real work only to fail afterward when the session is written.

The `sql` driver's migration stubs use `DATETIME` on MySQL and
`TIMESTAMP` (without time zone) on Postgres, never MySQL's own
`TIMESTAMP` type or Postgres's `TIMESTAMPTZ`. The store binds a bare
`Y-m-d H:i:s` UTC wall-clock string with no embedded offset, which a
timezone-naive column stores exactly as given. MySQL's `TIMESTAMP`
reinterprets a bound value through the connection's session timezone,
so the same literal can land as a different absolute instant. Choosing
a timezone-naive column keeps a shared connection's session timezone
out of when a session expires; the package never changes that setting
itself.

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

With the `redis` driver there is nothing to schedule: a session key
disappears the moment its TTL lapses, and `session:gc` says so and
exits `0`. A custom store joins the command by implementing
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
post-redirect-get companion. Values must be JSON-serializable
application data: every store projects the session to JSON, and a read
decodes it back to plain arrays and scalars.

Loading is lazy and persisting is conditional: a route that never
touches its session performs no storage round trip and sends no
`Set-Cookie`, so attaching the middleware broadly costs nothing on
session-free requests. The cookie is always `HttpOnly`; `Secure` and
`SameSite` come from configuration.

**Call `regenerate()` whenever privilege changes** — especially on
login. It gives the session a fresh id and a fresh CSRF token, keeps
its application data, and destroys the old id's payload, so neither a
session id nor a CSRF token an attacker planted before login carries
into the authenticated session. Every key the application itself set
survives, flash data included; only the token is discarded, and the
next `csrfToken()` call mints a different one. A form rendered before
the call therefore no longer passes `CsrfMiddleware` after it — render
it again with the new token, which the redirect a login already ends
with does on its own. `destroy()` is logout: payload gone, cookie
expired.

Both are transactional with respect to the request actually succeeding:
`regenerate()`/`destroy()` only ever change in-memory state, and the
store is only ever mutated afterward, when `SessionMiddleware` calls
`commit()` — which only happens once the handler has returned. If a
controller or later middleware throws instead, neither the store nor
the browser's cookie is touched, so the session the request started
with is exactly as usable afterward as if `regenerate()`/`destroy()`
had never been called. A regenerated id's replacement data is written
before the old id is destroyed, so a store failure partway through
`commit()` never loses a session that was still genuinely recoverable.
Once either call's `commit()` has run, the old id is retired and a
stale write cannot recreate it — see
[Concurrency and terminal writes](#concurrency-and-terminal-writes).

### A presented cookie id is never trusted just for being wellformed

`SessionMiddleware` filters a cookie value against the id shape
(32 hex characters) before it ever reaches `Session` — a malformed
value (wrong length, wrong characters) is treated as no cookie at all,
and a fresh id is minted. That check is about *shape*, not
*existence*: a wellformed id the store has never issued — fabricated,
or one that genuinely expired — is exactly the session-fixation
primitive `regenerate()` above defends against from a different angle.
Kinetis closes it at the source instead of relying only on the
application calling `regenerate()` correctly: on the first real access
to the session in a request, the presented id is read from the store,
and if nothing comes back, it is rotated to a fresh id before any
state can be exposed or written under it. A genuinely stored id,
including one whose payload happens to be an empty array, is left
exactly as presented — only an id the store has never heard of is
ever rotated.

That rotation is lazy: it changes only in-memory state and persists
nothing by itself. A read-only check against a rejected cookie —
`get()`, `has()`, or a CSRF check — still performs the one genuine
store read needed to learn the cookie is unknown (unlike the identical
check against a brand-new session with no cookie at all, which touches
the store not at all), but writes nothing and sends no cookie either
way. Something that genuinely needs a
stable identity still gets one, persisted under the already-rotated
fresh id and never the rejected one — a mutation, an explicit call to
`id()`, or generating a CSRF token via `csrfToken()`. This laziness is
what keeps checking a submitted CSRF token — right or wrong — from
being what allocates and persists a session in the first place: an
attacker sending an unlimited number of wrong tokens against cookies
the store has never heard of must not be able to force one stored
session per request — see the next section.

This closes the identifier itself; it says nothing about what an
authenticated session is allowed to do before and after login shares
one id, which is what `regenerate()` still exists for. Call it on
every privilege change regardless.

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
`X-CSRF-Token` header. `csrfToken()` generates one on first use, which
is a real write; call it only where the response is actually going to
carry the token (a form render, a bootstrap payload), not on every
request. A missing or mismatched token is a `403`; the comparison goes
through `Session::verifyCsrfToken()` instead, which is constant-time
via `hash_equals()` and — the reason a separate method exists at all —
never generates a token itself. Checking a submitted token, right or
wrong, must never be what creates one, since `csrfToken()`'s own
generate-on-first-use side effect would otherwise let a wrong token
allocate and persist a whole session for a cookie the store has never
heard of. A mismatch against a genuinely existing session leaves it
completely untouched too — not just unwritten, but its flash data,
TTL, and cookie exactly as they were before the request, even when
that session happens to have flash data pending: checking the token
alone never runs the ordinary per-request flash-aging a real access
would. A *matching* token, by contrast, immediately enters the
session's normal lifecycle — flash-generation-aging included — the
moment it's confirmed, not only if the guarded handler happens to use
`Session` again afterward: a route that does nothing but check CSRF
still ages any flash data pending on that session correctly.

A token does not survive a privilege change — `regenerate()` discards
it along with the old id, as described above.

JSON requests use the header: Kinetis decodes JSON bodies inside the
dispatcher, so a `_token` field inside a JSON body is not seen by this
middleware — only form-encoded bodies carry `_token`.

## Concurrency and terminal writes

No store locks. PHP's native session handler locks the session file,
serializing a browser's parallel requests against each other; that
would conflict with the concurrent-worker model the whole framework is
built around. Concurrent requests sharing one session are
last-write-wins — which is why session data should stay small and
low-contention (an auth reference, the CSRF token, flash data), not a
shared mutable workspace.

Removing a stored id is the exception, because it is terminal. A
request that read a session and writes it back under the same id
succeeds only while that id is still stored and still live: once
`destroy()` (logout) or `regenerate()` (privilege change) has committed,
the id is gone, the overlapping request's write is refused, its commit
discarded, and no `Set-Cookie` sent. That is what stops a stale write
from recreating a logged-out or pre-rotation id. Every store enforces
it: `sql` with a conditional UPDATE, `redis` with a single `SET ... XX`,
`file` by opening the existing record rather than publishing a new one.

The guarantee covers exactly that case. A request that itself calls
`regenerate()` writes under a *new* id, which creation always allows, so
a rotation running alongside a logout is not coordinated with it and can
carry the session forward under the new id. Call `regenerate()` from the
request that changes privilege — the login or elevation itself.

"Last-write-wins" is not "a reader might see a half-written file" for a
newly created session: the file store writes to a temporary file in the
same directory and renames it into place, so a concurrent read sees
either the complete previous state or the complete new one. That
temporary file is named `.sess-tmp-*`, outside `gc()`'s own `sess_*`
glob pattern, so a sweep cannot collect a creation in progress. An
*update* to an existing file is written in place, because a rename
would recreate a record another request may have just removed. A read
overlapping such an update can therefore land on an incomplete
envelope, which reads as an absent session: it fails closed, exposing
nothing.

**The file store enforces confidentiality, not just intends it.** Its
own session directory must have no group or world permissions at all —
checked against the directory's real, current mode on every
construction, so an already-existing, externally-provisioned directory
is refused rather than silently narrowed; this store never changes the
permissions of a directory it did not create itself. Every session
file's real, resulting mode is verified as private (`0600`) before it
is ever allowed to become the live session — a `chmod()` call reporting
success is not trusted on its own, since the file's actual permissions
are read back and compared directly. Either check failing, like every
other write failure, cleans up the temporary file and throws rather
than publishing something that was never confirmed private.

(custom-stores)=
## Custom stores

`SessionStoreInterface` is four methods — `read`, `create`, `update`,
`destroy` — and anything implementing it can be bound in `bootstrap.php`
to replace what `SESSION_DRIVER` would have picked:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Session\SessionStoreInterface;

return static function (AppScope $app, Config $config): void {
    $app->bind(SessionStoreInterface::class, static fn (): MyStore => new MyStore($config));
};
```

`update()` must return `false` when the id has no live record left, so
a custom store honours the terminal rule above. Throw only for a real
storage or encoding failure.

## See also

- {doc}`auth` / {doc}`auth-jwt` — token-based authentication, the
  API-first counterpart to cookie sessions.
- {doc}`persistence` — the SQL contracts the `sql` driver builds on.
- {doc}`middleware` — how route middleware and middleware groups work.
- {doc}`telemetry` — a span per session store call, via the same
  `bootstrap.php` rebind pattern shown above.
