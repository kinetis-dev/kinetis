<?php

declare(strict_types=1);

namespace Kinetis\Redis\Exception;

use Amp\Redis\RedisException;

/**
 * A cluster operation was redirected more times than the fixed attempt
 * bound allows. A healthy cluster needs at most a MOVED followed by an
 * ASK for one key; reaching the bound means slots are flapping between
 * nodes.
 */
final class RedirectLimitExceeded extends RedisException
{
}
