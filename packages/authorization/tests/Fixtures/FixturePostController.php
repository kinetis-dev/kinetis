<?php

declare(strict_types=1);

namespace Kinetis\Authorization\Tests\Fixtures;

use Kinetis\Authorization\Gate;
use Kinetis\Http\Attributes\Patch;

final readonly class FixturePostController
{
    public function __construct(
        private Gate $gate,
        private FixturePostPolicy $postPolicy,
    ) {}

    #[Patch('/posts/{id}')]
    public function update(int $id): array
    {
        $author = new FakeCurrentUser(7);
        $post = new FixturePost(authorId: 7, locked: $id === 99);

        $this->gate->authorize($author, $this->postPolicy->update(...), $post);

        return ['id' => $id, 'updated' => true];
    }
}
