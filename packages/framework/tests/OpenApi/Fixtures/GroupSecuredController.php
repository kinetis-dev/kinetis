<?php

declare(strict_types=1);

namespace Kinetis\Tests\OpenApi\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;

final readonly class GroupSecuredController
{
    #[Get('/group/secured')]
    #[Middleware('@secure')]
    public function secured(): array
    {
        return ['ok' => true];
    }
}
