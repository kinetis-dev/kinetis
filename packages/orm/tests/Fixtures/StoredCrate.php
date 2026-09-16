<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;
use Kinetis\Orm\Attributes\Id;

/** An aggregate root over a real generated key, a NOT NULL child collection and a UNIQUE owned target. */
#[Entity(table: 'kin_orm_crates')]
final class StoredCrate
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $code;

    /** @var list<StoredItem> */
    #[HasMany(target: StoredItem::class, mappedBy: 'crate', owned: true)]
    public array $items;

    #[HasOne(mappedBy: 'crate', owned: true)]
    public ?StoredSeal $seal;

    public static function of(string $code): self
    {
        $crate = new self();
        $crate->code = $code;

        return $crate;
    }
}
