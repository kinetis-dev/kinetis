# JWT Authentication

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/auth-jwt
```
````

Stateless JWT authentication: a PSR-15 route middleware that verifies an
`Authorization: Bearer <token>` header's signature and registers the
decoded claims on the current request as both `CurrentUserInterface` and
the concrete `JwtUser` (the identical object either way — see "Reading
claims beyond `id()`" below), plus an issuer for signing tokens.
Verification via
[`firebase/php-jwt`](https://github.com/googleapis/php-jwt) — no
database or cache lookup, and no equivalent of {doc}`auth`'s
`UserProviderInterface`: the signed claims are the entire authentication
decision. The `Authorization` header itself is parsed by
`Kinetis\Http\Auth\BearerCredentialParser` (core), the same class
{doc}`auth`'s `BearerAuthMiddleware` uses — see that page's "The
accepted `Authorization` header" section for the exact wire grammar.

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
        parent::__construct(
            $config->required('JWT_SECRET'),
            $scope,
            expectedIssuer: 'my-app',
            acceptedAudiences: ['my-app-api'],
        );
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

`expectedIssuer`/`acceptedAudiences` are what stop a token from a *different* service — one that happens to share this app's signing key — from authenticating here. `JwtIssuer` has to stamp matching values for a token to pass this check at all; see "Issuing tokens" below. Leave both `null` (the default) only for a genuinely single-service deployment where no other JWT-issuing service ever shares this key.

## Supplying your own secret

Extend `JwtAuthMiddleware` with a constructor taking only `RequestScope`
and (optionally) your own `Config`, both class-typed, and pass your
secret to `parent::__construct()` — the pattern in the example above.
Kinetis builds a subclass shaped this way automatically, with no extra
setup.

```{warning}
Don't register `JwtAuthMiddleware::class` itself on `AppScope` with a
factory that also resolves `RequestScope` — `AppScope` throws
`DisconnectedRequestScopeException` rather than reaching the real
per-request one (see {doc}`container`'s "Resolving `RequestScope` itself,
from the wrong scope"). The subclass above avoids this entirely: it's
resolved through the request's own `RequestScope`, which already has
itself registered.
```

```{warning}
Prefer `Config::required('JWT_SECRET')` over `Config::string('JWT_SECRET',
'')` with an empty-string default — a missing secret should read as a
deliberate "this must be configured," not a default value that happens
to also fail validation. Either way, an empty or too-short secret is
caught immediately: `JwtAuthMiddleware`/`JwtIssuer` validate `$key`
against `$algorithm` at construction (see "Cryptographic configuration
is validated at construction" below), so a misconfigured secret never
reaches a real request.
```

## Issuing tokens: `JwtIssuer`

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Config\Config;

$config = Config::fromEnvironment(); // or constructor-injected, wherever this runs
$issuer = new JwtIssuer(
    $config->required('JWT_SECRET'),
    issuer: 'my-app',
    audience: 'my-app-api',
);

$token = $issuer->issue($user->id());                                    // 1 hour expiry
$token = $issuer->issue($user->id(), ['roles' => ['editor', 'reviewer']]); // extra claims
$token = $issuer->issue($user->id(), ttlSeconds: 3600 * 24 * 30);         // 30 days
$token = $issuer->issue($user->id(), ttlSeconds: null);                  // never expires
```

`issuer`/`audience` here must match `AppJwtAuthMiddleware`'s own
`expectedIssuer`/`acceptedAudiences` above exactly, or every token this
issues will fail that check. `audience` also accepts a list of strings
(`audience: ['my-app-api', 'my-app-admin']`) for a token meant to be
accepted by more than one service — a verifier's own
`acceptedAudiences` matches on any one of them, not all. Both this list
and `acceptedAudiences` itself must be a genuine list — sequential
integer keys starting at `0` — not an associative or sparse array;
`json_encode()` serializes anything else as a JSON object rather than
the JWT standard's array-of-strings form, so construction rejects it
outright rather than issuing a token no verifier could ever match.

`$claims` is plain array data — whatever shape your application needs,
not a fixed schema. `roles` above is a name this example chose, not one
`kinetis/auth-jwt` defines or expects.

`sub` (the subject — always your passed-in id, coerced to a string),
`iat`, `jti` (a random, unique token ID — see "Revoking tokens" below),
and `iss`/`aud` (when `issuer`/`audience` are configured, per above)
always win over an extra claim of the same name, so a stray
`['sub' => ...]` in `$claims` can't accidentally override the real
subject. Signing only — verifying a password and returning the resulting
token to the client is your own login endpoint's job.

```{note}
`ttlSeconds` must be `null` (no expiry claim at all) or a positive number
of seconds — zero or negative throws `Exception\JwtIssuerException`,
since either would produce a token that's already expired. A `ttlSeconds`
large enough to overflow the platform's integer range when added to the
current time throws the same way, rather than silently corrupting the
resulting `exp` claim.
```

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
            'roles' => $this->user->claim('roles'),
        ];
    }
}
```

`claims(): stdClass` exposes every claim at once, for reading something
shaped like an array (`$user->claims()->roles`) or for passing `$user`
itself to a check that needs more than one claim — see
{doc}`authorization`'s "Reading claims or roles without a query" for a
worked example checking a `roles` claim from inside an authorization
Policy, with no query needed since the token already carried it.

## Revoking tokens: `RevocationStore`

A verified signature alone can't express "this specific token shouldn't
work anymore" — that's the one thing a stateless JWT structurally can't
do on its own. `RevocationStore` closes that gap with a cache-backed
denylist, keyed by the `jti` claim every `JwtIssuer`-issued token already
carries.

The denylist needs a real cache. Configure Redis (`REDIS_URL` or
`REDIS_HOST` — see {doc}`persistence`) so the `CacheInterface` binding is
`RedisSimpleCache`, or pass any other real PSR-16 implementation directly.
`RevocationStore` refuses to construct over `NullSimpleCache` — the
default binding when no Redis is configured — since a denylist that never
stores anything would let every revoked token stay valid until it expires
on its own.

A real, *reachable* cache can still fail a single write — a network
blip, a full Redis instance — and PSR-16 lets a conforming implementation
report that by returning `false` rather than throwing.
`revoke()`/`revokeToken()`/`revokeAllForUser()` all check for this and
throw `Exception\RevocationUnavailableException` rather than silently
treating a failed write as a successful revocation; let it propagate
rather than catching and ignoring it — the whole point is that the
caller must not proceed as though the token is actually revoked. The
same applies to `RefreshTokenStore`'s `issue()`/`revoke()`/
`revokeAllForUser()`, throwing `Exception\RefreshTokenUnavailableException`
— a failed `issue()` means the token about to be returned was never
stored, so it must be discarded rather than handed to a client. Neither
exception's message names the token, `jti`, or subject involved.

Every `$ttlSeconds` a revocation method accepts as a *duration* —
`revokeAllForUser()` on both stores, and `RefreshTokenStore::issue()` —
must be positive; zero or negative throws the same way, rather than
silently clamping to something that would look like it worked but
protect nothing. `RevocationStore::revoke()` is the one exception: its
`$ttlSeconds` also accepts `null`, meaning "revoke with no expiry at
all" (see the note below) — zero or negative is still rejected.

Configuring `revocationStore` also tightens what counts as a valid
token. `iat` and `jti` are otherwise optional per the JWT standard, but
with a revocation store in place both are required — `iat` a plain
integer, `jti` a non-empty string — before either revocation check
runs. A token missing or malformed on just one of them is rejected
outright with the usual 401, not silently exempted from whichever check
that claim would have driven. Every `JwtIssuer`-issued token already
satisfies this; it only matters for a hand-built or third-party token.

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
there's nothing left to revoke, so `revokeToken()` skips the write
entirely rather than attempting one. A token issued with `ttlSeconds:
null` (no expiry at all) has no such natural point, so it's revoked
*indefinitely* instead — a genuine, permanent denylist entry, not a TTL
standing in for "forever." `revokeToken()` throws
`Exception\RevocationUnavailableException` if the token carries no
usable `jti`, or an `exp` present but not a plain integer — a logout
that silently did nothing would be worse than one that fails loudly.
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

The user id this is keyed by is a `string|int`, and its type counts: the
integer `42` and the string `'42'` are two different users, on both
stores' `revokeAllForUser()`. `CurrentUserInterface::id()` hands back
whichever type your own user identity carries, so passing it straight
through — as the controller above does — keeps every call on one
identity. Casting it at some call sites and not others is what splits a
user in two, leaving a "log out everywhere" that revokes an identity
nobody's tokens were issued to.

## Refresh tokens

An access token's short expiry is what keeps a leaked one from being
useful for long — but that only works if a client can get a new one
without the user logging in again every hour. `Kinetis\AuthJwt\
RefreshTokenStore` issues a longer-lived, opaque, cache-backed token for
exactly that:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\Http\Attributes\Post;

final readonly class LoginController
{
    public function __construct(
        private JwtIssuer $issuer,
        private RefreshTokenStore $refreshTokens,
    ) {}

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data): array
    {
        // verify $data->email/$data->password against your own storage

        return [
            'accessToken' => $this->issuer->issue($user->id()),
            'refreshToken' => $this->refreshTokens->issue($user->id()),
        ];
    }
}
```

