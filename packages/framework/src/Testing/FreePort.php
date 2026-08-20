<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Kinetis\Testing\Exception\FreePortException;

/**
 * A TCP port nothing is currently listening on, for a test that spawns
 * its own fixture server. Asks the kernel for one (bind to port 0, read
 * back what it assigned, release it) instead of hard-coding a number —
 * two suites in one repository picking the same fixed port collide the
 * moment they run in parallel, and only ever do so intermittently.
 *
 * The reservation is released before the caller binds, so a genuinely
 * concurrent claimant could still win the race in between; in practice
 * the window is microseconds and the kernel rotates ephemeral ports, so
 * this is a large improvement over a fixed port, not a guarantee.
 */
final class FreePort
{
    public static function reserve(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw FreePortException::couldNotReserve($errno, $errstr);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if ($name === false) {
            throw FreePortException::couldNotReadAssignedPort();
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
