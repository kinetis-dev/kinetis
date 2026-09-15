<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks a property holding the entity its foreign-key column references.
 * The column is the property name in snake case followed by `_id`
 * (`author` maps to `author_id`) unless $column names it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class BelongsTo
{
    public function __construct(public ?string $column = null) {}
}