A refresh endpoint redeems the refresh token and issues both a fresh
access token and a fresh refresh token together:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;

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

A refresh token is single-use: `redeem()` reads it and deletes it in one
atomic operation the moment it's looked up, valid or not, so the same
refresh token can never be redeemed twice — even by two requests racing
each other, since the cache is required to implement
`Kinetis\SimpleCache\AtomicConsumeInterface` (both `RedisSimpleCache`
and `ClusteredRedisSimpleCache` do; construction throws otherwise, the
same refusal `NullSimpleCache` already gets). `redeem()` also returns
`null` — the identical "invalid or expired" outcome the endpoint above
already handles — for a token that's still on record but predates a
`revokeAllForUser()` cutoff for its own subject (see "Logging out
everywhere" below): a client sees no difference between "never existed,"
"already used," or "revoked," which is the point — none of those are a
distinction a refresh endpoint should leak. `revoke()` invalidates one
token directly — a "log out this device" action — without needing to
redeem it first:

```{code-block} php
$this->refreshTokens->revoke($data->refreshToken);
```

`RefreshTokenStore` has its own `revokeAllForUser()`, independent of
`RevocationStore`'s: revoking every access token a user holds doesn't
stop a still-valid refresh token from minting new ones, so a complete
"log out everywhere" calls both together:

```{code-block} php
#[Post('/logout-everywhere')]
public function invoke(): array
{
    $this->revocationStore->revokeAllForUser($this->user->id(), ttlSeconds: 3600);
    $this->refreshTokens->revokeAllForUser($this->user->id(), ttlSeconds: 3600 * 24 * 14);

    return ['loggedOut' => true];
}
```

Each `ttlSeconds` covers that store's own longest-lived outstanding
token — an access token's is typically much shorter than a refresh
token's, so the two calls above commonly pass different values.

Defaults to a 14-day expiry (`issue(..., ttlSeconds: 1_209_600)`),
adjustable per call. `RefreshTokenStore` requires a real cache the same
way `RevocationStore` does.

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

```{note}
`JwtAuthMiddleware`'s `expectedIssuer`/`acceptedAudiences` (configured on
the primary example above) are what actually stop two services sharing
one `HS256` secret from accepting each other's tokens — checked as part
of authentication itself, before a user is ever registered on the
request. There's no need, and no supported way, to repeat this check
per-controller against `JwtUser::claim('iss')`/`claim('aud')` — a route
this middleware protects has already had it enforced. Leaving both
unset is a real, supported choice for a single-service deployment with
no other JWT-issuing service sharing its key; it isn't a gap left for
application code to close.
```

### Cryptographic configuration is validated at construction

`JwtIssuer` and `JwtAuthMiddleware` both validate `$algorithm` and `$key`
the moment they're constructed — never on the first `issue()`/request.
`$algorithm` must be one of the six this page documents (`HS256`/
`HS384`/`HS512`/`RS256`/`RS384`/`RS512`); anything else, including an
algorithm `firebase/php-jwt` itself supports (`ES256`, `EdDSA`, ...),
throws immediately.

For an HMAC algorithm, `$key` must be at least as long, in bytes, as the
algorithm's own digest output — [RFC 7518 §3.2](https://www.rfc-editor.org/rfc/rfc7518#section-3.2)'s
stated minimum: 32 bytes for `HS256`, 48 for `HS384`, 64 for `HS512`. A
shorter secret is broken security, not merely discouraged, and is
rejected rather than accepted and quietly weak. For an RSA algorithm,
`$key` must parse as a genuine RSA key of at least 2048 bits — `JwtIssuer`
requires the **private** half, `JwtAuthMiddleware` the **public** half;
handing either class the wrong half of the pair is rejected the same way.

A `kid => Key` map (see "Rotating keys" below) is validated per entry,
the same way: the map itself must be non-empty, every value a real
`Firebase\JWT\Key`, and every `Key`'s own `getAlgorithm()`/key material
held to the identical rules above.
`JwtAuthMiddleware`'s own top-level `$algorithm` constructor argument has
no effect at all once `$key` selects by kid — each `Key` already carries
its own algorithm, so the top-level value is never read (and never
validated) in that case; leave it at its default rather than trying to
make it agree with anything in the map. A `ParsedJwkSet` (see "Verifying
against a published JWKS" below) already carries the same guarantee:
every key in it passed these identical rules before the set could exist,
so construction re-checks nothing there.

Every side of a rotation applies one rule to a kid, and
`Kinetis\AuthJwt\JwtKeyValidator::isUsableKid()` is where it lives: a
non-blank string of at most 256 bytes that is valid UTF-8. `JwtIssuer`'s
`$kid` (`null` to omit the header entirely), the kids in a `kid => Key`
map, the kids `JwkSet` publishes, and the `kid` a token's own header
carries are all held to it, so no side of a rotation can name a key
another side would refuse to select. UTF-8 is part of it because a kid
travels as JSON both ways: `json_encode()` fails on invalid bytes and
`json_decode()` never produces them.

`Key`'s own key material may be a raw PEM string, or already an
`OpenSSLAsymmetricKey`/`OpenSSLCertificate` object — the shape
`Firebase\JWT\JWK::parseKeySet()` itself produces for every RSA key in a
parsed JWKS. Validation never calls `openssl_pkey_get_public()`/
`openssl_pkey_get_private()` directly on an already-parsed object,
specifically because doing so can emit a genuine PHP warning (not just a
failed return) when the object's own role doesn't match what's being
asked of it — a warning a `set_error_handler()`-based warning-to-exception
handler (a legitimate, common application pattern) would otherwise let
escape as an unrelated exception in place of this package's own named
one.

Every failure throws a named exception (`Exception\JwtIssuerException`/
`Exception\JwtAuthMiddlewareException`) describing what's wrong without
ever including the key or secret itself.

```{note}
`Kinetis\AuthJwt\JwkSet::fromRsaPublicKeys()` (see "Publishing public
keys as a JWKS" below) validates the identical way: an empty list, a
`$keys` that isn't a list at all, an entry that isn't a
`PublishedRsaKey`, two entries claiming one kid, an
unparseable/non-RSA/undersized public key, or an `$algorithm` outside
`RS256`/`RS384`/`RS512` all throw
`Exception\JwkSetException` before any output is produced — a published
JWKS can never advertise a key or algorithm this package's own verifier
would refuse.
```

## What a token must be before verification starts

A JOSE header arrives unsigned, shaped however whoever sent the token
chose to shape it. `JwtAuthMiddleware` reads and validates it — through
`Kinetis\AuthJwt\JoseHeader` — before any of the token reaches
`JWT::decode()`, because `firebase/php-jwt` types `alg` and `kid` only
where it happens to use them: a header naming either as a JSON array or
object raises a `TypeError` from inside the library rather than a decode
failure, and a `TypeError` isn't something a middleware can answer with
a `401` without also swallowing bugs it should surface.

To reach verification at all, a token has to be exactly three base64url
segments within a fixed length limit, each in the single unpadded
spelling its own bytes encode to, with a header segment
decoding to a JSON object that names no member twice, an `alg` that is a
string among the six algorithms this page documents, and — when `$key`
selects by kid — a `kid` accepted by `JwtKeyValidator::isUsableKid()`. A
`kid` that is present is held to that rule whether or not the configured
`$key` would have read it.

Two rules there are about ambiguity rather than shape. A header naming
`alg` twice is refused rather than resolved to whichever value
`json_decode()` kept last, and a segment spelled with unused base64 pad
bits set is refused rather than decoded like its canonical spelling: a
JWS signs the encoded text of its own header and payload, so what a
verifier acts on has to be the one document the sender sent.

Two protected-header members are refused outright, because both change
what verification means and `firebase/php-jwt` reads neither:

- **`crit`** ([RFC 7515 §4.1.11](https://www.rfc-editor.org/rfc/rfc7515#section-4.1.11))
  names header members a verifier must understand or else reject the
  token over. This package implements no critical extension, so the only
  conformant answer to any `crit` is to refuse.
- **`b64`** ([RFC 7797](https://www.rfc-editor.org/rfc/rfc7797)) makes
  the payload sign unencoded, changing the signing input. Verification
  here signs the compact encoded form, so a token declaring `b64` — with
  or without the `crit` RFC 7797 requires alongside it — is asking for
  semantics this boundary doesn't implement.

Every other header member is left unread: `typ`, and anything else an
issuer stamps, carries no weight in this package's verification
decision.

Everything outside all of that is the same generic `401` as an expired
or badly-signed token — the handler never runs, and neither
`CurrentUserInterface` nor `JwtUser` is registered on the request.
Nothing in the response says which rule the token broke.

## Rotating keys

Swapping a signing key outright invalidates every token issued under
the old one at once. A `kid` (key ID) lets both the old and new key
verify at the same time, during an overlap window:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;

// Sign new tokens under the new key, labeled with its own kid.
$issuer = new JwtIssuer(
    file_get_contents('/path/to/2026-private.pem'),
    algorithm: 'RS256',
    kid: '2026-key',
);
```

```{code-block} php
use Firebase\JWT\Key;
use Kinetis\AuthJwt\JwtAuthMiddleware;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope)
    {
        parent::__construct([
            '2025-key' => new Key(file_get_contents('/path/to/2025-public.pem'), 'RS256'),
            '2026-key' => new Key(file_get_contents('/path/to/2026-public.pem'), 'RS256'),
        ], $scope);
    }
}
```

`$key` accepts a `kid => Key` map in place of a single string — a
token's own `kid` header (written by whichever `JwtIssuer` signed it)
selects which entry verifies it, so tokens signed under either key keep
working throughout the overlap. Retire an old key once its longest-lived
outstanding token has expired: sign everything new under the new `kid`,
wait out the old key's own token lifetime, then drop it from the map.

### Publishing public keys as a JWKS

For `RS256`/`RS384`/`RS512`, `Kinetis\AuthJwt\JwkSet` builds a standard
JWK Set from one or more RSA public keys — the format clients and
API gateways expect at a `.well-known/jwks.json`-style URL:

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
            new PublishedRsaKey('2025-key', file_get_contents('/path/to/2025-public.pem')),
            new PublishedRsaKey('2026-key', file_get_contents('/path/to/2026-public.pem')),
        ]);
    }
}
```

A plain array return, JSON-encoded automatically like any other route —
nothing registers this endpoint for you, the same way nothing registers
a login or refresh endpoint either. An `HS256` key is symmetric and is
never published; this only applies to the asymmetric algorithms.

Each key is a `PublishedRsaKey`, holding its kid as a string property
rather than as a position in a `kid => PEM` map, because a PHP array key
cannot hold every kid this package supports: `'0'` used as one is the
integer `0`, which is a different name from the one the document is
meant to publish. Carrying the kid as a value is what lets `JwkSet`
publish exactly the kids `ParsedJwkSet` reads back.

### Verifying against a published JWKS

`Kinetis\AuthJwt\ParsedJwkSet::fromJson()` is the other direction: raw
JWKS JSON, whatever a `.well-known/jwks.json` URL answers with, parsed
into the key set `JwtAuthMiddleware` verifies against. It's the
supported way to configure multi-key verification from a published
document; the `kid => Key` map stays for a deployment holding PEM files
directly, and cannot express a kid PHP reads as a number.

Parse once, at boot, and hand the result to the middleware —
`fromJson()` runs OpenSSL over every key in the document, which is not
work to repeat per request:

```{code-block} php
// bootstrap.php
use Kinetis\AuthJwt\ParsedJwkSet;

$app->instance(ParsedJwkSet::class, ParsedJwkSet::fromJson(
    (string) file_get_contents('/path/to/jwks.json'),
));
```

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\ParsedJwkSet;
use Kinetis\Container\RequestScope;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope, ParsedJwkSet $keys)
    {
        parent::__construct($keys, $scope);
    }
}
```

`ParsedJwkSet` belongs on `AppScope`: it needs no `RequestScope` of its
own, and the middleware above is still resolved through the request's
own scope, which reaches `AppScope` for it — so the warning in
"Supplying your own secret" doesn't apply to registering the key set
itself.

A kid is matched as the exact string the document published, so `"0"`,
`"00"` and `"zero"` are three separately selectable keys.

`fromJson()` either returns a set whose every key is usable or throws
`Exception\ParsedJwkSetException` — never a partial set with the
failing keys quietly dropped. It refuses a document that isn't a JSON
object or that names a member twice at any depth; a `keys` member that
isn't a non-empty JSON array; a key that isn't an object; a `kty` other
than `RSA`; RSA private members (`d`, `p`, `q`, `dp`, `dq`, `qi`,
`oth`) or a symmetric `k`, which a published document must never carry;
a `kid` outside the rule above, or one a previous key already claimed;
an `alg` outside `RS256`/`RS384`/`RS512`; a `use` other than `sig`;
`key_ops` other than exactly `["verify"]`; an `n` or `e` that isn't the
canonical unpadded base64url spelling of an unsigned integer of the
expected size; and a key that doesn't compose into an RSA public key
meeting the 2048-bit minimum stated above. Sizes are bounded as well —
64 KiB of raw JSON, 32 keys, and per-field limits — since a JWKS fetched
over the network is untrusted input.

Members outside that list are ignored, at the root and inside a key, as
[RFC 7517 §5](https://www.rfc-editor.org/rfc/rfc7517#section-5) requires
of a reader that doesn't understand them — so a provider's `x5c`,
`x5t`, `x5t#S256` or `x5u` alongside a key, or its own metadata beside
`keys`, changes nothing about the keys the set yields.

An exception from here names the rule and the position of the offending
key within the document, never the document, a kid, or key material, and
chains no OpenSSL or `firebase/php-jwt` cause behind itself.

## See also

- {doc}`auth` — opaque Bearer tokens against your own storage instead, if
  you don't want claims embedded directly in the token, or want every
  request to hit your own storage regardless.
- {doc}`middleware` — `CurrentUserInterface`, the global-vs-route
  middleware distinction, and `RequestScope` self-injection.
- {doc}`auth`'s "Preventing brute-force login attempts" section —
  `AttemptThrottle`, usable ahead of a login endpoint issuing a JWT the
  same way it's used ahead of one issuing an opaque token.
- {doc}`authorization` — checking a `JwtUser`'s claims from inside a
  Policy, with no query needed since the token already carried them.
