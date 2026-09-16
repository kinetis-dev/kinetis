<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** The target of StoredCrate's owned #[HasOne], behind a UNIQUE NOT NULL foreign key. */
#[Entity(table: 'kin_orm_seals')]
final class StoredSeal
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public StoredCrate $crate;

    public string $stamp;

    public static function on(StoredCrate $crate, string $stamp): self
    {
        $seal = new self();
        $seal->crate = $crate;
        $seal->stamp = $stamp;

        return $seal;
    }
}
