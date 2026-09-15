# Authorization

````{note}
Not part of core. Install it separately:

```{code-block} sh
composer require kinetis/authorization
```
````

Use `Gate` to check a user against your own policy before changing a
resource. It accepts a callable that returns an allow/deny decision.

The route below must first run authentication middleware that registers
`CurrentUserInterface` for this request. See {doc}`auth` or
{doc}`auth-jwt` for a guarded route; this example assumes that guard
has already run.

```{code-block} php
use Kinetis\Authorization\Gate;
use Kinetis\Http\Attributes\Body;
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
use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;

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

Only `true` or `AuthorizationResponse::allow()` allows. `false` and
`deny()` deny, and a check that throws or returns anything else raises an
error rather than allowing.

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
use Nyholm\Psr7\Response;

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

`AuthorizationException` implements core's
`Kinetis\Http\Exception\HttpStatusExceptionInterface` with status `403`.
It propagates out of the controller, whose own `return` is never reached,
and `ExceptionHandlerMiddleware`, always part of the global pipeline,
turns it into the response, with the denial message as the error text:

```{code-block} json
{"error": "This post is locked and cannot be edited."}
```

Nothing needs registering or ordering for this.

```{warning}
The denial message is sent to the client. Write it for the caller, and
never put internal detail or another user's data in it.
```

Only a denied check becomes this exception. Anything the check itself
throws propagates unchanged, and `ExceptionHandlerMiddleware` answers it
by the rule it applies to every exception: the status it declares
through `HttpStatusExceptionInterface`, otherwise a generic `500` (see
{doc}`middleware`'s "Mapping your own exceptions to a status").

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
login endpoint chose to put there; see {doc}`auth-jwt`'s "Issue tokens"
section for setting it in the first place:

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

A token without a `roles` claim is denied.

This works because `Gate` never inspects `$check`'s own parameter type —
it only forwards whatever `CurrentUserInterface` instance it was given.
`allows()`/`denies()`/`authorize()` are generic over the concrete user
type (`@template TUser of CurrentUserInterface`), so PHPStan accepts a
check typed narrower than the interface as long as the object actually
passed at that call site really is that type.

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
package doesn't ship a class for it: core's `#[Middleware]` attribute
already does the job, running before the controller and compiled into the
route cache like any other route middleware.

The one thing `#[Middleware(class-string)]` can't carry is an argument —
so a role check is a thin, per-role subclass, the same pattern
`Kinetis\Http\Middleware\RateLimitMiddleware` is left non-`final` for:

```{code-block} php
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
use Kinetis\AuthJwt\JwtAuthMiddleware;

#[Patch('/posts/{id}')]
#[Middleware(JwtAuthMiddleware::class)]
#[Middleware(RequireEditorMiddleware::class)]
public function update(int $id): array { ... }
```

Declare the role middleware after the authentication middleware: route
middleware runs in declaration order. Placed first, it finds no
`CurrentUserInterface` on the request, so `$this->scope->get()` throws
and the controller never runs.

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

`Gate` needs no binding either: it has no constructor dependencies and
holds no state, so autowiring builds it wherever a controller
constructor-injects it.

## See also

- {doc}`auth` / {doc}`auth-jwt` / {doc}`session` — where `CurrentUserInterface`
  actually comes from; this package has no dependency on any of them.
- {doc}`middleware` — `ExceptionHandlerMiddleware` and the
  `HttpStatusExceptionInterface` mapping `AuthorizationException`'s `403`
  travels through, and the `#[Middleware]`/thin-subclass pattern "Gating a
  whole route by role" above builds on.
- {doc}`container` — how autowiring resolves an unregistered class such
  as `Gate`.
