<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

#[Middleware(VersionedMiddleware::class)]
final class VersionPrefixedController
{
    #[Get('/users')]
    public function index(): array
    {
        return [];
    }
}
