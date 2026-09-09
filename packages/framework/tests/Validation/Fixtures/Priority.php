<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

/**
 * An int-backed enum, whose wire value is a JSON number rather than a
 * string — the half of the backing-type contract SortDirection cannot
 * show.
 */
enum Priority: int
{
    case Low = 1;

    case Normal = 2;

    case High = 3;
}
