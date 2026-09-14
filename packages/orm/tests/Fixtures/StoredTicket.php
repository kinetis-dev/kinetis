<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** A generated identifier over a unique subject: MySQL/MariaDB AUTO_INCREMENT, a PostgreSQL identity column. */
#[Entity(table: 'kin_orm_tickets')]
final class StoredTicket
{
    #[Id(generated: true)]
    public ?int $id = null;

    public function __construct(public string $subject) {}
}
