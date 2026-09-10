<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

/**
 * An empty #[Body] root — never registrable, a fixture to prove
 * Dispatcher::derivePlan() rejects it.
 */
final readonly class EmptyBodyRootController
{
    #[Post('/articles')]
    public function create(#[Body('')] CreateNoteRequest $article): array
    {
        return [];
    }
}
