<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;

/**
 * One method carrying two distinct RouteAttribute instances — each must
 * produce its own, independently registered Route sharing the same
 * controller method.
 */
final readonly class MultiVerbController
{
    #[Get('/multi')]
    #[Post('/multi')]
    public function handle(): array
    {
        return [];
    }
}
