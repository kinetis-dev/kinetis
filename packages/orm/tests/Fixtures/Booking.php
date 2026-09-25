<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Date;

/** Calendar-date properties, nullable or not. */
#[Entity(table: 'bookings')]
final class Booking
{
    public int $id;

    public Date $arrivesOn;

    public ?Date $cancelledOn;
}
