# JWT Authentication

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/auth-jwt
```
````

Stateless JWT authentication: a PSR-15 route middleware that verifies an
`Authorization: Bearer <token>` header's signature and registers the
decoded claims on the current request as `CurrentUserInterface`, plus an
issuer for signing tokens. Verification via
[`firebase/php-jwt`](https://github.com/googleapis/php-jwt) — no
database or cache lookup, and no equivalent of {doc}`auth`'s
`UserProviderInterface`: the signed claims are the entire authentication
decision.

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Config\Config;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope, Config $config)
    {
        parent::__construct($config->required('JWT_SECRET'), $scope);
    }
}

#[Middleware(AppJwtAuthMiddleware::class)]
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

## Supplying your own secret

Extend `JwtAuthMiddleware` with a constructor taking only `RequestScope`
and (optionally) your own `Config`, both class-typed, and pass your
secret to `parent::__construct()` — the pattern in the example above.
Kinetis builds a subclass shaped this way automatically, with no extra
setup.

```{warning}
Don't register `JwtAuthMiddleware::class` itself on `AppScope` with a
factory that also resolves `RequestScope` — `AppScope` will autowire a
brand-new, disconnected `RequestScope` instead of reaching the real
per-request one, since it falls back to autowiring any real class it has
no explicit binding for. The subclass above avoids this entirely: it's
resolved through the request's own `RequestScope`, which already has
itself registered.
```

```{warning}
Use `Config::required('JWT_SECRET')`, not `Config::string('JWT_SECRET',
'')` with an empty-string default — a missing secret should fail
clearly and immediately, not surface as an unrelated error later.
```

## Issuing tokens: `JwtIssuer`

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Config\Config;

$config = Config::fromEnvironment(); // or constructor-injected, wherever this runs
$issuer = new JwtIssuer($config->required('JWT_SECRET'));

$token = $issuer->issue($user->id());                                  // 1 hour expiry
$token = $issuer->issue($user->id(), ['role' => 'admin']);             // extra claims
$token = $issuer->issue($user->id(), ttlSeconds: 3600 * 24 * 30);       // 30 days
$token = $issuer->issue($user->id(), ttlSeconds: null);                // never expires
```

`sub` (the subject — always your passed-in id, coerced to a string),
`iat`, and `jti` (a random, unique token ID — see "Revoking tokens" below)
always win over an extra claim of the same name, so a stray
`['sub' => ...]` in `$claims` can't accidentally override the real
subject. Signing only — verifying a password and returning the resulting
token to the client is your own login endpoint's job.

## Reading claims beyond `id()`

`CurrentUserInterface::id()` only ever guarantees the subject.
`JwtAuthMiddleware` registers a `JwtUser`, which exposes the rest of the
token's claims directly — inject `JwtUser` instead of `CurrentUserInterface`
where you need one:

```{code-block} php
use Kinetis\AuthJwt\JwtUser;
use Kinetis\Http\Attributes\Get;

final readonly class OrderController
{
    public function __construct(
        private JwtUser $user,
    ) {}

    #[Get('/orders')]
    public function index(): array
    {
        return [
            'userId' => $this->user->id(),
            'role' => $this->user->claim('role'),
        ];
    }
}
```

## Revoking tokens: `RevocationStore`

A verified signature alone can't express "this specific token shouldn't
work anymore" — that's the one thing a stateless JWT structurally can't
do on its own. `RevocationStore` closes that gap with a cache-backed
denylist, keyed by the `jti` claim every `JwtIssuer`-issued token already
carries:

```{code-block} php
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Config\Config;
use Psr\SimpleCache\CacheInterface;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope, Config $config, CacheInterface $cache)
    {
        parent::__construct(
            $config->required('JWT_SECRET'),
            $scope,
            revocationStore: new RevocationStore($cache),
        );
    }
}
```

A logout endpoint revokes the *current* token by injecting `JwtUser`
(not `CurrentUserInterface` — you need `claim('jti')`, which only
`JwtUser` exposes) and handing it straight to `revokeToken()`:

