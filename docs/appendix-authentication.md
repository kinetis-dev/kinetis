# Appendix: Authentication

The contracts behind {doc}`auth` and {doc}`auth-jwt`: the accepted
`Authorization` header, the JWT key and algorithm rules, every check a
token passes before it authenticates, JWK Set validation, and the
exceptions `kinetis/auth-jwt` throws. The guides cover setup; this page
is the reference they link to.

(auth-reference-authorization-header)=
## The `Authorization` header

`BearerAuthMiddleware` and `JwtAuthMiddleware` both read the header
through core's `Kinetis\Http\Auth\AuthorizationToken68Parser` with the
`Bearer` scheme, so both packages accept identical input:

- The request carries exactly one `Authorization` header line. Two lines
  are rejected, not comma-joined.
- The scheme is matched case-insensitively.
- One or more literal space characters separate the scheme from the
  credential. A tab or other whitespace does not.
- The credential consists only of the RFC 6750 `b64token` characters
  `A-Za-z0-9-._~+/`, with `=` allowed only as a trailing run.
- The header value has no leading or trailing whitespace and no trailing
  newline. The parser rejects them rather than trimming.
- The parser imposes no length limit and passes the credential on
  unchanged. `JwtAuthMiddleware` applies the JWT limits below.

A header breaking any rule gets the same `401` as an unknown token.

(auth-reference-algorithms)=
## JWT keys and algorithms

| Algorithm | Signing (`JwtSigningKey`) | Verifying (`JwtVerificationKeys`) | Minimum key |
| --- | --- | --- | --- |
| `HS256` | `hmacSecret()` | `hmacSecret()`, same secret | 32-byte secret |
| `HS384` | `hmacSecret()` | `hmacSecret()`, same secret | 48-byte secret |
| `HS512` | `hmacSecret()` | `hmacSecret()`, same secret | 64-byte secret |
| `RS256`, `RS384`, `RS512` | `rsaPrivateKey()`, PEM private key | `rsaPublicKey()`, PEM public key, or `jwks()` | 2048-bit RSA key |

`hmacSecret()` defaults to `HS256` and the RSA constructors to `RS256`;
the second argument names another algorithm from the same family. The
algorithms `firebase/php-jwt` implements beyond these six (`ES256`,
`ES256K`, `ES384`, `PS256`, `EdDSA`) are refused.

A single configured key pins the algorithm. `firebase/php-jwt` rejects a
token whose header `alg` differs from the key's own algorithm, so a token
cannot move an `RS256` verifier onto `HS256` by naming it, and `none` is
not an accepted `alg` at all.

(auth-reference-key-validation)=
### Validation at construction

`JwtSigningKey` and `JwtVerificationKeys` validate when they are built,
never on the first `issue()` or request, and throw
`Exception\JwtConfigurationException` for:

- an algorithm outside the six, or from the other family than the
  constructor — `RS256` handed to `hmacSecret()`, `HS256` handed to
  `rsaPublicKey()`;
