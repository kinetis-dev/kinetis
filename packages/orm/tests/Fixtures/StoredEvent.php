<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use DateTimeImmutable;
use Kinetis\Orm\Attributes\Entity;

/** Timestamps read back from six-digit timestamp columns of a real table. */
#[Entity(table: 'kin_orm_events')]
final class StoredEvent
{
    public int $id;

    public DateTimeImmutable $occurredAt;

    public ?DateTimeImmutable $archivedAt;
}
