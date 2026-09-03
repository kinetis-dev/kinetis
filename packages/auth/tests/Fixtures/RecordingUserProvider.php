<?php

declare(strict_types=1);

namespace Kinetis\Auth\Tests\Fixtures;

use Kinetis\Auth\UserProviderInterface;
use Kinetis\Http\CurrentUserInterface;

/**
 * Records every token findByToken() was actually called with — used to
 * prove BearerAuthMiddleware never reaches the provider on a header
 * parse failure, and that it passes the exact accepted credential bytes
 * through unchanged on success.
 */
final class RecordingUserProvider implements UserProviderInterface
{
    /** @var list<string> */
    public array $calls = [];

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
        $this->calls[] = $token;

        return $this->usersByToken[$token] ?? null;
    }
}
