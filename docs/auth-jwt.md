# JWT Authentication

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/auth-jwt
```
````

`kinetis/auth-jwt` authenticates requests with signed JSON Web Tokens.
`JwtIssuer` signs a token when a user logs in, `JwtAuthMiddleware` guards
a route by verifying the `Authorization: Bearer <token>` header through
the registered `JwtAuthenticator`, and the controller receives the
verified identity. The signature is the authentication decision: no user
lookup runs per request. Verification uses
[`firebase/php-jwt`](https://github.com/googleapis/php-jwt).

## Guard a route

Generate a secret of at least 32 bytes and keep it in the environment,
not in the repository:

```{code-block} sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

```{code-block} text
:caption: .env

JWT_SECRET=<the 64 characters printed above>
```

Register the authenticator, and the issuer your login endpoint uses, in
`bootstrap.php`:

```{code-block} php
:caption: bootstrap.php

<?php

declare(strict_types=1);

use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;

return static function (AppScope $app, Config $config): void {
    $secret = $config->required('JWT_SECRET');

    $app->instance(JwtAuthenticator::class, new JwtAuthenticator(
        JwtVerificationKeys::hmacSecret($secret),
        expectedIssuer: 'my-app',
        acceptedAudiences: ['my-app-api'],
    ));

    $app->instance(JwtIssuer::class, new JwtIssuer(
        JwtSigningKey::hmacSecret($secret),
        issuer: 'my-app',
        audience: 'my-app-api',
    ));
};
```

Guard a controller or a single method with the middleware:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(JwtAuthMiddleware::class)]
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

A request with a missing, malformed, expired, badly signed or otherwise
rejected token gets a `401` before the controller runs, with a
`WWW-Authenticate: Bearer` header. The body never says which check
failed:

```{code-block} json
{"error": "Unauthenticated."}
```

Both registered objects are immutable and hold no request data, so one
instance serves every request a worker handles. Each validates its
configuration when constructed: a missing `JWT_SECRET` throws
`MissingConfigException`, and a secret shorter than 32 bytes throws
`Exception\JwtConfigurationException`, at boot rather than on a request.

```{important}
`expectedIssuer` and `acceptedAudiences` reject a token that another
service signed with the same key for a different purpose. Set them
whenever any other service holds this key, and give `JwtIssuer` matching
`issuer` and `audience` values, or every token fails the check. Leave
both `null` only when this application is the key's sole holder.
```

