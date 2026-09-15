<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** The target of Organization's non-nullable #[HasOne]. */
#[Entity(table: 'charters')]
final class Charter
{
    public int $id;

    #[BelongsTo]
    public Organization $organization;

    public string $text;
}
