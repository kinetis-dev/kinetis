<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** An entity on the "analytics" connection. */
#[Entity(table: 'metrics', connection: 'analytics')]
final class Metric
{
    public int $id;

    public string $name;
}
