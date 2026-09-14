<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks a class as an entity. $table overrides the default table name,
 * the class's short name in snake case (`ArticleCategory` maps to
 * `article_category`); a dot separates a schema from the table
 * (`reporting.articles`).
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    public function __construct(public ?string $table = null) {}
}
