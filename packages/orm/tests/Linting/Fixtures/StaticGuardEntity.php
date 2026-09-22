<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/**
 * `MetadataRegistry::entity()` skips a static property even on an
 * `#[Entity]` class, so the extension must leave this one standing for
 * both rules.
 */
#[Entity]
final class StaticGuardEntity
{
    private static ?int $cache = null;
}
