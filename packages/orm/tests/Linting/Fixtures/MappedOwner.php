<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/**
 * The entity `MappedEntity`'s `#[BelongsTo]` property points at. Neither
 * rule analyses this file; it exists so that owner property has a real
 * entity type.
 */
#[Entity(table: 'tickets')]
final class MappedOwner
{
    #[Id(generated: true)]
    private ?int $id = null;

    public function __construct(private string $subject) {}
}
