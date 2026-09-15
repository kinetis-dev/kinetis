<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** The target of Post's #[HasMany], with a second relationship to its author. */
#[Entity(table: 'comments')]
final class Comment
{
    public int $id;

    #[BelongsTo]
    public Post $post;

    #[BelongsTo]
    public Author $author;

    public string $body;
}
