<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** An owned child behind a NOT NULL foreign key the server enforces. */
#[Entity(table: 'kin_orm_items')]
final class StoredItem
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public StoredCrate $crate;

    public string $name;

    public static function in(StoredCrate $crate, string $name): self
    {
        $item = new self();
        $item->crate = $crate;
        $item->name = $name;

        return $item;
    }
}
