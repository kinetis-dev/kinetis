<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests\Fixtures;

use Kinetis\Authorization\AuthorizationResponse;
use Kinetis\Http\CurrentUserInterface;

/**
 * A plain class with plain methods — nothing about it is special to
 * kinetis/authorization. Constructor-injected into a controller and
 * called directly (`$this->postPolicy->update(...)` as a first-class
 * callable), the same way any other service dependency would be.
 */
final readonly class FixturePostPolicy
{
    public function update(CurrentUserInterface $user, FixturePost $post): bool|AuthorizationResponse
    {
        if ($post->locked) {
            return AuthorizationResponse::deny('This post is locked and cannot be edited.');
        }

        return $post->authorId === $user->id();
    }
}
