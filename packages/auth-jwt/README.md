<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/auth-jwt</strong>
  <br>
  <strong>Stateless JWT authentication middleware for Kinetis</strong>
</p>

---

A PSR-15 route middleware that verifies an `Authorization: Bearer <token>`
header's signature and registers the decoded claims on the current
request as `CurrentUserInterface`, plus an issuer for signing tokens.
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

Need opaque Bearer-token validation against your own storage instead?
See [`kinetis/auth`](../auth).

## Installation

```sh
composer require kinetis/auth-jwt
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/auth-jwt.html](https://docs.kinetis.dev/auth-jwt.html).

## License

MIT — see [LICENSE](../../LICENSE).