`JwtAuthMiddleware` needs no registration: the attribute resolves it from
the request's own scope. Use it as route middleware only, never global
middleware, so a login endpoint, a health check or `/openapi.json` stays
reachable without a token. Don't bind it on `AppScope` either: a factory
there cannot reach the request's scope and throws
`DisconnectedRequestScopeException` (see {doc}`container`'s "Resolving
`RequestScope` itself, from the wrong scope").

To place the middleware in a middleware group, declare an empty subclass
carrying the attribute. Configuration always belongs on the registered
`JwtAuthenticator`, never on a subclass:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\Http\Attributes\AsMiddlewareGroup;

#[AsMiddlewareGroup('broadcasting')]
final class BroadcastJwtAuthMiddleware extends JwtAuthMiddleware {}
```

## Choose a key

| Deployment | Algorithm | Issuer signs with | Verifier checks with |
| --- | --- | --- | --- |
| One application issues and verifies | `HS256` | `JwtSigningKey::hmacSecret($secret)` | `JwtVerificationKeys::hmacSecret($secret)`, the same secret |
| Other services verify, or tokens leave your infrastructure | `RS256` | `JwtSigningKey::rsaPrivateKey($privatePem)` | `JwtVerificationKeys::rsaPublicKey($publicPem)` |
| Keys rotate without invalidating tokens | `RS256` | `JwtSigningKey::rsaPrivateKey($privatePem, kid: '2026-key')` | `JwtVerificationKeys::jwks($jwksJson)` ([Rotate keys](#rotate-keys)) |

```{warning}
An HMAC secret signs as well as verifies: every service holding it can
mint tokens that every other holder accepts. Give a service that only
verifies tokens an RSA *public* key instead, and keep the private key on
the issuing service alone. The forms cannot be crossed:
`rsaPublicKey()` takes a PEM public key and throws
`JwtConfigurationException` at construction for a shared secret or a
private key, and no constructor hands a verifier a private key.
```

Generate a 2048-bit key pair:

```{code-block} sh
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out jwt-private.pem
openssl pkey -in jwt-private.pem -pubout -out jwt-public.pem
```

The issuing service reads the private key; every verifying service reads
only the public key:

```{code-block} php
:caption: bootstrap.php on the issuing service

$app->instance(JwtIssuer::class, new JwtIssuer(
    JwtSigningKey::rsaPrivateKey((string) file_get_contents($config->required('JWT_PRIVATE_KEY_FILE'))),
    issuer: 'my-app',
    audience: 'my-app-api',
));
```

```{code-block} php
:caption: bootstrap.php on each verifying service

$app->instance(JwtAuthenticator::class, new JwtAuthenticator(
    JwtVerificationKeys::rsaPublicKey((string) file_get_contents($config->required('JWT_PUBLIC_KEY_FILE'))),
    expectedIssuer: 'my-app',
    acceptedAudiences: ['my-app-api'],
));
```

An unreadable file becomes an empty string, which construction rejects.
`HS384`/`HS512` and `RS384`/`RS512` are selected by the constructors'
second argument, and `HS384`/`HS512` need 48- and 64-byte secrets. The
verifying key pins the algorithm, so a token naming a different `alg` in
its header is rejected. {ref}`auth-reference-algorithms` has the complete
algorithm and key rules.

## Issue tokens

```{code-block} php
$token = $issuer->issue($user->id());                                      // expires in 1 hour
$token = $issuer->issue($user->id(), ['roles' => ['editor', 'reviewer']]); // extra claims
$token = $issuer->issue($user->id(), ttlSeconds: 900);                     // expires in 15 minutes
```

A login endpoint verifies credentials and returns the token:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class LoginController
{
    public function __construct(
        private JwtIssuer $issuer,
        private Credentials $credentials,
    ) {}

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data): ResponseInterface|array
    {
        $user = $this->credentials->verify($data->email, $data->password);

        if ($user === null) {
            return ErrorResponse::create(401, 'Invalid credentials.');
        }

        return ['accessToken' => $this->issuer->issue($user->id())];
    }
}
```

`Credentials` and `LoginRequest` are the application's own: {doc}`auth`'s
"Passwords" section defines `Credentials`, and its "Preventing
brute-force login attempts" section defines `LoginRequest` and throttles
this endpoint.

Every token carries claims `JwtIssuer` writes itself, and these win over
an entry of the same name in `$claims`:

- `sub`, the subject as a string. An integer id `42` becomes `'42'`, the
  value `JwtUser::id()` returns.
- `iat`, the issue time, and `jti`, a random token id used by
  revocation.
- `exp`, unless `ttlSeconds` is `null`.
- `iss` and `aud`, when the issuer is configured with them. `audience`
  also takes a list, `audience: ['my-app-api', 'my-app-admin']`, and a
  verifier accepts the token when any one of them is in its
  `acceptedAudiences`.

`$claims` is application data with no fixed schema; `roles` above is this
example's own name.

```{warning}
Claims are signed, not encrypted. Anyone holding a token can decode its
payload, so never put a password, a secret, or data the client must not
see into `$claims`.
```

```{warning}
Keep access tokens short-lived. A stolen token works until its `exp`, and
nothing stops it sooner unless a revocation store is configured.
`ttlSeconds: null` issues a token with no `exp` at all, valid until it is
revoked; issue one only with [revocation](#revoke-tokens) in place. To
spare users frequent logins, add [refresh tokens](#refresh-tokens)
rather than raising the lifetime.
```

`ttlSeconds` must be a positive number of seconds or `null`, and the
subject must be non-empty; otherwise `issue()` throws
`Exception\JwtIssuerException`.

## Read the authenticated user

`CurrentUserInterface::id()` returns the subject. Inject `JwtUser` instead
to read other claims:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

#[Middleware(JwtAuthMiddleware::class)]
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
            'roles' => $this->user->claim('roles'),
        ];
    }
}
```

`claim()` returns `null` for an absent claim, and `claims()` returns every
claim as a `stdClass`. The middleware registers one verified object as
both `CurrentUserInterface` and `JwtUser`. {doc}`authorization`'s "Reading
claims or roles without a query" checks a claim from inside a Policy.

```{warning}
Take identity only from the `JwtUser` or `CurrentUserInterface` that
`JwtAuthMiddleware` registers, and only on routes it guards. Never decode
a token yourself — splitting it and base64-decoding the payload, or
reading its header — to decide who the caller is: those bytes are
unverified until `JwtAuthenticator` accepts the token. On a route without
the middleware, `CurrentUserInterface` cannot be resolved, and `JwtUser`
autowires as an empty object whose every `claim()` is `null` and whose
`id()` throws.
```

A token that arrives some other way than the `Authorization` header goes
through `JwtAuthenticator::authenticate($token)`, which applies the same
checks and returns a `JwtUser` or `null`.

## Revoke tokens

A signed token stays valid until it expires. `RevocationStore` adds a
denylist keyed by each token's `jti`, so a logout can end one token
early. It needs a cache that stores data: install `kinetis/cache-redis`
and configure Redis ({doc}`redis`). Construction over `NullSimpleCache`,
the binding when no Redis is configured, throws.

`AppScope::boot()` binds the cache after `bootstrap.php` runs, so resolve
it inside a binding, which runs on first use:

```{code-block} php
:caption: bootstrap.php

