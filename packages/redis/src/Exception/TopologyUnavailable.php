<?php

declare(strict_types=1);

namespace Kinetis\Redis\Exception;

use Amp\Redis\RedisException;

/**
 * No seed answered CLUSTER SLOTS within the operation budget, the reply
 * did not describe a complete, non-overlapping 0-16383 slot map with a
 * master for every range, or the budget expired while this fiber was
 * waiting on a read another fiber had already started.
 */
final class TopologyUnavailable extends RedisException
{
}
