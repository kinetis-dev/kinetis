<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;

/** Relationships to its own class: a parent and its children, and the topic one supersedes and the one superseding it. */
#[Entity(table: 'topics')]
final class Topic
{
    public int $id;

    #[BelongsTo]
    public ?self $parent;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent')]
    public array $children;

    #[BelongsTo]
    public ?self $supersedes;

    #[HasOne(mappedBy: 'supersedes')]
    public ?self $supersededBy;
}
