<?php

declare(strict_types=1);

namespace Kinetis\Testing\Exception;

use RuntimeException;

final class FreePortException extends RuntimeException
{
    public static function couldNotReserve(int $errno, string $errstr): self
    {
        return new self("Could not reserve a free TCP port: {$errstr} ({$errno})");
    }

    public static function couldNotReadAssignedPort(): self
    {
        return new self('Could not read back the port the kernel assigned.');
    }
}
