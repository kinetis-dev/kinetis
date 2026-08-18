<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/session</strong>
  <br>
  <strong>Cookie-backed sessions and CSRF protection for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/session"><img src="https://img.shields.io/packagist/v/kinetis/session?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/session"><img src="https://img.shields.io/packagist/dt/kinetis/session" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/session"><img src="https://img.shields.io/packagist/php-v/kinetis/session" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/session"><img src="https://img.shields.io/packagist/l/kinetis/session" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

The browser-application counterpart to the token-based `kinetis/auth`
and `kinetis/auth-jwt` packages: one store interface, three storage
drivers, and two route middlewares.

```php
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;

#[Middleware(SessionMiddleware::class)]
#[Middleware(CsrfMiddleware::class)]
final readonly class PreferencesController
{
    public function __construct(private Session $session) {}
}
```

Sessions load lazily and persist only when written: a route that never
touches its session performs no storage round trip and sends no cookie.
Payloads are JSON, never PHP `serialize()` — a crafted payload can't
become an object-injection vector. Concurrency is last-write-wins by
design (no session locking to serialize a browser's parallel requests).
`Session::regenerate()` is the session-fixation defense — call it on
login.

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A container binding** for `SessionStoreInterface`, driven by
  `SESSION_DRIVER` — `file` (one JSON file per session, suited to
  development), `cache` (sessions through the PSR-16 binding, which with
  `kinetis/cache-redis` and `REDIS_HOST` configured means Redis-backed
  sessions with zero further code, cluster and TLS included), or `sql`
  (a `kinetis_sessions` table over the persistence contracts, migration
  stubs shipped in `resources/migrations/`). Unset means the package
  binds nothing.
- **One command** on `vendor/bin/kinetis`: `session:gc`, deleting
  expired sessions from the bound store — schedule it with cron or an
  equivalent for the `file` and `sql` drivers. The `cache` driver needs
  no collection: its backend (Redis TTL) expires entries itself.

Nothing else. Both middlewares are explicit opt-ins attached per route
or controller — `SessionMiddleware` registers the request's `Session`
on the request scope, `CsrfMiddleware` (stacked after it) enforces a
synchronizer token on state-changing methods via `X-CSRF-Token` or a
form's `_token` field, with `hash_equals()` comparison.

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `SESSION_DRIVER` | — | `file`, `cache`, or `sql`; unset = inert. |
| `SESSION_LIFETIME` | `7200` | Seconds a session stays readable from its last write. |
| `SESSION_COOKIE` | `kinetis_session` | Cookie name. A `__Host-`/`__Secure-` prefix requires `SESSION_SECURE`. |
| `SESSION_SAMESITE` | `Lax` | Cookie `SameSite` attribute: `Strict`, `Lax`, or `None`, any casing. `None` requires `SESSION_SECURE`. |
| `SESSION_SECURE` | `true` | Cookie `Secure` attribute — `false` only for non-TLS local dev. |
| `SESSION_FILES_DIR` | system temp | The `file` driver's directory. |

## Installation

```sh
composer require kinetis/session
```

Requires PHP 8.4+. Full documentation:
[docs.kinetis.dev/session.html](https://docs.kinetis.dev/session.html).

## License

MIT — see [LICENSE](../../LICENSE).
