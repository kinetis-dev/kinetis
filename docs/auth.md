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

The database example below also needs `kinetis/database-bridge` and
`kinetis/query-builder`. Install them and configure `DB_CONNECTION=mysql`
and its credentials as shown in {doc}`persistence`.

```{code-block} sh
composer require kinetis/database-bridge kinetis/query-builder
```

```{code-block} php
use Kinetis\Auth\UserProviderInterface;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

// Your own row DTO, hydrated from whichever columns you select. What
// makes it usable here is the one method CurrentUserInterface asks for.
final readonly class UserRow implements CurrentUserInterface
{
    public function __construct(
        public int $id,
        public string $email,
    ) {}

    public function id(): int
    {
        return $this->id;
    }
}

final readonly class DatabaseUserProvider implements UserProviderInterface
{
    public function __construct(
        private MysqlLink $db,
    ) {}

    public function findByToken(string $token): ?CurrentUserInterface
    {
        $row = new Query($this->db)
            ->table('users')
            ->where('token_hash', '=', hash('sha256', $token))
            ->first(UserRow::class);

        // Given a class, first() hydrates a UserRow or answers null.
        // Its declared return also covers the plain-array form it uses
        // when given none, so narrow before returning.
        return $row instanceof UserRow ? $row : null;
    }
}
```

Bind it once, against the interface, in `bootstrap.php`:

```{code-block} php
:caption: bootstrap.php

use Kinetis\Auth\UserProviderInterface;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

return static function (AppScope $app, Config $config): void {
    $app->bind(UserProviderInterface::class, DatabaseUserProvider::class);
};
```

`DatabaseUserProvider` holds only the database connection, which is
request-neutral, so one instance serves every request a worker handles.
{doc}`bootstrapping` covers choosing that lifetime.

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

On a missing token, a malformed header, or an unrecognized token it
returns `401` directly, with a `WWW-Authenticate: Bearer` header, before
your controller ever runs:

```{code-block} json
{"error": "Unauthenticated."}
```

The request must carry exactly one `Authorization` header in the
`Bearer <token>` form; {ref}`auth-reference-authorization-header` has the
exact grammar, shared with {doc}`auth-jwt`.

On success it does the same thing a hand-written auth middleware would
(see {doc}`middleware`'s "Registering a value the controller reads later"
section) — `$scope->instance(CurrentUserInterface::class, $user)` — so any
controller constructor-injecting `CurrentUserInterface` receives it.

## The document says which routes it guards

`BearerAuthMiddleware` describes itself to the OpenAPI generator, so a
route it wraps publishes the requirement with nothing further to write:

```{code-block} json
"security": [{"bearerToken": []}]
```

```{code-block} json
:caption: components/securitySchemes

"bearerToken": {
    "type": "http",
    "scheme": "bearer",
    "description": "An opaque token issued by this application, sent as \"Authorization: Bearer <token>\"."
}
```

The name `bearerToken` and the definition are fixed. They describe the
wire mechanism this middleware enforces, not a deployment's own tokens,
and a client generated from the document reads the name. A subclass —
the one that joins a middleware group — publishes the same description.

{ref}`openapi-security` has the composition rules, and
`#[OpenApiSecurity]` for an operation whose authentication this
inference cannot see.

## `TokenGenerator`

```{code-block} php
use Kinetis\Auth\TokenGenerator;

$token = TokenGenerator::generate(); // 64 hex characters, 32 bytes of entropy
```

A thin wrapper over `random_bytes()`, hex-encoded so the result is safe to
place directly in an `Authorization` header with no escaping. Generation
only — issuing a token to a user (verifying a password, calling this,
storing the hash) is your own login endpoint's job. `kinetis/auth` stores
nothing: a token it never saw a hash of is a token
`UserProviderInterface` can never resolve.

Give the storing half an interface of your own, the counterpart to
`findByToken()`:

```{code-block} php
use Kinetis\Http\CurrentUserInterface;

interface IssuedTokens
{
    public function store(
        CurrentUserInterface $user,
        #[\SensitiveParameter] string $token,
    ): void;
}
```

An implementation writes `hash('sha256', $token)` against that user — the
same `token_hash` column `DatabaseUserProvider` reads back, and the same
digest it computes from the presented token. Storing the digest rather
than the token means a leaked table hands over nothing usable.

## Passwords

Kinetis has no password API. Use PHP's own —
`password_hash()`/`password_verify()`/`password_needs_rehash()` with
`PASSWORD_DEFAULT`, so hashing follows whatever PHP itself currently
recommends. Verifying credentials is your application's own boundary:
`UserProviderInterface` above resolves an already-issued token, not an
email and password.

