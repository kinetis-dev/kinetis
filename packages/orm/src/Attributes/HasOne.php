<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks a property holding the one entity whose #[BelongsTo] property
 * $mappedBy references this entity. The target is the property's class.
 * The property maps no column: the target's foreign key is the only one.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasOne
{
    public function __construct(public string $mappedBy) {}
}
