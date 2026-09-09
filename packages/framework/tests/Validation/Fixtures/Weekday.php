<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * A unit enum: it has cases but no backing values, so no wire value
 * can name one and a field declaring it accepts an existing case and
 * nothing else, exactly as any other non-instantiable class does.
 */
enum Weekday
{
    case Monday;

    case Tuesday;
}
