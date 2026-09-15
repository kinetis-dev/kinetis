<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** The target of StoredAuthor's nullable relationship. */
#[Entity(table: 'kin_orm_organizations')]
final class StoredOrganization
{
    public int $id;

    public string $name;
}
