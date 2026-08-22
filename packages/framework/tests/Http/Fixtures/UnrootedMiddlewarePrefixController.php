<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

#[Middleware(UnrootedPrefixMiddleware::class)]
final class UnrootedMiddlewarePrefixController
{
    #[Get('/items')]
    public function index(): array
    {
        return [];
    }
}
