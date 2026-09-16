<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

/** A shared target of a real join table, and its inverse end. */
#[Entity(table: 'kin_orm_ribbons')]
final class StoredRibbon
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $name;

    /** @var list<StoredParcel> */
    #[ManyToMany(target: StoredParcel::class, mappedBy: 'ribbons')]
    public array $parcels;

    public static function named(string $name): self
    {
        $ribbon = new self();
        $ribbon->name = $name;

        return $ribbon;
    }
}
