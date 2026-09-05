<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Closure;
use Kinetis\Http\Form\Exception\FormStagingException;
use Throwable;

/**
 * Copies a `multipart/form-data` body into a temporary stream shaped
 * the way a multipart parser wants it — the `Content-Type` header
 * carrying the boundary, a blank line, then the body, which is one raw
 * HTTP part — and owns that stream for exactly as long as the parse
 * runs.
 *
 * The staging is here, once, rather than in each adapter that parses a
 * body itself, because getting it slightly wrong is invisible: a single
 * `fwrite()` may accept fewer bytes than it was given, and a multipart
 * body missing its tail still parses — into a form that is plausible,
 * shorter than the client sent, and indistinguishable from a genuine
 * one. Every write is therefore looped to completion and a short or
 * failed write is an error, never a shorter form.
 *
 * The stream is closed on every path — a write that failed, a parse
 * that threw, a parse that returned — and whatever failed first is what
 * the caller sees: a failure to close is only worth reporting when
 * nothing else went wrong, and never worth losing a real failure to.
 *
 * A failure here is this worker's, not the client's, so it is a
 * {@see FormStagingException} rather than the `400`/`413` a bad request
 * gets; see that class for where each adapter sends it.
 */
final class StagedMultipartBody
{
    /**
     * @template T
     * @param Closure(resource): T $parse receives the staged, rewound
     *     stream and must not close it
     * @param ?Closure(): (resource|false) $openStream the temporary
     *     stream to stage into; `php://temp` when omitted. A seam for
     *     tests, which need a stream that short-writes, refuses to
     *     write, or fails to close on demand — none of which
     *     `php://temp` can be made to do.
     * @return T
     */
    public static function parse(string $contentType, string $body, Closure $parse, ?Closure $openStream = null): mixed
    {
        $stream = ($openStream ?? static fn (): mixed => fopen('php://temp', 'r+'))();

        if (!is_resource($stream)) {
            throw FormStagingException::couldNotOpenTempStream();
        }

        try {
            self::writeAll($stream, "Content-Type: {$contentType}\r\n\r\n" . $body);
            rewind($stream);

            $result = $parse($stream);
        } catch (Throwable $failure) {
            // The primary failure wins, and the handle goes either way:
            // a close that also fails says nothing about why the body
            // could not be parsed.
            self::closeQuietly($stream);

            throw $failure;
        }

        // Nothing else failed, so a close that does is the one failure
        // left to report. Past it the parser's answer leaves unchanged —
        // `null` included, which is an answer a parser is free to give.
        self::close($stream);

        return $result;
    }

    /**
     * @param resource $stream
     */
    private static function close($stream): void
    {
        try {
            $closed = fclose($stream);
        } catch (Throwable $closeFailure) {
            throw FormStagingException::couldNotCloseTempStream($closeFailure);
        }

        if ($closed === false) {
            throw FormStagingException::couldNotCloseTempStream();
        }
    }

    /**
     * @param resource $stream
     */
    private static function closeQuietly($stream): void
    {
        try {
            fclose($stream);
        } catch (Throwable) {
            // Swallowed on purpose: this only runs when a real failure
            // is already on its way up, and that failure is the one the
            // caller needs. Reported on its own in {@see close()}, where
            // there is nothing to lose it behind.
        }
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $payload): void
    {
        $total = strlen($payload);
        $written = 0;

        while ($written < $total) {
            $chunk = fwrite($stream, substr($payload, $written));

            // Zero as well as false: a stream that accepts nothing while
            // bytes are still outstanding will never accept them, and
            // looping on it would spin forever instead of failing.
            if ($chunk === false || $chunk === 0) {
                throw FormStagingException::bodyWriteFailed($written, $total);
            }

            $written += $chunk;
        }
    }
}
