<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/auth</strong>
  <br>
  <strong>Bearer/opaque-token authentication middleware for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/auth"><img src="https://img.shields.io/packagist/v/kinetis/auth?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/auth"><img src="https://img.shields.io/packagist/dt/kinetis/auth" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/auth"><img src="https://img.shields.io/packagist/php-v/kinetis/auth" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/auth"><img src="https://img.shields.io/packagist/l/kinetis/auth" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

A PSR-15 route middleware that validates an `Authorization: Bearer <token>`
header and registers the resolved user on the current request as
`CurrentUserInterface`, plus a token generator. Storage is entirely up to
you — the package has no opinion on where tokens live.

```php
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

Need stateless JWT verification instead of your own token storage? See
[`kinetis/auth-jwt`](../auth-jwt).

## Installation

```sh
composer require kinetis/auth
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/auth.html](https://docs.kinetis.dev/auth.html).

## License

MIT — see [LICENSE](../../LICENSE).
