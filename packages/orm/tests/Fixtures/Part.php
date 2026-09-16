<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** The second level of the Crate aggregate, with an application-assigned identifier. */
#[Entity(table: 'parts')]
final class Part
{
    public int $id;

    #[BelongsTo]
    public Item $item;

    public string $name;

    public static function of(Item $item, int $id, string $name): self
    {
        $part = new self();
        $part->id = $id;
        $part->item = $item;
        $part->name = $name;

        return $part;
    }
}
