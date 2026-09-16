<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\ManyToMany;
use Kinetis\Orm\Attributes\Version;

/** A versioned owner of two join tables. */
#[Entity(table: 'manifests')]
final class Manifest
{
    public int $id;

    public string $code;

    #[Version]
    public int $version;

    /** @var list<Ribbon> */
    #[ManyToMany(target: Ribbon::class, table: 'manifest_ribbon', joinColumn: 'manifest_id', inverseJoinColumn: 'ribbon_id')]
    public array $ribbons;

    /** @var list<Pallet> */
    #[ManyToMany(target: Pallet::class, table: 'manifest_pallet', joinColumn: 'manifest_id', inverseJoinColumn: 'pallet_id')]
    public array $pallets;

    public static function of(int $id, string $code, int $version): self
    {
        $manifest = new self();
        $manifest->id = $id;
        $manifest->code = $code;
        $manifest->version = $version;

        return $manifest;
    }
}
