<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

/**
 * Kept on its own controller, away from MiddlewareGroupController's valid
 * references: Kernel validates every registered route's group references
 * at construction, so a single undeclared one anywhere would stop a
 * Kernel carrying this controller from being built at all — which is
 * exactly what this fixture is for.
 */
final readonly class UnknownMiddlewareGroupController
{
    #[Get('/groups/unknown')]
    #[Middleware('@does-not-exist')]
    public function unknown(): array
    {
        return ['ok' => true];
    }
}
