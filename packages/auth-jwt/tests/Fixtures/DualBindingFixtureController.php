<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\AuthJwt\JwtUser;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

/**
 * Constructor-injects both CurrentUserInterface and the concrete JwtUser
 * — the two things a controller can legitimately ask for per
 * JwtAuthMiddleware's own docs — to prove through a real request that
 * they resolve to the identical object, and that a claim only JwtUser
 * exposes (a custom one, plus jti) is genuinely reachable through it.
 */
#[Middleware(FixtureJwtAuthMiddleware::class)]
final readonly class DualBindingFixtureController
{
    public function __construct(
        private CurrentUserInterface $currentUser,
        private JwtUser $jwtUser,
    ) {}

    #[Get('/dual')]
    public function show(): array
    {
        return [
            'sameInstance' => $this->currentUser === $this->jwtUser,
            'role' => $this->jwtUser->claim('role'),
            'jti' => $this->jwtUser->claim('jti'),
        ];
    }
}
