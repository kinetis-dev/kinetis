<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Overrides one property's column name, which is otherwise the property
 * name in snake case (`publishedAt` maps to `published_at`).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Column
{
    public function __construct(public ?string $name = null) {}
}
