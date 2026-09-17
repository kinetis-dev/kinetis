<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol\Exception;

use RuntimeException;

/**
 * A JSON-RPC frame that could only be partially written to the output
 * stream. The byte counts are what say the stream is now in an ambiguous
 * state no further write can land in: a frame with no trailing newline
 * corrupts the framing of every message after it, so {@see
 * \Kinetis\McpProtocol\StdioLoop} writes nothing more — not even a second
 * protocol response describing this failure — and lets this propagate out
 * of the loop instead.
 */
final class StdioWriteException extends RuntimeException
{
    public static function partialFrame(int $written, int $total): self
    {
        return new self("Wrote only {$written} of {$total} bytes of a JSON-RPC frame to the output stream.");
    }
}