Give the login endpoint one interface of your own to depend on:

```{code-block} php
use Kinetis\Http\CurrentUserInterface;

interface Credentials
{
    public function verify(
        string $email,
        #[\SensitiveParameter] string $password,
    ): ?CurrentUserInterface;
}
```

`#[\SensitiveParameter]` redacts that argument in a PHP stack trace: a
backtrace through this frame renders a placeholder rather than the
submitted password. It reaches nothing else — keeping the password out
of what your own application logs stays your code's job.

One `null` covers an unknown email and a wrong password alike; verify
against a fixed dummy hash when no user matches, so the time taken
doesn't disclose which of the two it was.

Registration hashes the submitted password once and stores the result:

```{code-block} php
$storedHash = password_hash($password, PASSWORD_DEFAULT);
```

`verify()` loads that stored hash for the given email and checks the
submitted password against it:

```{code-block} php
if (!password_verify($password, $storedHash)) {
    return null;
}

if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
    $storedHash = password_hash($password, PASSWORD_DEFAULT);
    // Store $storedHash against the same user.
}
```

```{warning}
**Hashing and verifying a password is CPU work, not a wait.** A worker
running `password_hash()`/`password_verify()` spends CPU for the whole
call, and there is nothing to yield to the way a database or HTTP wait
yields. Cross-request concurrency is already bounded by the runtime's
thread or process count ({doc}`runtime-adapters`), so keep the
algorithm's cost parameters inside the latency budget you accept for a
login request, and throttle the endpoint (below) rather than letting a
burst of attempts spend every worker's CPU on hashing.
```

## Preventing brute-force login attempts

`Kinetis\Security\AttemptThrottle` locks an identifier out after too many
failures, backed by `Psr\SimpleCache\CacheInterface`:

Install `kinetis/cache-redis` and configure `REDIS_HOST` or `REDIS_URL`
as shown in {doc}`redis` before using the example. The throttle needs
its atomic counter; a default `NullSimpleCache` cannot provide one.

```{code-block} sh
composer require kinetis/cache-redis
```

```{code-block} php
use Kinetis\Auth\TokenGenerator;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Kinetis\Security\AttemptThrottle;
use Psr\Http\Message\ResponseInterface;

// The request body — your own DTO, like any other #[Body] parameter.
final readonly class LoginRequest
{
    public function __construct(
        public string $email,
        #[\SensitiveParameter] public string $password,
    ) {}
}

final readonly class LoginController
{
    public function __construct(
        private AttemptThrottle $throttle,
        private Credentials $credentials,
        private IssuedTokens $tokens,
    ) {}

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data): ResponseInterface|array
    {
        if ($this->throttle->tooManyAttempts($data->email)) {
            return ErrorResponse::create(429, 'Too many attempts.', headers: [
                'Retry-After' => (string) $this->throttle->availableInSeconds($data->email),
            ]);
        }

        $user = $this->credentials->verify($data->email, $data->password);

        if ($user === null) {
            $this->throttle->recordFailure($data->email);
            return ErrorResponse::create(401, 'Invalid credentials.');
        }

        $this->throttle->clear($data->email);

        $token = TokenGenerator::generate();
        $this->tokens->store($user, $token);

        return ['token' => $token];
    }
}
```

`LoginRequest`, `Credentials` and `IssuedTokens` above are your
application's own — `kinetis/auth` defines none of the three. The DTO
takes whatever constraint attributes any other request body takes; see
{doc}`routing-validation`.

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

```{note}
**The cache must count atomically, and construction enforces it.**
`AttemptThrottle` requires a cache implementing
`Kinetis\SimpleCache\AtomicCounterInterface`. `RedisSimpleCache` does,
once Redis is configured (see {doc}`redis`); any other cache,
`NullSimpleCache` included, throws
`Exception\AttemptThrottleUnavailableException` at construction.

Without atomic counting, failures arriving together each read the same
count before any of them writes, so they register as one and the lockout
never arms. Parallel attempts are the normal shape of the attack this
class exists to stop.
```

## See also

- {doc}`middleware` — `CurrentUserInterface`, the global-vs-route
  middleware distinction, and `RequestScope` self-injection.
- {doc}`persistence` and {doc}`query-builder` — the database connection
  and `Query` behind a database-backed `UserProviderInterface`.
- {doc}`auth-jwt` — stateless JWT verification instead, with no user
  storage of your own to implement: the signed claims carry the
  identity, and only optional per-token revocation touches a store.
- {doc}`appendix-authentication` — the accepted `Authorization` header.
