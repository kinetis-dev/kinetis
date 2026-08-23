<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;

/**
 * Routes shared by several controllers through a trait, each mounting them
 * under its own #[RoutePrefix]. The empty path sits at the prefix itself.
 */
trait SharedCrudRoutes
{
    #[Get('/')]
    public function index(): array
    {
        return ['listed'];
    }

    #[Get('/{id}')]
    public function show(int $id): array
    {
        return ['id' => $id];
    }
}
