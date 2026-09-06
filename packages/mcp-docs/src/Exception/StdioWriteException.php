<?php

declare(strict_types=1);

namespace Kinetis\McpDocs\Exception;

use RuntimeException;

/**
 * A JSON-RPC frame that could only be partially written to stdout. The
 * byte counts are what say the stream is now in an ambiguous state that
 * no further write can land in, so StdioLoop stops rather than emitting
 * a second message after a truncated one.
 */
final class StdioWriteException extends RuntimeException
{
    public static function partialFrame(int $written, int $total): self
    {
        return new self("Wrote only {$written} of {$total} bytes of a JSON-RPC frame to stdout.");
    }
}
