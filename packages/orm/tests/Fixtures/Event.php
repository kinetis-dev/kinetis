<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use DateTimeImmutable;
use Kinetis\Orm\Attributes\Entity;

/**
 * Timestamp properties, nullable or not, and a count of every instance PHP
 * destroys, so a test can prove an object was never allocated. The static
 * counter is not mapped.
 */
#[Entity(table: 'events')]
final class Event
{
    public static int $destroyed = 0;

    public int $id;

    public DateTimeImmutable $occurredAt;

    public ?DateTimeImmutable $archivedAt;

    public function __destruct()
    {
        self::$destroyed++;
    }
}
