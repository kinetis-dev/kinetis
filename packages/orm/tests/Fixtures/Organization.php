<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** A relationship target with an int identifier. */
#[Entity(table: 'organizations')]
final class Organization
{
    public int $id;

    public string $name;
}
