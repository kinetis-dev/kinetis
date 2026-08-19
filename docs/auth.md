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

````{danger}
**Give this a cache that can count atomically.** `RedisSimpleCache` and
`ClusteredRedisSimpleCache` can, through
`Kinetis\SimpleCache\AtomicCounterInterface`; see {doc}`middleware`'s
rate-limiting section for the `REDIS_URL`/`REDIS_HOST` configuration they
read. Any other PSR-16 cache is accepted and locks out sequential
failures, but cannot count failures that arrive together — measured
against a real Redis, 40 parallel wrong passwords recorded a **single**
failure and the lockout never armed.

That is the normal shape of the attack this class exists to stop:
someone working through a password list sends attempts in parallel by
default. Check it where it matters:

```{code-block} php
if (!$throttle->countsAtomically()) {
    // Sequential lockout only — parallel attempts can outrun this.
}
```
````

## See also

- {doc}`middleware` — `CurrentUserInterface`, the global-vs-route
  middleware distinction, and `RequestScope` self-injection.
- {doc}`persistence` — `Query`/`TransactionGuard` for a database-backed
  `UserProviderInterface`.
- {doc}`auth-jwt` — stateless JWT verification instead, with no token
  storage at all.
