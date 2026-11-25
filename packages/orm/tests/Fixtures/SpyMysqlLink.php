<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\ReportsJsonDepth;

final class SpyMysqlLink implements MysqlLink, ReportsJsonDepth
{
    use RecordsCalls;

    /** @var positive-int what jsonDepth() reports: MySQL's by default */
    public int $jsonDepth = 100;

    public function jsonDepth(): int
    {
        return $this->jsonDepth;
    }
}
