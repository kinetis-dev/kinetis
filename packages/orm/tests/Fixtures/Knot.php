<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A NOT NULL foreign key to its own class: the loop no flush can break. */
#[Entity(table: 'knots')]
final class Knot
{
    public int $id;

    #[BelongsTo]
    public self $partner;

    public static function numbered(int $id): self
    {
        $knot = new self();
        $knot->id = $id;

        return $knot;
    }
}
