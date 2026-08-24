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
 * Deliberately holds no state and resolves nothing itself: it never sees
 * a Policy class name or an ability string, only whatever callable the
 * caller already has. A controller that needs a Policy check constructor-
 * injects the Policy directly, the same as any other dependency; Gate's
 * only job is the normalize-then-branch step every call site would
 * otherwise repeat.
 */
final class Gate
{
    /**
     * @template TUser of CurrentUserInterface
     * @param TUser $user
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     */
    public function allows(CurrentUserInterface $user, callable $check, mixed ...$arguments): bool
    {
        return $this->evaluate($check, $user, ...$arguments)->allowed;
    }

    /**
     * @template TUser of CurrentUserInterface
     * @param TUser $user
     * @param callable(TUser, mixed...): (bool|AuthorizationResponse) $check
     */
    public function denies(CurrentUserInterface $user, callable $check, mixed ...$arguments): bool
    {
        return !$this->allows($user, $check, ...$arguments);
    }

    /**
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
