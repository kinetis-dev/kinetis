<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasOne;

/**
 * The target of StoredAuthor's nullable relationship. Its non-nullable
 * #[HasOne] runs over that foreign key, which has no unique constraint, so
 * a missing and a duplicate row are both observable.
 */
#[Entity(table: 'kin_orm_organizations')]
final class StoredOrganization
{
    public int $id;

    public string $name;

    #[HasOne(mappedBy: 'organization')]
    public StoredAuthor $soleMember;
}
