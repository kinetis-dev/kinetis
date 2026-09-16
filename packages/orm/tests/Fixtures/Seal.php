<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** The target of Crate's owned #[HasOne], over a unique foreign-key column. */
#[Entity(table: 'seals')]
final class Seal
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public Crate $crate;

    public string $stamp;

    public static function on(Crate $crate, string $stamp): self
    {
        $seal = new self();
        $seal->crate = $crate;
        $seal->stamp = $stamp;

        return $seal;
    }
}
