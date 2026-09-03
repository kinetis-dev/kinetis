<?php

declare(strict_types=1);

namespace Kinetis\AuthJwt\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\CurrentUserInterface;

#[Middleware(IssuerAudienceCheckingFixtureMiddleware::class)]
final readonly class IssuerAudienceCheckedFixtureController
{
    public function __construct(
        private CurrentUserInterface $user,
    ) {}

    #[Get('/issuer-audience-checked')]
    public function show(): array
    {
        return ['userId' => $this->user->id()];
    }
}
