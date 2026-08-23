<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Auth\UserProviderInterface;
use Kinetis\Http\CurrentUserInterface;

final class InMemoryUserProvider implements UserProviderInterface
{
    /** @var array<string, CurrentUserInterface> */
    private array $usersByToken;

    /**
     * @param array<string, CurrentUserInterface> $usersByToken
     */
    public function __construct(array $usersByToken = [])
    {
        $this->usersByToken = $usersByToken;
    }

    public function findByToken(string $token): ?CurrentUserInterface
    {
        return $this->usersByToken[$token] ?? null;
    }
}
