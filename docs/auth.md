# Authentication

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/auth
```
````

Bearer/opaque-token authentication: a PSR-15 route middleware that
validates an `Authorization: Bearer <token>` header and registers the
resolved user on the current request as `CurrentUserInterface`, plus a
token generator. Storage is entirely up to you — the package has no
opinion on where tokens live.

```{code-block} php
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Auth\BearerAuthMiddleware;

#[Middleware(BearerAuthMiddleware::class)]
final readonly class OrderController
{
    public function __construct(
        private CurrentUserInterface $user,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return ['userId' => $this->user->id()];
    }
}
```

## The accepted `Authorization` header

`Kinetis\Http\Auth\BearerCredentialParser` (core) parses the header —
the same class {doc}`auth-jwt`'s `JwtAuthMiddleware` uses, so both
packages accept identical input rather than risking two independently-
drifting parsers. The scheme (`Bearer`) is matched case-insensitively;
the separator between scheme and credential must be one or more literal
space characters (a tab or other whitespace doesn't count); the
credential itself must consist only of the RFC 6750 `b64token`
characters (`A-Za-z0-9-._~+/`) with `=` padding allowed only as a
trailing run. A request with anything other than exactly one
`Authorization` header line is rejected too — two lines are ambiguous,
not a value to comma-join and hope. Any of this failing is
indistinguishable from an unknown token: the same generic `401`.

## `UserProviderInterface`

The one thing your app implements — resolving a raw token to a user, or
`null` if it doesn't match anything:

```{code-block} php
use Kinetis\Auth\UserProviderInterface;
use Kinetis\Http\CurrentUserInterface;

final readonly class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(
        private MysqlLink $db,
    ) {}

    public function findByToken(string $token): ?CurrentUserInterface
    {
        $hash = hash('sha256', $token);

        $row = new Query($this->db)
            ->table('users')
            ->where('token_hash', '=', $hash)
            ->first(UserRow::class);

        return $row;
    }
}
```

Register it once, against the interface:

```{code-block} php
$app->instance(UserProviderInterface::class, new DatabaseUserProvider($db));
```

```{tip}
Store `hash('sha256', $token)`, not the raw token, and look up by that
same hash. Don't use `password_hash()`/bcrypt here — a bearer token is
already high-entropy random data, not a low-entropy human password, so a
slow KDF only adds latency to every request's lookup with no security
benefit.
```

## `BearerAuthMiddleware` is route middleware only

Register it with `#[Middleware(BearerAuthMiddleware::class)]` on the
controllers or methods that need it — never globally. A health check, a
login endpoint, or `/openapi.json` needs to stay reachable without a
token, and route middleware only runs after a route has already matched,
so there's no way for it to block an unmatched request the way global
middleware could.

On a missing, malformed, or unrecognized token it returns `401` directly,
with a `WWW-Authenticate: Bearer` header, before your controller ever
runs:

```{code-block} json
{"error": "Unauthenticated."}
```

On success it does the same thing a hand-written auth middleware would
(see {doc}`middleware`'s "Registering a value the controller reads later"
section) — `$scope->instance(CurrentUserInterface::class, $user)` — so any
controller constructor-injecting `CurrentUserInterface` receives it.

## `TokenGenerator`

```{code-block} php
use Kinetis\Auth\TokenGenerator;

$token = TokenGenerator::generate(); // 64 hex characters, 32 bytes of entropy
```

A thin wrapper over `random_bytes()`, hex-encoded so the result is safe to
place directly in an `Authorization` header with no escaping. Generation
only — issuing a token to a user (verifying a password, calling this,
storing the hash) is your own login endpoint's job.

## `PasswordHasher`

```{code-block} php
use Kinetis\Auth\PasswordHasher;

$hash = PasswordHasher::hash($request->password); // at registration
```

```{code-block} php
if (!PasswordHasher::verify($request->password, $user->passwordHash)) {
    return ErrorResponse::create(401, 'Invalid credentials.');
}

if (PasswordHasher::needsRehash($user->passwordHash)) {
    $this->users->updatePasswordHash($user->id, PasswordHasher::hash($request->password));
}
```

