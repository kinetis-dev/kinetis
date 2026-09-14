<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** Application-generated UUID text: PostgreSQL uuid, MySQL/MariaDB CHAR(36). */
#[Entity(table: 'kin_orm_documents')]
final class StoredDocument
{
    public string $id;

    public string $title;
}
