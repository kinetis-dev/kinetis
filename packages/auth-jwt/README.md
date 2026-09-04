<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/auth-jwt</strong>
  <br>
  <strong>Stateless JWT authentication middleware for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/auth-jwt"><img src="https://img.shields.io/packagist/v/kinetis/auth-jwt?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/auth-jwt"><img src="https://img.shields.io/packagist/dt/kinetis/auth-jwt" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/auth-jwt"><img src="https://img.shields.io/packagist/php-v/kinetis/auth-jwt" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/auth-jwt"><img src="https://img.shields.io/packagist/l/kinetis/auth-jwt" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

A PSR-15 route middleware that verifies an `Authorization: Bearer <token>`
header's signature and registers the decoded claims on the current
request as both `CurrentUserInterface` and the concrete `JwtUser` (the
identical object either way — inject `JwtUser` directly when you need a
claim beyond the subject, `roles` or `jti` for instance), plus an issuer
for signing tokens.
Verification via [`firebase/php-jwt`](https://github.com/firebase/php-jwt)
(HS256/RS256, optional per-token revocation) — no database or cache
lookup; the signed claims are the entire authentication decision.

```php
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

Rotating signing keys: `JwkSet` publishes `PublishedRsaKey` values as an
RFC 7517 JWK Set for a `.well-known/jwks.json` route, and
`ParsedJwkSet::fromJson()` parses raw JWKS JSON back into the key set
`JwtAuthMiddleware` verifies against — kids carried and matched as the
exact strings the document published, every key validated before the set
exists.

Need opaque Bearer-token validation against your own storage instead?
See [`kinetis/auth`](https://github.com/kinetis-dev/auth).

## Installation

```sh
composer require kinetis/auth-jwt
```

Requires PHP 8.4+ and [`kinetis/framework`](https://github.com/kinetis-dev/framework). Full documentation:
[kinetis.dev/docs/auth-jwt.html](https://kinetis.dev/docs/auth-jwt.html).

## License

MIT — see [LICENSE](LICENSE).
