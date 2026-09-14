<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** A property named version without #[Version]: ordinary mapped data. */
#[Entity(table: 'editions')]
final class Edition
{
    public int $id;

    public int $version;
}
