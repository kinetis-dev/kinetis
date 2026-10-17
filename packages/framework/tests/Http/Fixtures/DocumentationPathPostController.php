<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Post;

/**
 * An application route on a documentation path, under a method the
 * documentation does not use.
 */
final readonly class DocumentationPathPostController
{
    #[Post('/openapi.json')]
    public function store(): array
    {
        return ['stored' => true];
    }
}
