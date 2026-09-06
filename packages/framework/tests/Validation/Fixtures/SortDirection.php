<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

enum SortDirection: string
{
    case Ascending = 'asc';

    case Descending = 'desc';
}
