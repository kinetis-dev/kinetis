<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests\Fixtures;

use Kinetis\Http\CurrentUserInterface;

/**
 * Stands in for a richer CurrentUserInterface implementation that carries
 * more than an id — kinetis/auth-jwt's own JwtUser is the real-world
 * shape this mirrors (id() from the sub claim, roles/permissions already
 * decoded from the token, no query needed to read them).
 */
final readonly class FakeClaimsUser implements CurrentUserInterface
{
    /** @param list<string> $roles */
    public function __construct(
        private string|int $userId,
        private array $roles,
    ) {}

    public function id(): string|int
    {
        return $this->userId;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
