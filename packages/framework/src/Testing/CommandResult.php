<?php

declare(strict_types=1);

namespace Kinetis\Testing;

use Throwable;

/**
 * What {@see TestApplication::command()} observed: the exit code
 * bin/kinetis would have returned for the command, everything it wrote
 * to stdout and to stderr, and the throwable it raised, if any.
 */
final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public ?Throwable $exception,
    ) {}
}
