<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\ReportsJsonDepth;

final class SpyPostgresLink implements PostgresLink, ReportsJsonDepth
{
    use RecordsCalls;

    /** @var positive-int what jsonDepth() reports: the decoder limit a PostgreSQL client reports */
    public int $jsonDepth = 2499;

    public function jsonDepth(): int
    {
        return $this->jsonDepth;
    }
}