`hash()`/`verify()`/`needsRehash()` wrap PHP's own
`password_hash()`/`password_verify()`/`password_needs_rehash()`, always
with `PASSWORD_DEFAULT` — so a hash produced under an older PHP version
still verifies correctly, and `needsRehash()` tells you when it's worth
upgrading to whatever PHP now recommends. Storage — where the hash lives,
when to call `needsRehash()` — is your own concern; this covers only the
three primitives.

## Preventing brute-force login attempts

`Kinetis\Security\AttemptThrottle` locks an identifier out after too many
failures, backed by `Psr\SimpleCache\CacheInterface`:

```{code-block} php
use Kinetis\Security\AttemptThrottle;
use Kinetis\Http\Responses\ErrorResponse;

final readonly class LoginController
{
    public function __construct(
        private AttemptThrottle $throttle,
        private UserProviderInterface $users,
    ) {}

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data): ResponseInterface|array
    {
        if ($this->throttle->tooManyAttempts($data->email)) {
            return ErrorResponse::create(429, 'Too many attempts.', headers: [
                'Retry-After' => (string) $this->throttle->availableInSeconds($data->email),
            ]);
        }

        $user = $this->users->verify($data->email, $data->password);

        if ($user === null) {
            $this->throttle->recordFailure($data->email);
            return ErrorResponse::create(401, 'Invalid credentials.');
        }

        $this->throttle->clear($data->email);

        return ['token' => TokenGenerator::generate()];
    }
}
```

The default is 5 failures within a rolling 15-minute window, adjustable
through the constructor:

```{code-block} php
new AttemptThrottle($cache, maxAttempts: 3, decaySeconds: 600);
```

Each failure resets the window to a fresh `decaySeconds` from that
failure, so repeated attempts keep extending the lockout; `clear()` on a
successful attempt removes it immediately. Identifiers aren't limited to
emails — anything failure-prone and identifier-keyed works the same way,
a 2FA code or an invite redemption included.

Two `AttemptThrottle` instances with the same `maxAttempts`/`decaySeconds`
guarding two different purposes for the same identifier — a login
password check and a 2FA code check for the same email, say — share a
bucket unless told otherwise: pass a distinct `namespace` to each.

```{code-block} php
$loginThrottle = new AttemptThrottle($cache, namespace: 'login');
$twoFactorThrottle = new AttemptThrottle($cache, namespace: '2fa');
```

Two throttles that differ in `maxAttempts` or `decaySeconds` already get
independent buckets with no `namespace` needed — that's the default.
`namespace` only exists for the one case those alone can't distinguish.

```{warning}
**Changing `maxAttempts`, `decaySeconds`, or `namespace` changes the
underlying cache key.** Deploying that change resets every identifier's
lockout state — usually harmless (a clean slate, not a security gap),
but worth knowing if you're relying on an active lockout surviving a
deploy.
```

````{note}
**The cache must count atomically, and construction enforces it.**
`AttemptThrottle` requires the given cache to implement
`Kinetis\SimpleCache\AtomicCounterInterface` — `RedisSimpleCache` and
`ClusteredRedisSimpleCache` do, see {doc}`middleware`'s rate-limiting
section for the `REDIS_URL`/`REDIS_HOST` configuration they read — and
throws `Exception\AttemptThrottleUnavailableException` at construction
for any cache that doesn't, `NullSimpleCache` included.

Without it, failures arriving together cannot be counted — every
attempt reads the same value before any of them writes, so they
register as one and the lockout never arms. That is the normal shape
of the attack this class exists to stop: someone working through a
password list sends attempts in parallel by default. Measured against
a real Redis, 40 parallel wrong passwords recorded a **single**
failure without this guard.
````

## See also

- {doc}`middleware` — `CurrentUserInterface`, the global-vs-route
  middleware distinction, and `RequestScope` self-injection.
- {doc}`persistence` — `Query`/`TransactionGuard` for a database-backed
  `UserProviderInterface`.
- {doc}`auth-jwt` — stateless JWT verification instead, with no token
  storage at all.
