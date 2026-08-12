<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use RuntimeException;

#[Middleware(ClassLevelMiddleware::class)]
final readonly class MiddlewareTestController
{
    #[Get('/middleware-test')]
    #[Middleware(MethodLevelMiddleware::class)]
    public function index(): array
    {
        return ['ok' => true];
    }

    #[Get('/middleware-test/short-circuit')]
    #[Middleware(ShortCircuitMiddleware::class)]
    public function shortCircuited(): array
    {
        return ['reached' => true];
    }

    #[Get('/middleware-test/throws')]
    public function throws(): array
    {
        throw new RuntimeException('boom');
    }
}
