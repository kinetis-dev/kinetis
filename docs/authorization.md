# Authorization

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/authorization
```
````

Kinetis is deliberately unopinionated about how an application organizes
authorization checks. There's no required Policy convention, no
ability-name registry, and nothing here inspects an object's runtime
class to decide which code answers a check — that kind of implicit,
type-based dispatch is exactly what this package avoids. `Gate` is a
small, generic wrapper: hand it any callable and it normalizes the
result into an allow/deny decision.

```{code-block} php
use Kinetis\Authorization\Gate;
use Kinetis\Http\Attributes\Patch;
use Kinetis\Http\CurrentUserInterface;

final readonly class PostController
{
    public function __construct(
        private Gate $gate,
        private PostPolicy $postPolicy,
        private PostRepository $posts,
    ) {}

    #[Patch('/posts/{id}')]
    public function update(int $id, CurrentUserInterface $user, #[Body] UpdatePostRequest $body): array
    {
        $post = $this->posts->find($id);

        $this->gate->authorize($user, $this->postPolicy->update(...), $post);

        $updated = $this->posts->update($post->id, $body->title, $body->content);

        return ['id' => $updated->id];
    }
}
```

`$this->postPolicy->update(...)` is PHP's own first-class callable
syntax — `PostPolicy` is a plain, constructor-injected class with plain
methods, resolved and called exactly like any other service. `Gate`
never resolves anything itself and never sees `PostPolicy` exist as a
concept; "Policy" is a naming convention a developer chooses, not
something this package enforces or discovers.

```{code-block} php
final readonly class PostPolicy
{
    public function update(CurrentUserInterface $user, Post $post): bool|AuthorizationResponse
    {
        if ($post->locked) {
            return AuthorizationResponse::deny('This post is locked and cannot be edited.');
        }

        return $post->authorId === $user->id();
    }
}
```

`CurrentUserInterface` is core's own minimal identity contract — `Gate`
works identically regardless of which package resolved it: `kinetis/auth`,
`kinetis/auth-jwt`, a session-based check via `kinetis/session`, or
anything an application writes itself. Authorization is orthogonal to
authentication mechanism; nothing here depends on either auth package.

## The three methods

```{code-block} php
$this->gate->authorize($user, $check, ...$arguments): void;  // throws on denial
$this->gate->allows($user, $check, ...$arguments): bool;     // reports the denial instead
$this->gate->denies($user, $check, ...$arguments): bool;     // the exact inverse of allows()
```

**`authorize()`** throws `Kinetis\Authorization\Exception\AuthorizationException`
on denial, which reaches the client as a `403` on its own (see below).
Use it when a denial should hard-stop the request — the common case.

**`allows()`** returns a plain `bool`: it reports the decision instead of
acting on it, so a denial is `false` rather than an exception. Use it when
execution should continue either way and the result itself is what you
need — shaping a response value, or branching positively:

```{code-block} php
return [
    'id' => $post->id,
    'canEdit' => $this->gate->allows($user, $this->postPolicy->update(...), $post),
];
```

**`denies()`** is the exact inverse of `allows()`, offered purely for
guard-clause readability — "if denied, bail" reads more directly than "if
not allowed, bail" — and matters most when a denial shouldn't produce the
generic `403` body, say a redirect on a web-flavored route instead:

```{code-block} php
if ($this->gate->denies($user, $this->postPolicy->update(...), $post)) {
    return new Response(302, ['Location' => '/posts/' . $post->id]);
}
```

## `AuthorizationResponse`

A check's callable may return a plain `bool`, or an `AuthorizationResponse`
when a denial should carry a specific reason instead of the generic
fallback message:

```{code-block} php
use Kinetis\Authorization\AuthorizationResponse;

AuthorizationResponse::allow();
AuthorizationResponse::deny('This post is locked and cannot be edited.');
AuthorizationResponse::deny(); // "This action is unauthorized."
```

`Gate` normalizes `true`/`false` into the generic allow/deny form
internally, so the common case stays a one-line boolean check and only a
check that needs a specific reason has to build one.

## How a denial reaches the client

The controller never sees an `AuthorizationResponse` and never returns
one — the flow is exception propagation, not a return value:

1. `authorize()` calls the given callable, gets back `bool|AuthorizationResponse`.
2. On denial, it **throws** `AuthorizationException` right there, inside
   `Gate` — several stack frames below the controller.
3. That throw unwinds everything above it: `Gate::authorize()`, the
   controller method (its own `return` is never reached), `Dispatcher::dispatch()`,
   any route middleware — all the way out to the **global** middleware
   pipeline, since nothing in between catches it.
4. `AuthorizationException` implements core's
   `Kinetis\Http\Exception\HttpStatusExceptionInterface`, declaring
   `403`. `Kinetis\Http\Middleware\ExceptionHandlerMiddleware` —
   included unconditionally, whatever else is registered — reads that
   status off the exception and returns
   `ErrorResponse::create(403, $e->getMessage())`, the exception's own
   message as the body's error text.

This package registers nothing to make that work: the interface is the
seam core provides for exactly this, so there's no middleware to install,
order, or forget. Only a *denied* check becomes this exception — anything
the check itself throws propagates unchanged, and
`ExceptionHandlerMiddleware` treats it by the same rule it applies to
everything else: the status it declares if it implements
`HttpStatusExceptionInterface` with a valid one, a generic `500` only
when no such status contract applies.

## Reading claims or roles without a query

`CurrentUserInterface` is deliberately minimal — `id()` only — so `Gate`
never assumes any auth mechanism carries more than that. But a Policy
method's own parameter type isn't limited to `CurrentUserInterface`
either: it can type-hint the concrete class your auth middleware actually
resolves, and `Gate` passes the real object straight through untouched.

`kinetis/auth-jwt`'s `JwtUser` is the clearest case — it already exposes
every claim the token carried (`claim(string): mixed`, `claims(): stdClass`)
with nothing to look up, since a verified JWT's claims are decoded once,
in memory, at the moment the token is verified. `roles` here isn't a
claim `kinetis/auth-jwt` defines or expects — it's plain data your own
login endpoint chose to put there; see {doc}`auth-jwt`'s "Issuing
tokens" section for setting it in the first place:

```{code-block} php
use Kinetis\AuthJwt\JwtUser;

final readonly class ArticlePolicy
{
    public function publish(JwtUser $user): bool
    {
        return in_array('editor', (array) ($user->claims()->roles ?? []), true);
    }
}
```

```{code-block} php
$this->gate->authorize($user, $this->articlePolicy->publish(...));
```

This works because `Gate` never inspects `$check`'s own parameter type —
it only forwards whatever `CurrentUserInterface` instance it was given.
`allows()`/`denies()`/`authorize()` are generic over the concrete user
type (`@template TUser of CurrentUserInterface`), so PHPStan accepts a
check typed narrower than the interface as long as the object actually
passed at that call site really is that type. Without the generic, the
same check is a contravariance violation.

The same pattern works for `kinetis/auth`'s opaque Bearer tokens, just
with the richer type coming from your own application instead of a
package — `UserProviderInterface::findByToken()` resolves once per
request, so a `CurrentUserInterface` implementation you write yourself
(carrying roles as constructor properties, populated by whatever your own
lookup does) pays that cost once per request, not once per `Gate` check,
the same as `JwtUser` pays it once per token decode.

```{important}
Only type a Policy method against a concrete user class when that
Policy is reachable from exactly one auth mechanism. A route that could
be reached by more than one (JWT on some paths, Bearer on others) needs
either a Policy typed against `CurrentUserInterface` itself, or two
separate Policy methods — a mismatched concrete type is a `TypeError` at
the point `Gate` calls the check, not a caught, reported denial.
```

## Gating a whole route by role

A role/claim check that needs nothing beyond `CurrentUserInterface` — no
specific resolved object, unlike `Gate`'s own case — is better expressed
declaratively than as the first line of every controller method. This
package doesn't ship a class for it; core's existing `#[Middleware]`
attribute already does the job, resolved before the controller runs and
captured into the AOT route cache automatically, since `Route::toArray()`
already carries the middleware list `#[Middleware]` produces.

The one thing `#[Middleware(class-string)]` can't carry is an argument —
so a role check is a thin, per-role subclass, the same pattern
`Kinetis\Http\Middleware\RateLimitMiddleware`/`JwtAuthMiddleware` are left
non-`final` for:

```{code-block} php
class RequireRoleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RequestScope $scope,
        private readonly string $role,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->scope->get(CurrentUserInterface::class);

        if (!$user->hasRole($this->role)) {
            return ErrorResponse::create(403, "Missing role: {$this->role}");
        }

        return $handler->handle($request);
    }
}

final class RequireEditorMiddleware extends RequireRoleMiddleware
{
    public function __construct(RequestScope $scope) { parent::__construct($scope, 'editor'); }
}
```

```{code-block} php
#[Patch('/posts/{id}')]
#[Middleware(RequireEditorMiddleware::class)]
public function update(int $id): array { ... }
```

`$user->hasRole()` above is a stand-in — the actual line depends on which
auth mechanism resolved `CurrentUserInterface` for this route (`JwtUser`'s
`claims()`, or whatever your own `UserProviderInterface` implementation
carries), which is also why this stays a documented pattern rather than a
class this package ships: there's little left to share once that one line
is auth-mechanism-specific.

This composes with `Gate` rather than replacing it: a role gate answers
"can this user do this *kind* of thing at all," resolved once per
request before the controller runs; `Gate::authorize()` still answers
"can this user do this to *this specific* object," which needs the
object in hand and so stays an imperative call inside the controller.

## Provides

Nothing. This package declares no `extra.kinetis` bootstrap, registers no
middleware, and discovers no attribute — the `403` comes from the
exception's own declared status, described above.

`Gate` needs no explicit binding either: it has no constructor
dependencies, so plain autowiring resolves it wherever a controller
constructor-injects it.

## See also

- {doc}`container` — why `Gate` (holding no per-request state) is safe as
  a worker-lifetime autowired instance, the same criterion
  `Kinetis\Http\Middleware\RateLimitMiddleware`'s own docblock establishes.
- {doc}`auth` / {doc}`auth-jwt` / {doc}`session` — where `CurrentUserInterface`
  actually comes from; this package has no dependency on any of them.
- {doc}`middleware` — `ExceptionHandlerMiddleware` and the
  `HttpStatusExceptionInterface` mapping `AuthorizationException`'s `403`
  travels through, and the `#[Middleware]`/thin-subclass pattern "Gating a
  whole route by role" above builds on.
