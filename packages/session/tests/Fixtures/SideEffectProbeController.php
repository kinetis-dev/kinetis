<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Session\Middleware\SessionMiddleware;

#[Middleware(SessionMiddleware::class)]
final readonly class SideEffectProbeController
{
    public function __construct(private InvocationRecorder $recorder) {}

    #[Get('/side-effect-probe')]
    public function probe(): array
    {
        $this->recorder->record();

        return ['ok' => true];
    }
}
