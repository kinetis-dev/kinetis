<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Session\Middleware\CsrfMiddleware;
use Kinetis\Session\Middleware\SessionMiddleware;
use Kinetis\Session\Session;
use RuntimeException;

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

    /** Reads only the id — must still establish and persist that identity. */
    #[Get('/id-only')]
    public function idOnly(): array
    {
        return ['id' => $this->session->id()];
    }

    #[Get('/token')]
    public function token(): array
    {
        return ['token' => $this->session->csrfToken()];
    }

    /** Sets a flash value — the commit right after this writes it under _flash.old already, matching the shift's own timing. */
    #[Get('/flash-set/{value}')]
    public function flashSet(string $value): array
    {
        $this->session->flash('status', $value);

        return ['ok' => true];
    }

    /** Reads a value flashed by an earlier request — the KINETIS-70 FEEDBACK regression check's own probe. */
    #[Get('/flash-read')]
    public function flashRead(): array
    {
        return ['status' => $this->session->flashed('status')];
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

    /**
     * KINETIS-64: regenerate() followed by an exception must leave the
     * original session and cookie untouched — this route reaches
     * ExceptionHandlerMiddleware's own 500 without ever reaching
     * SessionMiddleware's post-handler cookie/commit code.
     */
    #[Get('/rotate-then-throw')]
    public function rotateThenThrow(): never
    {
        $this->session->regenerate();

        throw new RuntimeException('boom after regenerate');
    }

    /** The same proof, for destroy(). */
    #[Get('/logout-then-throw')]
    public function logoutThenThrow(): never
    {
        $this->session->destroy();

        throw new RuntimeException('boom after destroy');
    }
}
