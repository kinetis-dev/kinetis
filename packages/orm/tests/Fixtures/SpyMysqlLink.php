<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;

final class SpyMysqlLink implements MysqlLink
{
    use RecordsCalls;
}
