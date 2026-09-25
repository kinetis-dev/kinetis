<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\NamedOrm;

use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'snapshots', connection: 'archive')]
final class Snapshot
{
    public int $id;

    public string $title;
}
