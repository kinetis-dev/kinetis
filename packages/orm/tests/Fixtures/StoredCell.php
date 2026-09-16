<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** A generated key behind a nullable self-referencing foreign key the server enforces. */
#[Entity(table: 'kin_orm_cells')]
final class StoredCell
{
    #[Id(generated: true)]
    public ?int $id = null;

    #[BelongsTo]
    public ?self $twin;

    public string $name;

    public static function named(string $name): self
    {
        $cell = new self();
        $cell->name = $name;
        $cell->twin = null;

        return $cell;
    }
}
