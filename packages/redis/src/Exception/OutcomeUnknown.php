<?php

declare(strict_types=1);

namespace Kinetis\Redis\Exception;

use Amp\Redis\RedisException;

/**
 * Bytes of the command may have reached Redis and no reply arrived: the
 * connection was lost, the operation budget expired, or the wait was
 * cancelled after the write began. Whether the command executed is
 * unknowable from the client, so it is never re-sent.
 *
 * A caller that needs a retry must make the command idempotent, or
 * reconcile the effect itself.
 */
final class OutcomeUnknown extends RedisException
{
}
