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
use Kinetis\AuthJwt\JwtVerificationKeys;
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
            JwtVerificationKeys::hmacSecret($config->required('JWT_SECRET')),
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

`expectedIssuer`/`acceptedAudiences` are what stop a token from a
*different* service — one that happens to share this app's signing
key — from authenticating here. `JwtIssuer` has to stamp matching values
for a token to pass this check at all; see "Issuing tokens" below. Leave
both `null` (the default) only for a single-service deployment where no
other JWT-issuing service ever shares this key.

## Configuring keys

Both sides take one immutable key value, and each value names its own
algorithm and key id:

| Value | Named constructors | Used by |
| --- | --- | --- |
| `JwtVerificationKeys` | `hmacSecret()`, `rsaPublicKey()`, `jwks()` | `JwtAuthMiddleware` |
| `JwtSigningKey` | `hmacSecret()`, `rsaPrivateKey()` | `JwtIssuer` |

There is no form that hands a private key to a verifier, and no
algorithm argument on the middleware or the issuer that a key could
contradict. Everything is checked where the value is written — see
"Cryptographic configuration is validated at construction" below.

## Supplying your own secret

Extend `JwtAuthMiddleware` with a constructor taking only `RequestScope`
and (optionally) your own `Config`, both class-typed, and pass your keys
to `parent::__construct()` — the pattern in the example above. Kinetis
builds a subclass shaped this way automatically, with no extra setup.

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
caught immediately: `JwtVerificationKeys`/`JwtSigningKey` validate the
material against the algorithm the moment they are built (see
"Cryptographic configuration is validated at construction" below), so a
misconfigured secret never reaches a real request.
```

## Issuing tokens: `JwtIssuer`

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\Config\Config;

$config = Config::fromEnvironment(); // or constructor-injected, wherever this runs
$issuer = new JwtIssuer(
    JwtSigningKey::hmacSecret($config->required('JWT_SECRET')),
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

`sub` (the subject), `iat`, `jti` (a random, unique token ID — see
"Revoking tokens" below), and `iss`/`aud` (when `issuer`/`audience` are
configured, per above) always win over an extra claim of the same name,
so a stray `['sub' => ...]` in `$claims` can't accidentally override the
real subject. Signing only — verifying a password and returning the
resulting token to the client is your own login endpoint's job.

```{note}
`ttlSeconds` must be `null` (no expiry claim at all) or a positive number
of seconds — zero or negative throws `Exception\JwtIssuerException`,
since either would produce a token that's already expired. A `ttlSeconds`
large enough to overflow the platform's integer range when added to the
current time throws the same way, rather than silently corrupting the
resulting `exp` claim.
```

### The subject is one canonical string

A subject is one non-empty string everywhere in `kinetis/auth-jwt`.
`issue()` takes `string|int` and converts an application's own integer
id to that string at the moment of issuance; `RefreshTokenStore::issue()`
takes and converts it identically, so the access token and the refresh
token a login endpoint hands back name the same subject. `JwtUser::id()`
returns that string and `redeem()` hands it back ready to reissue with,
so the id a request carries is the id a refresh reissues under — an
integer `42` in your own user table is the subject `'42'` on both token
kinds.

An empty subject throws (`Exception\JwtIssuerException` from
`JwtIssuer::issue()`, `Exception\RefreshTokenUnavailableException` from
`RefreshTokenStore`), and `JwtAuthMiddleware` answers a token whose
`sub` is anything but a non-empty string — absent, a JSON number, empty
— with the usual `401`: such a token names no user the application can
act on.

## Reading claims beyond `id()`

`CurrentUserInterface::id()` only ever guarantees the subject.
`JwtAuthMiddleware` registers a `JwtUser`, which exposes the rest of the
token's claims directly, and narrows `id()` to the canonical subject
string — inject `JwtUser` instead of `CurrentUserInterface` where you
need either:

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
`revoke()`/`revokeToken()` both check for this and throw
`Exception\RevocationUnavailableException` rather than silently treating
a failed write as a successful revocation; let it propagate rather than
catching and ignoring it — the whole point is that the caller must not
proceed as though the token is actually revoked. The same applies to
`RefreshTokenStore`'s `issue()`/`revoke()`, throwing
`Exception\RefreshTokenUnavailableException` — a failed `issue()` means
the token about to be returned was never stored, so it must be discarded
rather than handed to a client. Neither exception's message names the
token, `jti`, or subject involved.

`RefreshTokenStore::issue()`'s `$ttlSeconds` must be positive; zero or
negative throws the same way, rather than silently clamping to something
that would look like it worked but protect nothing.
`RevocationStore::revoke()`'s `$ttlSeconds` also accepts `null`, meaning
"revoke with no expiry at all" (see the note below) — zero or negative
is still rejected there too.

Configuring `revocationStore` also tightens what counts as a valid
token. `jti` is otherwise optional per the JWT standard, but with a
revocation store in place it is required, and must be a non-empty
string, before the revocation check runs. A token carrying no usable
`jti` is rejected outright with the usual 401, rather than
authenticating with the one check the store makes silently skipped.
Every `JwtIssuer`-issued token already satisfies this; it only matters
for a hand-built or third-party token.

```{code-block} php
use Kinetis\AuthJwt\RevocationStore;
use Kinetis\Config\Config;
use Psr\SimpleCache\CacheInterface;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope, Config $config, CacheInterface $cache)
    {
        parent::__construct(
            JwtVerificationKeys::hmacSecret($config->required('JWT_SECRET')),
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

Revocation here is always per token: `revokeToken()` logs out the one
token you hand it. "Log out everywhere" is application policy, not a
framework call — own a monotonic credential generation per user in your
identity domain, stamp its current value into both the access token
(a claim passed to `JwtIssuer::issue()`) and the refresh token (a claim
passed to `RefreshTokenStore::issue()`), bump it when the user logs out
everywhere, and reject a stale generation in your own middleware
alongside `JwtAuthMiddleware`. Only your identity domain can advance
that value monotonically and stamp it on the replacement credentials a
refresh mints.

## Refresh tokens

An access token's short expiry is what keeps a leaked one from being
useful for long — but that only works if a client can get a new one
without the user logging in again every hour. `Kinetis\AuthJwt\
RefreshTokenStore` issues a longer-lived, opaque, cache-backed token for
exactly that:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\RefreshTokenStore;
use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class LoginController
{
    public function __construct(
        private JwtIssuer $issuer,
        private RefreshTokenStore $refreshTokens,
        private Credentials $credentials,
    ) {}

    #[Post('/login')]
    public function attempt(#[Body] LoginRequest $data): ResponseInterface|array
    {
        $user = $this->credentials->verify($data->email, $data->password);

        if ($user === null) {
            return ErrorResponse::create(401, 'Invalid credentials.');
        }

        return [
            'accessToken' => $this->issuer->issue($user->id()),
            'refreshToken' => $this->refreshTokens->issue($user->id()),
        ];
    }
}
```

`Credentials` and `LoginRequest` there are the application's own, and so
is `RefreshRequest` below — this package defines none of them. Verifying
an email and password is your boundary; see {doc}`auth`'s "Passwords"
section for the `Credentials` interface, and {doc}`routing-validation`
for what a `#[Body]` DTO carries.

A refresh endpoint redeems the refresh token and issues both a fresh
access token and a fresh refresh token together:

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

A refresh token is single-use: `redeem()` reads it and deletes it in one
atomic operation the moment it's looked up, valid or not, so the same
refresh token can never be redeemed twice — even by two requests racing
each other, since the cache is required to implement
`Kinetis\SimpleCache\AtomicConsumeInterface` (`RedisSimpleCache` does;
construction throws otherwise, the same refusal `NullSimpleCache`
already gets). A client sees no difference between "never existed" and
"already used" — the identical `null` the endpoint above already handles
as "invalid or expired" — which is the point: neither is a distinction a
refresh endpoint should leak. `revoke()` invalidates one token directly
— a "log out this device" action — without needing to redeem it first:

```{code-block} php
$this->refreshTokens->revoke($data->refreshToken);
```

Revoking an access token does not stop a still-valid refresh token from
minting new ones, so a logout that ends a session revokes both the
access token (`RevocationStore::revokeToken()`) and the refresh token
(`revoke()` above).

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

### Credentials stay out of this package's frames

A stack frame carries the arguments it was called with, so any backtrace
renders them. Key material, issued claims, the request a bearer token
arrived in, a refresh token, and a revocation id are marked
`#[\SensitiveParameter]` where this package passes them, so a trace
through its own frames shows a redacted placeholder instead. Frames
owned by `firebase/php-jwt`, PSR-7, or your own application are outside
its reach.

## Algorithms

`HS256` by default — a shared secret, symmetric algorithm, passed as the
same string to `JwtSigningKey::hmacSecret()` and
`JwtVerificationKeys::hmacSecret()`. `HS384`/`HS512` work the same way —
a different algorithm name, same shared secret on both sides.

`RS256` (and `RS384`/`RS512`) use a key *pair* instead of a shared
secret — `rsaPrivateKey()` signs, `rsaPublicKey()` verifies, both taking
PEM-format strings:

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;

$issuer = new JwtIssuer(
    JwtSigningKey::rsaPrivateKey((string) file_get_contents('/path/to/private.pem')),
);
$token = $issuer->issue($user->id());

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope)
    {
        parent::__construct(
            JwtVerificationKeys::rsaPublicKey((string) file_get_contents('/path/to/public.pem')),
            $scope,
        );
    }
}
```

There is no way to hand the same key to both sides for `RS256`: the
verifying value takes the *public* half and rejects a private one.
Keeping the private key out of anything that only verifies tokens is the
entire point of choosing an asymmetric algorithm.

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

`JwtSigningKey` and `JwtVerificationKeys` validate their algorithm and
their key material the moment they are built — never on the first
`issue()` or request. The algorithm must be one of the six this page
documents (`HS256`/`HS384`/`HS512`/`RS256`/`RS384`/`RS512`), and it must
belong to the family the named constructor is for: an RSA algorithm
handed to `hmacSecret()`, an HMAC algorithm handed to `rsaPublicKey()`,
or an algorithm `firebase/php-jwt` itself supports but this package does
not (`ES256`, `EdDSA`, ...) all throw immediately.

For an HMAC algorithm, the secret must be at least as long, in bytes, as
the algorithm's own digest output — [RFC 7518 §3.2](https://www.rfc-editor.org/rfc/rfc7518#section-3.2)'s
stated minimum: 32 bytes for `HS256`, 48 for `HS384`, 64 for `HS512`. A
shorter secret is broken security, not merely discouraged, and is
rejected rather than accepted and quietly weak. For an RSA algorithm the
material must parse as a genuine RSA key of at least 2048 bits, and as
the half its own constructor is for: `rsaPrivateKey()` refuses a public
key and `rsaPublicKey()` refuses a private one.

Every key in a `jwks()` set passed those same rules before the value
could exist, key by key — see "Verifying against a published JWKS"
below.

One rule applies to a kid everywhere, and
`Kinetis\AuthJwt\JwtKeyValidator::isUsableKid()` is where it lives: a
non-blank string of at most 256 bytes that is valid UTF-8.
`JwtSigningKey`'s `$kid` (`null` to omit the header entirely), the kids
`JwkSet` publishes, the kids a JWKS document carries, and the `kid` a
token's own header names are all held to it, so no side of a rotation
can name a key another side would refuse to select. UTF-8 is part of it
because a kid travels as JSON both ways: `json_encode()` fails on
invalid bytes and `json_decode()` never produces them.

A key parsed out of a JWKS arrives as an `OpenSSLAsymmetricKey` object
rather than a PEM string — the shape `Firebase\JWT\JWK::parseKeySet()`
produces. Validation never calls `openssl_pkey_get_public()`/
`openssl_pkey_get_private()` on an already-parsed object, because those
emit a genuine PHP warning (not just a failed return) when the object's
own role doesn't match what's asked of it, and a
`set_error_handler()`-based warning-to-exception handler — a legitimate,
common application pattern — would otherwise let that escape in place of
this package's own failure.

Every failure throws `Exception\JwtConfigurationException`, naming the
rule without ever including the key or secret itself, and chaining no
OpenSSL or `firebase/php-jwt` cause behind itself.

```{note}
`Kinetis\AuthJwt\JwkSet::fromRsaPublicKeys()` (see "Publishing public
keys as a JWKS" below) validates the identical way: an empty or
non-list `$keys`, an entry that isn't a `PublishedRsaKey`, two entries
claiming one kid, an unparseable/non-RSA/undersized public key, or an
`$algorithm` outside `RS256`/`RS384`/`RS512` all throw the same
exception before any output is produced — a published JWKS can never
advertise a key or algorithm this package's own verifier would refuse.
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
string among the six algorithms this page documents, and — when the
configured keys select by kid — a `kid` accepted by
`JwtKeyValidator::isUsableKid()`. A `kid` that is present is held to that
rule whether or not the configured keys would have read it.

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

Swapping a signing key outright invalidates every token issued under the
old one at once. A `kid` (key ID) lets both the old and new key verify
at the same time, during an overlap window. The signing key stamps its
own `kid`; the verifier holds a JWK Set naming both:

```{code-block} php
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;

// Sign new tokens under the new key, labeled with its own kid.
$issuer = new JwtIssuer(JwtSigningKey::rsaPrivateKey(
    (string) file_get_contents('/path/to/2026-private.pem'),
    kid: '2026-key',
));
```

```{code-block} php
use Kinetis\AuthJwt\JwkSet;
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\AuthJwt\PublishedRsaKey;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope)
    {
        $jwks = JwkSet::fromRsaPublicKeys([
            new PublishedRsaKey('2025-key', (string) file_get_contents('/path/to/2025-public.pem')),
            new PublishedRsaKey('2026-key', (string) file_get_contents('/path/to/2026-public.pem')),
        ]);

        parent::__construct(
            JwtVerificationKeys::jwks((string) json_encode($jwks, JSON_THROW_ON_ERROR)),
            $scope,
        );
    }
}
```

A token's own `kid` header (written by whichever signing key signed it)
selects which key verifies it, so tokens signed under either key keep
working throughout the overlap. Retire an old key once its longest-lived
outstanding token has expired: sign everything new under the new `kid`,
wait out the old key's own token lifetime, then drop it from the set.

A JWK Set is the only multi-key form, so the same list of keys a
deployment publishes at `.well-known/jwks.json` is the one it verifies
against — the two cannot drift. Building the value once at boot and
binding it on `AppScope` keeps the OpenSSL work off every request; see
"Verifying against a published JWKS" below.

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
            new PublishedRsaKey('2025-key', (string) file_get_contents('/path/to/2025-public.pem')),
            new PublishedRsaKey('2026-key', (string) file_get_contents('/path/to/2026-public.pem')),
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
publish exactly the kids `JwtVerificationKeys::jwks()` reads back.

### Verifying against a published JWKS

`Kinetis\AuthJwt\JwtVerificationKeys::jwks()` is the other direction:
raw JWKS JSON, whatever a `.well-known/jwks.json` URL answers with,
parsed into the keys `JwtAuthMiddleware` verifies against. It is the
only multi-key form.

Build it once, at boot, and bind it — parsing runs OpenSSL over every
key in the document, which is not work to repeat per request:

```{code-block} php
// bootstrap.php
use Kinetis\AuthJwt\JwtVerificationKeys;

$app->instance(JwtVerificationKeys::class, JwtVerificationKeys::jwks(
    (string) file_get_contents('/path/to/jwks.json'),
));
```

```{code-block} php
use Kinetis\AuthJwt\JwtAuthMiddleware;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\Container\RequestScope;

final class AppJwtAuthMiddleware extends JwtAuthMiddleware
{
    public function __construct(RequestScope $scope, JwtVerificationKeys $keys)
    {
        parent::__construct($keys, $scope);
    }
}
```

`JwtVerificationKeys` belongs on `AppScope`: it needs no `RequestScope`
of its own, and the middleware above is still resolved through the
request's own scope, which reaches `AppScope` for it — so the warning in
"Supplying your own secret" doesn't apply to registering the keys
themselves.

A kid is matched as the exact string the document published, so `"0"`,
`"00"` and `"zero"` are three separately selectable keys. A token
carrying no `kid`, or one no key in the set claims, gets the usual
`401`.

`jwks()` either returns a value whose every key is usable or throws
`Exception\JwtConfigurationException` — never a partial set with the
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
