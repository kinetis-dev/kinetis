<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures\GroupProject;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Session\Session;

/**
 * References the `session` group by name rather than naming
 * SessionMiddleware/CsrfMiddleware directly — the point of the
 * extension: a route that only knows about the group still gets Session
 * registered before Csrf checks it.
 */
#[Middleware('@session')]
final readonly class GroupedSessionController
{
    public function __construct(private Session $session) {}

    #[Get('/group-token')]
    public function token(): array
    {
        return ['token' => $this->session->csrfToken()];
    }

    #[Post('/group-guarded')]
    public function guarded(): array
    {
        return ['changed' => true];
    }
}
