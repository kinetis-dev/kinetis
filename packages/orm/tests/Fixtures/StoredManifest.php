<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;
use Kinetis\Orm\Attributes\Version;

/** A versioned owner of a real join table, over a BIGINT version column. */
#[Entity(table: 'kin_orm_manifests')]
final class StoredManifest
{
    #[Id(generated: true)]
    public ?int $id = null;

    public string $code;

    #[Version]
    public int $version;

    /** @var list<StoredRibbon> */
    #[ManyToMany(
        target: StoredRibbon::class,
        table: 'kin_orm_manifest_ribbon',
        joinColumn: 'manifest_id',
        inverseJoinColumn: 'ribbon_id',
    )]
    public array $ribbons;
}
