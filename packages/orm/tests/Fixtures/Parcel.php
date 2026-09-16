<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

/** The owning end of two join tables, one to an int identifier and one to a string one. */
#[Entity(table: 'parcels')]
final class Parcel
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $code;

    /** @var list<Ribbon> */
    #[ManyToMany(target: Ribbon::class, table: 'parcel_ribbon', joinColumn: 'parcel_id', inverseJoinColumn: 'ribbon_id')]
    public array $ribbons;

    /** @var list<Stamp> */
    #[ManyToMany(target: Stamp::class, table: 'parcel_stamp', joinColumn: 'parcel_id', inverseJoinColumn: 'stamp_code')]
    public array $stamps;

    public static function of(string $code): self
    {
        $parcel = new self();
        $parcel->code = $code;

        return $parcel;
    }
}
