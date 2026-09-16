<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** A generated identifier behind a nullable foreign key to its own class: the loop a flush can break. */
#[Entity(table: 'cells')]
final class Cell
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
