<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;

/** An aggregate that owns its own class: a tree under one root. */
#[Entity(table: 'branches')]
final class Branch
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public ?self $parent;

    /** @var list<self> */
    #[HasMany(target: self::class, mappedBy: 'parent', owned: true)]
    public array $children;

    public string $name;

    public static function under(?self $parent, string $name): self
    {
        $branch = new self();
        $branch->parent = $parent;
        $branch->name = $name;

        return $branch;
    }
}
