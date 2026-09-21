<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\OpenApiSecurity;
use Kinetis\Tests\Http\Fixtures\MethodLevelMiddleware;

final readonly class InvalidProviderController
{
    /** MethodLevelMiddleware is a plain PSR-15 middleware: it describes nothing. */
    #[Get('/invalid-provider')]
    #[OpenApiSecurity(MethodLevelMiddleware::class)]
    public function invalid(): array
    {
        return ['ok' => true];
    }
}