use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Psr\SimpleCache\CacheInterface;

return static function (AppScope $app, Config $config): void {
    $keys = JwtVerificationKeys::hmacSecret($config->required('JWT_SECRET'));

    $app->bind(JwtAuthenticator::class, static fn (AppScope $scope): JwtAuthenticator => new JwtAuthenticator(
        $keys,
        revocationStore: new RevocationStore($scope->get(CacheInterface::class)),
        expectedIssuer: 'my-app',
        acceptedAudiences: ['my-app-api'],
    ));

    // Register JwtIssuer as in "Guard a route".
};
```

A logout endpoint revokes the token it was called with:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtUser;
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;

#[Middleware(JwtAuthMiddleware::class)]
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

- With a store configured, every request costs one cache read, and a
  token must carry a non-empty `jti`. `JwtIssuer` always writes one; a
  hand-built or third-party token without it gets the `401`.
- A cache read that fails propagates as an exception and the request
  fails; it never counts as "not revoked".
- `revokeToken()` keeps the denylist entry until the token's own `exp`,
  skips the write for a token that has already expired, and revokes a
  token with no `exp` permanently. `revoke($jti, $ttlSeconds)` revokes by
  `jti` alone.
- `revokeToken()` throws `Exception\RevocationUnavailableException` when
  the cache reports a failed write, or the token lacks a usable `jti` or
  integer `exp`. Let it propagate: a logout that reports success while
  the token still works is exactly what revocation exists to prevent.

Revocation is per token. "Log out everywhere" is application policy:
keep a credential generation per user in your own store, put its current
value into the access token and the refresh token as a claim, increment
it on "log out everywhere", and reject a stale value in your own
middleware after `JwtAuthMiddleware`.

## Refresh tokens

A short access-token lifetime needs a way to renew without logging in
again. `RefreshTokenStore` issues an opaque, single-use refresh token
kept in the cache. It requires a cache implementing
`Kinetis\SimpleCache\AtomicConsumeInterface` — `RedisSimpleCache` does —
and throws at construction otherwise.

Login returns both tokens, with `RefreshTokenStore` injected next to the
issuer:

```{code-block} php
return [
    'accessToken' => $this->issuer->issue($user->id()),
    'refreshToken' => $this->refreshTokens->issue($user->id()),
];
```

A refresh endpoint redeems the refresh token and issues a new pair:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class RefreshController
{
    public function __construct(
        private JwtIssuer $issuer,
        private RefreshTokenStore $refreshTokens,
    ) {}

    #[Post('/token/refresh')]
    public function refresh(#[Body] RefreshRequest $data): ResponseInterface|array
    {
        $redeemed = $this->refreshTokens->redeem($data->refreshToken);

        if ($redeemed === null) {
            return ErrorResponse::create(401, 'Invalid or expired refresh token.');
        }

        return [
            'accessToken' => $this->issuer->issue($redeemed['subject'], $redeemed['claims']),
            'refreshToken' => $this->refreshTokens->issue($redeemed['subject'], $redeemed['claims']),
        ];
    }
}
```

`RefreshRequest` is the application's own `#[Body]` DTO (see
{doc}`routing-validation`).

- `redeem()` reads and deletes the token in one atomic operation, valid
  or not, so a refresh token works once even when two requests race.
  Unknown, used and expired tokens all return the same `null`.
- `issue()` stores `$claims` with the token and `redeem()` returns them,
  so the endpoint above copies the login-time claims into every new
  token. Load claims that can change, such as roles, from your own store
  instead.
- `revoke($refreshToken)` invalidates one refresh token, for "log out
  this device". Revoking an access token leaves its refresh token
  working, so a logout revokes both.
- A refresh token lasts 14 days unless `issue()` is given another
  `ttlSeconds`.
- `issue()` throws `Exception\RefreshTokenUnavailableException` when the
  cache reports a failed write. That token was never stored: return an
  error, not the token.

