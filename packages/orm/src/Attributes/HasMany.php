<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks an array property holding every $target entity whose #[BelongsTo]
 * property $mappedBy references this entity. The property maps no column:
 * the target's foreign key is the only one.
 *
 * $owned makes the relationship an aggregate ownership edge: flush()
 * discovers new targets through it, removing this entity removes them, and
 * dropping one from a loaded relationship deletes or reparents its row. A
 * target class carries at most one owned inverse in the whole registry.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasMany
{
    /**
     * @param class-string $target the element class, which the array type cannot name
     */
    public function __construct(public string $target, public string $mappedBy, public bool $owned = false) {}
}
