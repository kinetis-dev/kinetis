<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;

/**
 * A nullable relationship over a nullable foreign-key constraint, and the
 * inverse sides of StoredProfile's and StoredPost's relationships to it.
 */
#[Entity(table: 'kin_orm_authors')]
final class StoredAuthor
{
    public int $id;

    public string $name;

    #[BelongsTo]
    public ?StoredOrganization $organization;

    #[HasOne(mappedBy: 'author')]
    public ?StoredProfile $profile;

    /** @var list<StoredPost> */
    #[HasMany(target: StoredPost::class, mappedBy: 'author')]
    public array $posts;
}