## Rotate keys

A `kid` (key id) on the signing key lets an old and a new RSA key verify
side by side. Verifiers hold a JWK Set of public keys, and each token's
`kid` header selects the key that verifies it:

1. Give the signing key a `kid` from the start. A `jwks()` verifier
   rejects tokens without one, so moving from an unnamed key to a key set
   invalidates every outstanding token.
2. Generate the new key pair, and deploy every verifying service with a
   set holding both the current and the new public key.
3. Switch the issuing service to the new private key and its `kid`.
4. Wait until the longest-lived token signed with the old key has
   expired. A token issued with `ttlSeconds: null` never expires and
   stops working in the next step.
5. Remove the old public key from the set and deploy the verifiers again.

```{code-block} php
:caption: bootstrap.php on the issuing service

$app->instance(JwtIssuer::class, new JwtIssuer(
    JwtSigningKey::rsaPrivateKey((string) file_get_contents('/run/secrets/jwt-2026-private.pem'), kid: '2026-key'),
    issuer: 'my-app',
    audience: 'my-app-api',
));
```

```{code-block} php
:caption: bootstrap.php on each verifying service

use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\PublishedRsaKey;

$jwks = JwkSet::fromRsaPublicKeys([
    new PublishedRsaKey('2025-key', (string) file_get_contents('/run/secrets/jwt-2025-public.pem')),
    new PublishedRsaKey('2026-key', (string) file_get_contents('/run/secrets/jwt-2026-public.pem')),
]);

$app->instance(JwtAuthenticator::class, new JwtAuthenticator(
    JwtVerificationKeys::jwks(json_encode($jwks, JSON_THROW_ON_ERROR)),
    expectedIssuer: 'my-app',
    acceptedAudiences: ['my-app-api'],
));
```

`jwks()` parses the document once, when the authenticator is built; it
never fetches or refreshes a URL. A changed set takes effect when workers
restart and build a new authenticator. A document from an identity
provider works as long as every key is RSA and carries a `kid` and an
`alg` of `RS256`, `RS384` or `RS512`; a key without `alg` is refused.
{ref}`auth-reference-jwks` lists every rule.

An HMAC secret has no multi-key form. Replacing `JWT_SECRET` rejects every
outstanding access token at once. Refresh tokens are stored rather than
signed, so they keep working and their clients get tokens signed with
the new secret on the next refresh.

### Publish the public keys

Clients and API gateways that verify your tokens read the key set from a
`.well-known/jwks.json` route you declare:

```{code-block} php
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\PublishedRsaKey;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Hidden;

final readonly class JwksController
{
    #[Get('/.well-known/jwks.json')]
    #[Hidden]
    public function jwks(): array
    {
        return JwkSet::fromRsaPublicKeys([
            new PublishedRsaKey('2025-key', (string) file_get_contents('/run/secrets/jwt-2025-public.pem')),
            new PublishedRsaKey('2026-key', (string) file_get_contents('/run/secrets/jwt-2026-public.pem')),
        ]);
    }
}
```

`JwkSet` accepts RSA public keys only and validates each one before
producing output. An HMAC secret is never published.

## Failures

| Situation | Result |
| --- | --- |
| Missing, malformed, expired, not-yet-valid, badly signed, wrong-issuer, wrong-audience or revoked token | `401` with `WWW-Authenticate: Bearer`; the controller does not run |
| Invalid secret, key, algorithm, `kid`, issuer or audience configuration | `Exception\JwtConfigurationException` where the object is constructed |
| The revocation cache fails during a request | The exception propagates and the request fails |
| The cache reports a failed revocation or refresh-token write | `Exception\RevocationUnavailableException` or `Exception\RefreshTokenUnavailableException` |

`exp`, `nbf` and `iat` are checked with no clock-skew allowance, so keep
issuer and verifier clocks synchronized: a token whose `iat` is ahead of
the verifier's clock is rejected. {ref}`auth-reference-token-acceptance`
lists every check in order, {ref}`auth-reference-exceptions` every
exception, and {ref}`auth-reference-sensitive-parameters` how tokens and
keys stay out of stack traces.

## See also

- {doc}`auth` — opaque Bearer tokens checked against your own storage,
  password handling, and login throttling.
- {doc}`authorization` — checking a `JwtUser`'s claims from a Policy.
- {doc}`middleware` — route and global middleware, groups, and
  `CurrentUserInterface`.
- {doc}`redis` — the cache revocation and refresh tokens require.
- {doc}`appendix-authentication` — algorithms, token acceptance, JWK Set
  validation, and exceptions.
