<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Version;

/**
 * A versioned entity with a non-nullable relationship over a named
 * foreign-key column, and the inverse side of Comment's relationship to it.
 */
#[Entity(table: 'posts')]
final class Post
{
    public int $id;

    public string $title;

    #[BelongsTo(column: 'written_by')]
    public Author $author;

    #[Version]
    public int $version;

    /** @var list<Comment> */
    #[HasMany(target: Comment::class, mappedBy: 'post')]
    public array $comments;
}
