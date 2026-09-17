<?php

declare(strict_types=1);

namespace Kinetis\McpProtocol;

use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\Exception\StdioWriteException;

/**
 * The transport an MCP client launches a server as: one JSON-RPC message
 * per line on stdin, one frame per response on stdout. Streams are
 * parameters rather than the process's own, so the same loop runs against
 * `php://memory`.
 *
 * Synchronous and one message at a time, which is the backpressure: a
 * client cannot outrun a server that has not read its next line. End of
 * input ends the loop and returns — a client closing the pipe is how a
 * stdio server is stopped, not a failure.
 *
 * Input is read in bounded chunks and a payload is capped at {@see
 * MAX_PAYLOAD_BYTES}, so a client cannot make the server hold an
 * arbitrarily large line in memory. There is no output cap: a tool result
 * has no universal safe size, so each consumer bounds its own.
 */
final class StdioLoop
{
    /**
     * The largest message payload accepted, matching the framework's own
     * default HTTP body ceiling. The framing terminator is not counted.
     */
    public const int MAX_PAYLOAD_BYTES = 2_097_152;

    /** How much of a line is read at a time. */
    private const int CHUNK_BYTES = 65_536;

    /**
     * @param resource $input
     * @param resource $output
     * @throws StdioWriteException when a frame could only be partially
     *     written; nothing further is written to a stream left in that
     *     state, so the loop ends rather than corrupting the framing of
     *     every message after it
     */
    public function run(MessageHandler $handler, $input, $output): void
    {
        $buffer = '';
        // Set once a payload has already passed the cap: the rest of that
        // one line is read and discarded so the next frame starts clean,
        // and exactly one parse error is written for it.
        $draining = false;

        // fgets() with a length reads at most CHUNK_BYTES and stops early
        // at a newline, so no single read is unbounded — a whole-line
        // fgets() would let one client line size the server's memory.
        while (($chunk = fgets($input, self::CHUNK_BYTES + 1)) !== false) {
            $terminated = str_ends_with($chunk, "\n");

            if ($draining) {
                if ($terminated) {
                    $draining = false;
                    $this->writeFrame($output, JsonRpcCodec::errorEnvelope(null, JsonRpcException::parseError()));
                }

                continue;
            }

            $buffer .= $chunk;

            if ($terminated) {
                $payload = $buffer;
                $buffer = '';
                $this->accept($handler, $output, $payload);

                continue;
            }

            // Not oversized merely because a full chunk arrived with no
            // terminator: the line may simply continue, or end at EOF
            // within the cap. Only the accumulated payload decides.
            if (strlen($buffer) > self::MAX_PAYLOAD_BYTES) {
                $buffer = '';
                $draining = true;
            }
        }

        if ($draining) {
            // The oversized line ran to EOF without a terminator; its one
            // parse error is still owed.
            $this->writeFrame($output, JsonRpcCodec::errorEnvelope(null, JsonRpcException::parseError()));

            return;
        }

        // A final line at EOF with no terminator is a complete message.
        if ($buffer !== '') {
            $this->accept($handler, $output, $buffer);
        }
    }

    /**
     * One framed payload: the framing terminator removed, the cap
     * enforced, and the message decoded and answered.
     *
     * Only `\r`/`\n` are stripped, never a bare `trim()`, whose default
     * charlist also removes NUL and vertical-tab bytes and would turn a
     * line that is only valid JSON once they are gone into accepted
     * input. A line left holding nothing but spaces and tabs is a blank
     * between messages; anything else reaches the codec, which rejects it.
     *
     * @param resource $output
     */
    private function accept(MessageHandler $handler, $output, string $payload): void
    {
        $payload = rtrim($payload, "\r\n");

        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            $this->writeFrame($output, JsonRpcCodec::errorEnvelope(null, JsonRpcException::parseError()));

            return;
        }

        if (trim($payload, " \t") === '') {
            return;
        }

        $decoded = JsonRpcCodec::decode($payload);

        if (array_key_exists('errorResponse', $decoded)) {
            $this->writeFrame($output, $decoded['errorResponse']);

            return;
        }

        if (array_key_exists('ignored', $decoded)) {
            return;
        }

        $this->dispatch($handler, $output, $decoded['message']);
    }

    /**
     * A notification write is never allowed to throw out of the emit
     * closure: it runs on the tool's own call stack, so an exception there
     * would reach the consumer as an ordinary tool failure, be reported as
     * a normal result, and that result would then be written into the very
     * stream the failed write already left in a partial state. So a
     * failure is stashed instead, every later notification is skipped
     * without touching the stream again, and the stash is re-thrown once
     * the handler returns — before any final response is attempted.
     *
     * @param resource $output
     * @param array<string, mixed> $message
     */
    private function dispatch(MessageHandler $handler, $output, array $message): void
    {
        $writeFailure = null;

        $emit = function (array $notification) use ($output, &$writeFailure): void {
            if ($writeFailure !== null) {
                return;
            }

            try {
                $this->writeFrame($output, $notification);
            } catch (StdioWriteException $e) {
                $writeFailure = $e;
            }
        };

        $response = $handler->handle($message, $emit);

        if ($writeFailure !== null) {
            throw $writeFailure;
        }

        if ($response !== null) {
            $this->writeFrame($output, $response);
        }
    }

    /**
     * Writes one complete frame — the encoded message plus its framing
     * newline — looping until every byte has been written. `fwrite()` may
     * accept fewer bytes than it was given, and one unchecked call can
     * leave a truncated line that corrupts every message after it.
     *
     * A return of 0 is terminal rather than retried: these streams are
     * blocking, so it means the stream can no longer accept data at all,
     * most often a reader that has gone away. Retrying would spin against
     * a stream that will never report progress again.
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
