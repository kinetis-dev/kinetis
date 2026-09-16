<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

/** The owning end of a real join table, over a generated key at each end. */
#[Entity(table: 'kin_orm_parcels')]
final class StoredParcel
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $code;

    /** @var list<StoredRibbon> */
    #[ManyToMany(
        target: StoredRibbon::class,
        table: 'kin_orm_parcel_ribbon',
        joinColumn: 'parcel_id',
        inverseJoinColumn: 'ribbon_id',
    )]
    public array $ribbons;

    public static function of(string $code): self
    {
        $parcel = new self();
        $parcel->code = $code;

        return $parcel;
    }
}
