<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;

/** An owned child over a NOT NULL foreign key, itself owning a second level. */
#[Entity(table: 'items')]
final class Item
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public Crate $crate;

    public string $name;

    /** @var list<Part> */
    #[HasMany(target: Part::class, mappedBy: 'item', owned: true)]
    public array $parts;

    public static function in(Crate $crate, string $name): self
    {
        $item = new self();
        $item->crate = $crate;
        $item->name = $name;

        return $item;
    }
}
