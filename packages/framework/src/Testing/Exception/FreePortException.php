<?php

declare(strict_types=1);

namespace Kinetis\Testing\Exception;

use RuntimeException;

final class FreePortException extends RuntimeException
{
    /**
     * stream_socket_server() leaves both out-parameters null when it
     * fails before the OS is even asked, hence the nullable types.
     */
    public static function couldNotReserve(?int $errno, ?string $errstr): self
    {
        return new self(sprintf('Could not reserve a free TCP port: %s (%s)', $errstr ?? 'unknown error', $errno ?? '?'));
    }

    public static function couldNotReadAssignedPort(): self
    {
        return new self('Could not read back the port the kernel assigned.');
    }
}
