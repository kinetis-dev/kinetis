<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;

final class SpyPostgresLink implements PostgresLink
{
    use RecordsCalls;
}
