<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;

/**
 * Counts every instance PHP destroys, so a test can prove an object was
 * never allocated at all. The static counter is not mapped.
 */
#[Entity(table: 'counted')]
final class Counted
{
    public static int $destroyed = 0;

    public int $id;

    public int $score;

    public function __destruct()
    {
        self::$destroyed++;
    }
}
