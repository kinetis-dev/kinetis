<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests\Fixtures\NamedOrm;

use Kinetis\Orm\Attributes\Entity;

#[Entity(table: 'reports', connection: 'reporting')]
final class Report
{
    public int $id;

    public string $title;
}
