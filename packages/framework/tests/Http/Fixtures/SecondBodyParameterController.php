<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;

/**
 * Two #[Body] parameters on one method — never registrable, a fixture to
 * prove Dispatcher::derivePlan() rejects the second.
 */
final readonly class SecondBodyParameterController
{
    #[Post('/articles')]
    public function create(#[Body('article')] CreateNoteRequest $article, #[Body('author')] CreateUserRequest $author): array
    {
        return [];
    }
}
