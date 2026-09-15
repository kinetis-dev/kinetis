<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** The target of StoredAuthor's nullable #[HasOne], over a UNIQUE foreign-key column. */
#[Entity(table: 'kin_orm_profiles')]
final class StoredProfile
{
    public int $id;

    #[BelongsTo]
    public StoredAuthor $author;

    public string $bio;
}
