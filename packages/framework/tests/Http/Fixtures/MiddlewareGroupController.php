<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

final readonly class MiddlewareGroupController
{
    #[Get('/groups/admin')]
    #[Middleware('@admin')]
    public function admin(): array
    {
        return ['ok' => true];
    }

    /**
     * A group reference and a plain class-string on the same route — the
     * group expands where its own reference sits, so declaration order
     * still governs the whole list.
     */
    #[Get('/groups/mixed')]
    #[Middleware(MethodLevelMiddleware::class)]
    #[Middleware('@admin')]
    public function mixed(): array
    {
        return ['ok' => true];
    }
}
