<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasOne;

/** A relationship target with an int identifier, and a non-nullable #[HasOne]. */
#[Entity(table: 'organizations')]
final class Organization
{
    public int $id;

    public string $name;

    #[HasOne(mappedBy: 'organization')]
    public Charter $charter;
}
