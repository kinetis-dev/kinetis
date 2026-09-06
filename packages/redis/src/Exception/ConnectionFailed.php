<?php

declare(strict_types=1);

namespace Kinetis\Redis\Exception;

use Amp\Redis\RedisException;

/**
 * The operation ended before any byte of its command could have reached
 * Redis: the connection was never established, the operation budget
 * expired while it was still being established, the budget was already
 * spent when the command reached the transport, or the link was closed
 * under a connection still being set up. The command was not executed,
 * so retrying it is safe.
 *
 * Messages name the endpoint's host and port only — never a URI, a
 * password, or any other credential.
 */
final class ConnectionFailed extends RedisException
{
}
