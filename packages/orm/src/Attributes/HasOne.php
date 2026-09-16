<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks a property holding the one entity whose #[BelongsTo] property
 * $mappedBy references this entity. The target is the property's class.
 * The property maps no column: the target's foreign key is the only one.
 *
 * $owned makes the relationship an aggregate ownership edge: flush()
 * discovers a new target through it, removing this entity removes the
 * target, and dropping the target from a loaded relationship deletes or
 * reparents its row. A target class carries at most one owned inverse in
 * the whole registry.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasOne
{
    public function __construct(public string $mappedBy, public bool $owned = false) {}
}