```{code-block} php
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Http\Attributes\Post;

final readonly class LogoutController
{
    public function __construct(
        private JwtUser $user,
        private RevocationStore $revocationStore,
    ) {}

    #[Post('/logout')]
    public function invoke(): array
    {
        $this->revocationStore->revokeToken($this->user);

        return ['loggedOut' => true];
    }
}
```

```{note}
The denylist entry's TTL is derived from the token's own `exp` claim, not
a fixed duration — once the token would have expired naturally anyway,
there's nothing left to revoke, so the entry is dropped too. A token
issued with `ttlSeconds: null` (no expiry) has nothing to bound the entry
by; revoking one is effectively a no-op. Give a token you intend to be
able to revoke a real expiry.
```

`revocationStore` is optional and `null` by default — every example
earlier on this page works with zero revocation checking, at zero extra
cache cost.

## Logging out everywhere

`revokeToken()` only logs out the one token you hand it — "log out this
session." To invalidate every token a user currently holds, across every
device they're logged in on, use `revokeAllForUser()` instead:

```{code-block} php
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\CurrentUserInterface;

final readonly class LogoutEverywhereController
{
    public function __construct(
        private CurrentUserInterface $user,
        private RevocationStore $revocationStore,
    ) {}

    #[Post('/logout-everywhere')]
    public function invoke(): array
    {
        $this->revocationStore->revokeAllForUser($this->user->id(), ttlSeconds: 3600);

        return ['loggedOut' => true];
    }
}
```

Any token issued before this call stops working immediately; a fresh
login right afterward — including the user's own, if they log back in on
this device — still works normally, since its own `iat` is after the
cutoff.

`ttlSeconds` here isn't a token's own remaining lifetime the way it is for
`revokeToken()` — there's no single token to derive it from, since this
covers every token the user might be holding. Pass however long your app's
longest-lived token can stay valid (matching whatever `ttlSeconds` you
pass to `JwtIssuer::issue()`); anything shorter risks the cutoff itself
expiring while an old token is technically still unexpired.

## Failure, expiry, and revocation

An expired, badly signed, malformed, subject-less, or revoked token all
produce the same `401`, with a `WWW-Authenticate: Bearer` header, before
your controller runs — matching {doc}`auth`'s `BearerAuthMiddleware`
failure shape exactly:

```{code-block} json
{"error": "Unauthenticated."}
```

An empty or malformed key on your own side is not caught here — that's a
misconfiguration, not a client-supplied bad token, and surfaces as a real
error rather than a silent `401`.

## Algorithms

`HS256` by default — a shared secret, symmetric algorithm, passed as the
same string to both `JwtIssuer` and `JwtAuthMiddleware`. `HS384`/`HS512`
work the same way — just a different algorithm name, same shared secret
on both sides.

`RS256` (and `RS384`/`RS512`) use a key *pair* instead of a shared secret
— `JwtIssuer` takes the **private** key, `JwtAuthMiddleware` takes the
**public** one, both as PEM-format strings:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;

$issuer = new JwtIssuer(file_get_contents('/path/to/private.pem'), algorithm: 'RS256');
$token = $issuer->issue($user->id());

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope)
    {
        parent::__construct(
            file_get_contents('/path/to/public.pem'),
            $scope,
            algorithm: 'RS256',
        );
    }
}
```

```{warning}
Don't pass the same key to both sides for `RS256` — that only works for
`HS*`. For an asymmetric algorithm, the middleware only ever needs the
*public* key; keeping the private key out of anything that only verifies
tokens is the entire point of choosing an asymmetric algorithm in the
first place.
```

```{warning}
Neither `JwtIssuer` nor `JwtAuthMiddleware` sets or checks `iss`
(issuer) or `aud` (audience) claims. If two separate services share the
same `HS256` secret, each will accept a token the other one issued —
there's nothing here to stop it. Pass your own `iss`/`aud` through
`issue()`'s `$claims` argument and check them yourself (via
`JwtUser::claim()`) if that matters for your setup, or give each service
its own secret/key pair instead.
```

## See also

- {doc}`auth` — opaque Bearer tokens against your own storage instead, if
  you don't want claims embedded directly in the token, or want every
  request to hit your own storage regardless.
- {doc}`middleware` — `CurrentUserInterface`, the global-vs-route
  middleware distinction, and `RequestScope` self-injection.
