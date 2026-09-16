<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\ManyToMany;
use Kinetis\Orm\Attributes\Version;

/** A versioned target holding the inverse end of Manifest's join table. */
#[Entity(table: 'pallets')]
final class Pallet
{
    public int $id;

    public string $label;

    #[Version]
    public int $version;

    /** @var list<Manifest> */
    #[ManyToMany(target: Manifest::class, mappedBy: 'pallets')]
    public array $manifests;

    public static function of(int $id, string $label, int $version): self
    {
        $pallet = new self();
        $pallet->id = $id;
        $pallet->label = $label;
        $pallet->version = $version;

        return $pallet;
    }
}