- an HMAC secret shorter than the table's minimum, the digest length
  [RFC 7518 §3.2](https://www.rfc-editor.org/rfc/rfc7518#section-3.2)
  requires;
- RSA material that does not parse as an RSA key, is under 2048 bits, or
  is the wrong half: `rsaPrivateKey()` refuses a public key and
  `rsaPublicKey()` refuses a private key. A shared HMAC secret is not PEM
  and fails here too;
- a `kid` that breaks the rule below.

`JwtAuthenticator` and `JwtIssuer` validate their claim constraints the
same way: an empty issuer, an empty audience string, and an audience
array that is empty, not a list (sequential integer keys from `0`), or
holds anything but non-empty strings. A non-list array would encode as a
JSON object rather than the JWT array-of-strings form.

Validating a key parsed out of a JWK Set raises no PHP warning, so an
error handler that converts warnings to exceptions still sees this
package's own exception. Each message names the rule, never the secret or
key material, and chains no OpenSSL or `firebase/php-jwt` exception.

(auth-reference-kid)=
### Key ids

A `kid` is a non-blank string of at most 256 bytes that is valid UTF-8
(`JwtKeyValidator::isUsableKid()`). The rule applies to
`JwtSigningKey`'s `$kid`, to `PublishedRsaKey`, to every `kid` in a JWK
Set document, and to the `kid` in a token's header, so no side of a
rotation can name a key another side refuses. UTF-8 is required because a
kid travels as JSON in both directions.

A kid is matched as the exact string published: `"0"`, `"00"` and
`"zero"` select three different keys. `PublishedRsaKey` carries its kid
as a value rather than an array key because PHP turns the array key `'0'`
into the integer `0`.

(auth-reference-token-acceptance)=
## Token acceptance

`JwtAuthenticator::authenticate()` returns a `JwtUser` only when every
check passes, in this order. Any failure returns `null`, which
`JwtAuthMiddleware` answers with the generic `401`; nothing in the
response names the failed check.

1. The JOSE header is acceptable ({ref}`auth-reference-jose-header`).
2. A key is selected: the single configured key, or the key a `jwks()`
   set publishes under the token's `kid`.
3. `firebase/php-jwt` verifies the token: the header `alg` equals the
   key's algorithm, the signature verifies, the payload is a JSON object,
   and `iat`, `nbf` and `exp` are numbers when present. A token is
   rejected before `nbf`, before `iat` when it has no `nbf`, and from the
   second `exp` is reached. No clock-skew leeway applies:
   `Firebase\JWT\JWT::$leeway` defaults to `0` and this package does not
   set it, so an issuer whose clock runs ahead of the verifier produces
   tokens that fail until the verifier's clock reaches `iat`.
4. `sub` is a non-empty string. A JSON number is refused.
5. With `expectedIssuer`, `iss` is a string equal to it.
6. With `acceptedAudiences`, `aud` is either a string in the list or a
   non-empty array of non-empty strings, at least one of them in the
   list.
7. With a revocation store, `jti` is a non-empty string and the store
   does not report it revoked. A lookup that throws propagates instead of
   returning `null`.

(auth-reference-jose-header)=
### The JOSE header

The header arrives unsigned. `JwtAuthenticator` validates it through
`Kinetis\AuthJwt\JoseHeader` before any part of the token reaches
`JWT::decode()`, because `firebase/php-jwt` raises a `TypeError`, not a
decode failure, for an `alg` or `kid` written as a JSON array or object.
A token reaches verification only when:

- it is non-empty and at most 16,384 bytes;
- it has exactly three segments, the header segment at most 4,096
  characters;
- every segment is non-empty and is the one canonical unpadded base64url
  spelling of its bytes — no `=` padding, no `+` or `/`, no unused pad
  bits set;
- the header decodes to a JSON object of at most 2,048 bytes and nesting
  depth 8 that names no member twice at any depth, with names compared
  after unescaping;
- `alg` is a string among the six supported algorithms;
- the header has no `crit` or `b64` member;
- `kid`, when present, follows the kid rule whether or not the configured
  key reads it. A `jwks()` set requires one.

Duplicate members and non-canonical spellings are refused because a JWS
signs the encoded text of its header and payload: what the verifier acts
on has to be the one document the sender signed, not whichever value
`json_decode()` kept.

`crit` and `b64` are refused because each changes what verification
means and `firebase/php-jwt` reads neither:

- **`crit`** ([RFC 7515 §4.1.11](https://www.rfc-editor.org/rfc/rfc7515#section-4.1.11))
  lists header members a verifier must understand or reject the token
  over. This package implements no critical extension.
- **`b64`** ([RFC 7797](https://www.rfc-editor.org/rfc/rfc7797)) signs
  the payload unencoded. Verification here signs the compact encoded
  form.

Every other member, `typ` included, is ignored.

(auth-reference-jwks)=
## JWK Sets

### Publishing: `JwkSet::fromRsaPublicKeys()`

`JwkSet::fromRsaPublicKeys(array $keys, string $algorithm = 'RS256')`
returns an RFC 7517 `{"keys": [...]}` array. Each entry carries `kty`
`RSA`, the key's `kid`, `use` `sig`, `alg`, and the base64url `n` and
`e`. Every entry shares one `$algorithm`.

It validates everything before producing output and throws
`Exception\JwtConfigurationException` for an `$algorithm` outside
`RS256`/`RS384`/`RS512`, an empty or non-list `$keys`, an entry that is
not a `PublishedRsaKey`, two entries under one kid, and a key that is not
a PEM RSA public key of at least 2048 bits. A message about one key names
its kid, since on this side a kid is the application's own
configuration. A published document therefore never advertises a key
the verifier would refuse.

### Parsing: `JwtVerificationKeys::jwks()`

`jwks()` parses a JWK Set JSON string once, when it is called. It does
not fetch a URL or refresh; an application that needs a changed document
builds a new `JwtAuthenticator`.

It returns a set whose every key is usable, or throws
`Exception\JwtConfigurationException` — never a partial set. It refuses:

- a document that is not a JSON object, exceeds 65,536 bytes or nesting
  depth 8, or names a member twice at any depth;
- a missing `keys` member, or one that is not a non-empty array, or more
  than 32 keys;
- a key that is not a non-empty object;
- a `kty` other than `RSA` (`oct` keys are symmetric secrets and never
  belong in a published set);
- the RSA private members `d`, `p`, `q`, `dp`, `dq`, `qi`, `oth`, or a
  symmetric `k`;
- a missing `kid`, one outside the kid rule, or one an earlier key
  already claims;
- a missing `alg`, or one outside `RS256`/`RS384`/`RS512`;
- a `use` other than `sig`, or `key_ops` other than exactly `["verify"]`;
- an `n` or `e` that is not a string holding the canonical unpadded
  base64url spelling of an unsigned integer with no leading zero byte,
  within 2,048 characters, 1,024 bytes for `n` and 8 bytes for `e`;
- an even `e`, or `e` equal to 1;
- a key that does not compose into an RSA public key of at least 2048
  bits.

Members outside that list are ignored at the root and inside a key, as
[RFC 7517 §5](https://www.rfc-editor.org/rfc/rfc7517#section-5) requires,
so a provider's `x5c`, `x5t`, `x5t#S256` or `x5u` changes nothing. A
message names the rule and the zero-based index of the offending key,
never the document, a kid, or key material, and chains no OpenSSL or
`firebase/php-jwt` exception.

(auth-reference-exceptions)=
## Exceptions

All four live in `Kinetis\AuthJwt\Exception`. No message carries a
token, `jti`, subject, secret, or key material.

| Exception | Thrown by | When |
| --- | --- | --- |
| `JwtConfigurationException` | `JwtSigningKey`, `JwtVerificationKeys`, `JwtAuthenticator`, `JwtIssuer`, `JwkSet`, `PublishedRsaKey` | A key, algorithm, kid, JWK Set, or issuer/audience constraint breaks the rules above. |
| `JwtIssuerException` | `JwtIssuer::issue()` | An empty subject; `ttlSeconds` zero or negative; `ttlSeconds` large enough that `time() + ttlSeconds` overflows. |
| `RevocationUnavailableException` | `RevocationStore` | Construction over `NullSimpleCache`; `revoke()` with a zero or negative TTL; the cache's `set()` returning `false`; `revokeToken()` for a token with no non-empty `jti` or a non-integer `exp`. |
| `RefreshTokenUnavailableException` | `RefreshTokenStore` | Construction over `NullSimpleCache` or a cache without `Kinetis\SimpleCache\AtomicConsumeInterface`; an empty subject; `issue()` with a zero or negative TTL; the cache's `set()` returning `false` in `issue()` or `delete()` returning `false` in `revoke()`. |

`JwtAuthenticator::authenticate()` turns every rejected token into
`null` and throws nothing for one. A `JwtConfigurationException` or a
cache failure is a server-side fault and surfaces as one.

(auth-reference-sensitive-parameters)=
## Credentials in stack traces

A stack frame carries the arguments it was called with, so a backtrace
renders them. `kinetis/auth-jwt` marks key material, issued claims, the
request carrying a bearer token, the token string, a refresh token, and a
`jti` with `#[\SensitiveParameter]` where it passes them, so a trace
through its own frames shows a redacted placeholder. Frames owned by
`firebase/php-jwt`, PSR-7, or the application are outside its reach.
