<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A string identifier, and a nullable relationship over the conventional foreign-key column. */
#[Entity(table: 'authors')]
final class Author
{
    public string $id;

    public string $name;

    #[BelongsTo]
    public ?Organization $organization;
}
