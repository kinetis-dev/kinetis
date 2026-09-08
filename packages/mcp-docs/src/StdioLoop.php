<?php

declare(strict_types=1);

namespace Kinetis\McpDocs;

use Kinetis\McpDocs\Exception\StdioWriteException;

/**
 * The transport an MCP client launches this server as: one JSON-RPC
 * message per line on stdin, one response per line on stdout. Streams
 * are parameters rather than the process's own, so the suite drives the
 * same loop against `php://memory`.
 *
 * End of input ends the loop and returns — a client closing the pipe is
 * how a stdio server is stopped, not a failure to report.
 */
final class StdioLoop
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function run(McpDocsServer $server, $input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            // Only the framing terminator is stripped, never a bare
            // trim(), whose default charlist also removes NUL and
            // vertical-tab bytes and would turn a line that is only
            // valid JSON once they are gone into accepted input. A line
            // left holding nothing but spaces and tabs is a blank
            // between messages; anything else goes to the decoder,
            // which rejects it as a parse error.
            $line = rtrim($line, "\r\n");

            if (trim($line, " \t") === '') {
                continue;
            }

            $decoded = JsonRpcEnvelope::decode($line);

            $response = array_key_exists('request', $decoded)
                ? $server->handle($decoded['request'])
                : $decoded['errorResponse'];

            if ($response !== null) {
                $this->writeFrame($output, $response);
            }
        }
    }

    /**
     * Writes one complete frame — the encoded message plus its framing
     * newline — looping until every byte of it has been written.
     * fwrite() may accept fewer bytes than it was given, and a single
     * unchecked call can leave a truncated line that corrupts every
     * message after it.
     *
     * A return of 0 is terminal rather than retried: stdout here is a
     * blocking stream, so it means the stream can no longer accept data
     * at all, most often a reader that has gone away. Retrying would
     * spin against a stream that will never report progress again.
     *
     * @param resource $output
     * @param array<string, mixed> $message
     * @throws StdioWriteException
     */
    private function writeFrame($output, array $message): void
    {
        $frame = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $total = strlen($frame);
        $written = 0;

        while ($written < $total) {
            $result = fwrite($output, substr($frame, $written));

            if ($result === false || $result === 0) {
                throw StdioWriteException::partialFrame($written, $total);
            }

            $written += $result;
        }
    }
}
