<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;

/** A relationship to its own class. */
#[Entity(table: 'topics')]
final class Topic
{
    public int $id;

    #[BelongsTo]
    public ?self $parent;
}
