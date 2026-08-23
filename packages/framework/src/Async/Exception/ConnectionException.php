<?php

declare(strict_types=1);

namespace Kinetis\Async\Exception;

use RuntimeException;

final class ConnectionException extends RuntimeException
{
    public static function couldNotConnect(string $host, int $port, string $reason): self
    {
        return new self("Could not connect to tcp://{$host}:{$port}: {$reason}");
    }

    public static function closedWhileWriting(): self
    {
        return new self('Connection closed while writing.');
    }

    public static function timedOut(string $host, int $port, float $timeoutSeconds): self
    {
        return new self("Connecting to tcp://{$host}:{$port} timed out after {$timeoutSeconds}s.");
    }
}
