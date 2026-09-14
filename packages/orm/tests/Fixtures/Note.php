<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/** A nullable identifier property: the type admits null, a loaded row may not. */
#[Entity(table: 'notes')]
final class Note
{
    public ?int $id;

    public string $body;
}
