<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

/** A shared target with a generated identifier, and the inverse end of Parcel's join table. */
#[Entity(table: 'ribbons')]
final class Ribbon
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $name;

    /** @var list<Parcel> */
    #[ManyToMany(target: Parcel::class, mappedBy: 'ribbons')]
    public array $parcels;

    public static function named(string $name): self
    {
        $ribbon = new self();
        $ribbon->name = $name;

        return $ribbon;
    }
}
