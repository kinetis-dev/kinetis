<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

final class InheritingController extends SecuredParentController
{
    #[Get('/inheriting')]
    #[Middleware(TokenAuthMiddleware::class)]
    public function show(): array
    {
        return ['ok' => true];
    }
}
