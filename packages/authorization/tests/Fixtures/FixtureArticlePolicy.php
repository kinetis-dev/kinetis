<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests\Fixtures;

/**
 * Typed against the concrete FakeClaimsUser, not the generic
 * CurrentUserInterface — proves a Policy method can read claims/roles
 * already carried by a richer user object with no query at all, so long
 * as it type-hints the concrete class that actually carries them.
 */
final readonly class FixtureArticlePolicy
{
    public function publish(FakeClaimsUser $user): bool
    {
        return $user->hasRole('editor');
    }
}
