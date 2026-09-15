# Appendix: Sessions Reference

The contracts behind {doc}`session`: expiry, cookie name prefixes, how a
presented cookie id is verified, how a CSRF check interacts with the
session lifecycle, when writes reach the store, and what a custom store
must honour. For the task-first path — drivers, middleware, forms,
JavaScript clients and cookie settings — see {doc}`session`.

## Expiry and lifetime

**A session is live only while its expiry is strictly in the future** —
`expires_at > now` for `sql`, the identical boundary for `file`'s own
`expiresAt`. A session expiring at exactly the current second is already
expired on both. `redis` has no boundary of its own to state: expiry is
the key's TTL.

An expired `file` session is deleted the next time it is read; files for
sessions never touched again stay until `session:gc` sweeps them. An
expired `sql` session is invisible to reads, and its row stays until
`session:gc` deletes it.

**`SESSION_LIFETIME` is checked the same way regardless of driver** — it
must be a positive number of seconds, and adding it to the current Unix
timestamp must stay inside PHP's integer range. Every driver applies
both, `redis` included, even though it never computes an absolute
timestamp of its own. The value is checked at middleware construction,
before the handler runs, so a misconfigured value never lets a request
perform real work only to fail when the session is written.
`SESSION_SAMESITE` and `SESSION_COOKIE` are checked at the same point.

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

The `sql` driver uses the link `kinetis/database-bridge` binds, or a
`MysqlLink`/`PostgresLink` your own `bootstrap.php` binds. The `redis`
driver requires the application's `CacheInterface` binding to be
`RedisSimpleCache`, which `AppScope::boot()` registers when Redis is
configured. Both name what is missing when they cannot find it.

## Cookie name prefixes

Every session cookie is sent `HttpOnly`, `Path=/`, with no `Domain`, and
`Secure` unless `SESSION_SECURE` is off. Those are attributes a server
*requests*; a cookie name prefix is what makes the browser *enforce*
them.

`__Secure-` tells the browser to refuse the cookie unless it is marked
`Secure`. `__Host-` refuses it unless it is `Secure`, `Path=/`, and
carries no `Domain` — which pins it to exactly one host, so a
compromised sibling subdomain cannot overwrite the session cookie.
Kinetis already writes cookies that satisfy both, so either prefix works
as soon as `SESSION_SECURE` is on.

The prefix has to reach the browser to mean anything, so a prefixed name
with `SESSION_SECURE=false` is refused at startup rather than sent. A
browser would drop such a cookie on every response, which presents as
sessions that never persist and says nothing about why.

Matching is case-sensitive, as the specification defines it: `__host-`
is an ordinary name that no browser enforces anything about.

## Presented cookie ids

`SessionMiddleware` filters a cookie value against the id shape
(32 lowercase hex characters) before it reaches `Session` — a malformed
value is treated as no cookie at all, and a fresh id is minted. That
check is about *shape*, not *existence*: a wellformed id the store has
never issued — fabricated, or one that expired — is the session-fixation
primitive `regenerate()` defends against from a different angle.
Kinetis closes it at the source instead of relying only on the
application calling `regenerate()`: on the first real access to the
session in a request, the presented id is read from the store, and if
nothing comes back, it is rotated to a fresh id before any state can be
exposed or written under it. A stored id, including one whose payload is
an empty array, is left exactly as presented.

That rotation is lazy: it changes only in-memory state and persists
nothing by itself. A read-only check against a rejected cookie —
`get()`, `has()`, or a CSRF check — still performs the one store read
needed to learn the cookie is unknown (unlike the same check against a
brand-new session with no cookie at all, which touches the store not at
all), but writes nothing and sends no cookie. Something that needs a
stable identity still gets one, persisted under the already-rotated
fresh id and never the rejected one — a mutation, an explicit call to
`id()`, or generating a token via `csrfToken()`.

This closes the identifier itself; it says nothing about what an
authenticated session is allowed to do when login and the anonymous
session before it share one id, which is what `regenerate()` still
exists for.

