<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A nullable relationship over a nullable foreign-key constraint. */
#[Entity(table: 'kin_orm_authors')]
final class StoredAuthor
{
    public int $id;

    public string $name;

    #[BelongsTo]
    public ?StoredOrganization $organization;
}
