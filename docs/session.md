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

**A session is live only while its expiry is strictly in the future** —
`expires_at > now` for `sql`, the identical boundary for `file`'s own
`expiresAt`. A session expiring at exactly the current second is already
expired on both, not one second short of it. `cache` has no boundary of
its own to state: expiry is entirely the backend's own TTL semantics.

**`$lifetimeSeconds` (`SESSION_LIFETIME`) is validated the same way
everywhere the package uses it, regardless of driver** — zero or
negative is rejected outright, and any value that would push the
expiry past `9999-12-31 23:59:59 UTC` is rejected too: MySQL's own
`DATETIME` column — the type this package's own MySQL migration stub
uses — can't store a later date (confirmed directly against a real
server — a value one second past this fails with a genuine `Incorrect
datetime value` error, not a silent clamp), so this is the portable
ceiling every driver enforces, even `cache`, which never computes an
absolute timestamp of its own. `SESSION_LIFETIME` is checked at
middleware construction — before the handler ever runs — so a
misconfigured value never lets a request perform real work only to fail
afterward when the session is written.

The `sql` driver's migration stubs use `DATETIME` on MySQL and
`TIMESTAMP` (without time zone) on Postgres, never MySQL's own
`TIMESTAMP` type or Postgres's `TIMESTAMPTZ` — both store the exact
literal UTC wall-clock value this package writes, unaffected by
whatever timezone the database connection itself happens to be
configured with. MySQL's `TIMESTAMP` type, by contrast, reinterprets a
bound value through the connection's own session timezone, confirmed
directly against a real server: the same literal string can be stored
as a materially different absolute instant, or rejected outright even
when comfortably within the range above, purely depending on that
setting. Sessions are never expected to expire early — or fail to write
at all — because of how a shared connection's session timezone happens
to be configured.

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
shared mutable workspace. "Last-write-wins" means exactly that, not "a
reader might see a half-written file": the file store writes to a
temporary file in the same directory and renames it into place, so a
concurrent read always sees either the complete previous write or the
complete new one, never a partial one. That temporary file is named
`.sess-tmp-*`, deliberately outside `gc()`'s own `sess_*` glob pattern —
a session mid-write must never be collectable while it's still in
progress.

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
- {doc}`telemetry` — a span per session read/write/destroy, via the same
  `bootstrap.php` rebind pattern shown above.
