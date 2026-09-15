<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A non-nullable relationship over a named NOT NULL foreign-key constraint. */
#[Entity(table: 'kin_orm_posts')]
final class StoredPost
{
    public int $id;

    public string $title;

    #[BelongsTo(column: 'written_by')]
    public StoredAuthor $author;
}
