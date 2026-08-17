<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;

#[Middleware(SessionMiddleware::class)]
final readonly class SessionFixtureController
{
    public function __construct(private Session $session) {}

    #[Get('/remember/{value}')]
    public function remember(string $value): array
    {
        $this->session->set('remembered', $value);

        return ['stored' => $value];
    }

    #[Get('/recall')]
    public function recall(): array
    {
        return ['remembered' => $this->session->get('remembered')];
    }

    /** Never touches the session — must produce no cookie. */
    #[Get('/untouched')]
    public function untouched(): array
    {
        return ['ok' => true];
    }

    #[Get('/token')]
    public function token(): array
    {
        return ['token' => $this->session->csrfToken()];
    }

    #[Post('/guarded')]
    #[Middleware(CsrfMiddleware::class)]
    public function guarded(): array
    {
        return ['changed' => true];
    }

    #[Get('/rotate')]
    public function rotate(): array
    {
        $this->session->regenerate();

        return ['id' => $this->session->id()];
    }

    #[Get('/logout')]
    public function logout(): array
    {
        $this->session->destroy();

        return ['out' => true];
    }
}
