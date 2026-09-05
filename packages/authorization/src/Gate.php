<?php

declare(strict_types=1);

namespace Kinetis\Authorization;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Http\CurrentUserInterface;

/**
 * Wraps a callable authorization check — a Policy method reference
 * (`$this->postPolicy->update(...)`) or a plain closure — normalizing its
 * `bool|AuthorizationResponse` result and giving three ways to act on it.
 *
 * Holds no state and resolves nothing itself: it never sees a Policy
 * class name or an ability string, only whatever callable the caller
 * already has. A controller that needs a Policy check constructor-
 * injects the Policy directly, the same as any other dependency; Gate's
 * only job is the normalize-then-branch step every call site would
 * otherwise repeat.
 *
 * None of the three methods catches anything the check itself throws:
 * a check that fails outright is a failure, not a denial, and reaches
 * ExceptionHandlerMiddleware as whatever it already is. Only a decided
 * denial is turned into an AuthorizationException, and only by
 * authorize().
 */
final class Gate
{
    /**
     * Reports the decision instead of acting on it: a denial is a `false`
     * return, never an AuthorizationException. An exception raised by
     * $check itself still propagates.
     *
     * @template TUser of CurrentUserInterface
     * @param TUser $user
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     */
    public function allows(CurrentUserInterface $user, callable $check, mixed ...$arguments): bool
    {
        return $this->evaluate($check, $user, ...$arguments)->allowed;
    }

    /**
     * The exact inverse of allows(), with the identical behavior on a
     * check that throws.
     *
     * @template TUser of CurrentUserInterface
     * @param TUser $user
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     */
    public function denies(CurrentUserInterface $user, callable $check, mixed ...$arguments): bool
    {
        return !$this->allows($user, $check, ...$arguments);
    }

    /**
     * Hard-stops the request on a denial: AuthorizationException declares
     * a 403 through core's HttpStatusExceptionInterface, so it becomes
     * that response wherever it is thrown, with no middleware to register.
     *
     * @template TUser of CurrentUserInterface
     * @param TUser $user
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     * @throws AuthorizationException when the check denies
     */
    public function authorize(CurrentUserInterface $user, callable $check, mixed ...$arguments): void
    {
        $response = $this->evaluate($check, $user, ...$arguments);

        if (!$response->allowed) {
            throw AuthorizationException::denied($response->message ?? 'This action is unauthorized.');
        }
    }

    /**
     * @template TUser of CurrentUserInterface
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     * @param TUser $user
     */
    private function evaluate(callable $check, CurrentUserInterface $user, mixed ...$arguments): AuthorizationResponse
    {
        $result = $check($user, ...$arguments);

        return is_bool($result) ? AuthorizationResponse::fromBool($result) : $result;
    }
}