## The CSRF check

`CsrfMiddleware` passes `GET`, `HEAD` and `OPTIONS` untouched. For every
other method it takes the `X-CSRF-Token` header when it is non-empty,
otherwise a string `_token` member of `getParsedBody()`, which only
form-encoded and multipart bodies populate. With no `Session` registered
on the request — `SessionMiddleware` missing or declared after it — it
answers `500` naming the declaration-order mistake. A missing or
mismatched token answers `403` with the body
`{"error": "CSRF token mismatch."}`.

The comparison goes through `Session::verifyCsrfToken()`, which is
constant-time via `hash_equals()` and never generates a token. A session
that never had `csrfToken()` called holds no token, so every submitted
value fails. Checking a submitted token, right or wrong, is therefore
never what creates one: an attacker sending unlimited wrong tokens
against cookies the store has never heard of cannot force one stored
session per request.

A mismatch against an existing session leaves it untouched — not just
unwritten, but its flash data, TTL and cookie exactly as they were,
because checking the token alone never runs the per-request flash aging
a real access would. A *matching* token enters the session's normal
lifecycle the moment it is confirmed, flash aging included, reusing the
store read that verified it: a route that does nothing but pass the CSRF
check still ages pending flash data correctly.

`regenerate()` discards the token along with the old id, and the next
`csrfToken()` call mints a different one.

## When writes reach the store

`regenerate()` and `destroy()` change only in-memory state. The store is
mutated afterward, when `SessionMiddleware` calls `commit()`, which
happens only once the handler has returned. If a controller or later
middleware throws instead, neither the store nor the browser's cookie is
touched, so the session the request started with is exactly as usable
as if neither call had been made.

A regenerated id's replacement data is written before the old id is
destroyed, so a store failure partway through `commit()` never loses a
session that was still recoverable. A destroyed session's data is
removed before the response expires the cookie. Every successful write
sends a fresh `Set-Cookie`, even when the id is unchanged, so the
cookie's `Max-Age` and the store's expiry count from the same write.

## Concurrency and terminal writes

No store locks. PHP's native session handler locks the session file,
serializing a browser's parallel requests against each other; that
would conflict with the concurrent-worker model the framework is built
around. Concurrent requests sharing one session are last-write-wins.

Removing a stored id is the exception, because it is terminal. A
request that read a session and writes it back under the same id
succeeds only while that id is still stored and still live: once
`destroy()` or `regenerate()` has committed, the id is gone, the
overlapping request's write is refused, its commit discarded, and no
`Set-Cookie` sent. That is what stops a stale write from recreating a
logged-out or pre-rotation id. Every store enforces it: `sql` with a
conditional UPDATE, `redis` with a single `SET ... XX`, `file` by
opening the existing record rather than publishing a new one.

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

## File store permissions

The file store's session directory must have no group or world
permissions at all — checked against the directory's real, current mode
on every construction, so an existing, externally provisioned directory
is refused rather than silently narrowed; the store never changes the
permissions of a directory it did not create itself. Every session
file's resulting mode is verified as private (`0600`) before it becomes
the live session — a `chmod()` reporting success is not trusted on its
own, since the file's actual permissions are read back and compared.
Either check failing, like every other write failure, cleans up the
temporary file and throws rather than publishing something that was
never confirmed private.

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

`read()` returns `null` for a session that does not exist or has
expired. `update()` must return `false` when the id has no live record
left, so a custom store honours the terminal rule above; throw only for
a real storage or encoding failure. A store that keeps expired records
joins `session:gc` by also implementing
`GarbageCollectableStoreInterface` — one method, `gc(): int`, returning
how many sessions it removed.

## See also

- {doc}`session` — the task-first guide.
- {doc}`appendix-configuration` — every `SESSION_*` key.
- {doc}`appendix-packages` — `Kinetis\Session` in the package map.
- {doc}`telemetry` — wrapping a store for tracing with the same
  `bootstrap.php` rebind pattern.
