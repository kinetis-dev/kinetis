<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** Every name from convention: table, identifier and a camel-case column. */
#[Entity]
final class ArticleCategory
{
    public int $id;

    public string $displayName;
}
