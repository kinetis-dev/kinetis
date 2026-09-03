<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/authorization</strong>
  <br>
  <strong>Ability-based authorization for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/authorization"><img src="https://img.shields.io/packagist/v/kinetis/authorization?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/authorization"><img src="https://img.shields.io/packagist/dt/kinetis/authorization" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/authorization"><img src="https://img.shields.io/packagist/php-v/kinetis/authorization" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/authorization"><img src="https://img.shields.io/packagist/l/kinetis/authorization" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.

Kinetis is deliberately unopinionated about how your application
organizes authorization checks — there's no required Policy convention,
no ability-name registry, and nothing here inspects an object's class to
decide which code answers a check. `Gate` is a small, generic wrapper:
hand it any callable and it normalizes the result into an allow/deny
decision.

```php
use Kinetis\Authorization\Gate;

final readonly class PostController
{
    public function __construct(
        private Gate $gate,
        private PostPolicy $postPolicy,
    ) {}

    public function update(int $id, CurrentUserInterface $user): array
    {
        $post = $this->posts->find($id);

        $this->gate->authorize($user, $this->postPolicy->update(...), $post);

        // ...
    }
}
```

`$this->postPolicy->update(...)` is PHP's own first-class callable
syntax — `PostPolicy` is a plain, constructor-injected class with plain
methods, resolved and called exactly like any other service. `Gate` never
sees `PostPolicy` exist as a concept.

## Provides

Installing this package auto-registers, via `extra.kinetis`:

- **A global middleware** translating a thrown `AuthorizationException`
  into a `403` response, so a denied `Gate::authorize()` call works from
  any route with nothing else to wire.

`Gate` itself needs no explicit binding — it has no constructor
dependencies, so plain autowiring resolves it wherever a controller
constructor-injects it.

Nothing else. There's no attribute to discover, no registry, and no
"Policy" concept this package enforces — `PostPolicy` above is only a
name a developer chose.

## The three methods

- `authorize($user, $check, ...$arguments): void` — throws
  `AuthorizationException` on denial, letting the registered middleware
  turn it into a `403`. Use it when a denial should hard-stop the
  request.
- `allows($user, $check, ...$arguments): bool` — never throws. Use it to
  branch, or to shape a response value (`'canEdit' => $gate->allows(...)`).
- `denies($user, $check, ...$arguments): bool` — the exact inverse of
  `allows()`, for guard-clause style (`if ($gate->denies(...)) { ... }`).

`$check` is any `callable(CurrentUserInterface, mixed...): bool|AuthorizationResponse`
— a first-class callable reference to a method, a plain closure, or a
`Gate::allows()`-independent function. Returning a plain `bool` covers the
ordinary case; returning `AuthorizationResponse::deny('a specific reason')`
lets a denial carry a message more useful than the generic default.

`$check` may also be typed against a concrete `CurrentUserInterface`
implementation richer than the interface itself — [`kinetis/auth-jwt`](https://github.com/kinetis-dev/auth-jwt)'s
`JwtUser`, say, whose `claim()`/`claims()` already carry everything the
token decoded, with no query needed. `Gate`'s methods are generic over the
user type (`@template TUser of CurrentUserInterface`) precisely so this
type-checks correctly. See
[kinetis.dev/docs/authorization.html](https://kinetis.dev/docs/authorization.html#reading-claims-or-roles-without-a-query).

## Installation

```sh
composer require kinetis/authorization
```

Requires PHP 8.4 or later and [`kinetis/framework`](https://github.com/kinetis-dev/framework).
Documentation:
[kinetis.dev/docs/authorization.html](https://kinetis.dev/docs/authorization.html)

## License

MIT — see [LICENSE](LICENSE).
