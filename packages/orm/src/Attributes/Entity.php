<?php

declare(strict_types=1);

namespace Kinetis\Orm\Attributes;

use Attribute;

/**
 * Marks a class as an entity. $table overrides the default table name,
 * the class's short name in snake case (`ArticleCategory` maps to
 * `article_category`); a dot separates a schema from the table
 * (`reporting.articles`).
 *
 * $connection names the database the entity lives on: lowercase ASCII
 * letters and digits, starting with a letter, and not the reserved `app`.
 * Every relationship stays on one connection, and each OrmFactory maps the
 * entities of one.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Entity
{
    public function __construct(public ?string $table = null, public string $connection = 'default') {}
}
