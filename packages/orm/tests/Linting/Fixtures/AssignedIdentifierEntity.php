<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Linting\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'invoices')]
final class AssignedIdentifierEntity
{
    #[Id]
    private ?int $id = null;

    public function __construct(private string $subject) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function subject(): string
    {
        return $this->subject;
    }
}
