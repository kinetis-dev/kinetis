<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** A database-generated identifier. */
#[Entity(table: 'tickets')]
final class Ticket
{
    #[Id(generated: true)]
    public ?int $id = null;

    public function __construct(public string $subject) {}
}
