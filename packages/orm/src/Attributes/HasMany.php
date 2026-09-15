<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks an array property holding every $target entity whose #[BelongsTo]
 * property $mappedBy references this entity. The property maps no column:
 * the target's foreign key is the only one.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class HasMany
{
    /**
     * @param class-string $target the element class, which the array type cannot name
     */
    public function __construct(public string $target, public string $mappedBy) {}
}
