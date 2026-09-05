<?php

declare(strict_types=1);

namespace Kinetis\Http\Form;

use Closure;
use Kinetis\Http\Form\Exception\FormStagingException;
use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Reads a request body into a replayable temporary stream, bounded, once,
 * before a handler runs.
 *
 * A request body arrives as a stream that may be read only once, may
 * report no size, and may be longer than it said it was. The only way to
 * hold every one of those to a byte ceiling is to read it — so this
 * reads it here, in front of the handler, counting as it goes, and hands
 * on a stream that is seekable, sized, and known to be complete.
 *
 * **Why not a wrapper that counts while the handler reads.** A stream
 * that enforces its cap by throwing can only throw where the caller
 * looks: `read()` and `getContents()` can, and `__toString()` cannot —
 * `Stringable` forbids it — so a string cast has to answer something,
 * and the only things it can answer are a lie or an empty string. An
 * empty string is the dangerous one: a handler, or any vendor middleware
 * between here and it, that casts the body reads an oversized request as
 * an absent optional body and carries on. There is no cast-safe wrapper;
 * the ceiling has to be settled before the handler is called, which is
 * what this does. Afterwards `read()`, `getContents()` and a string cast
 * all return the identical accepted bytes, because there is nothing left
 * to enforce.
 *
 * A body past the ceiling is a {@see BodyTooLargeException} — the
 * client's, answered with a `413`. A temporary stream that will not open,
 * a read that stalls, a write that stops short — those are this worker's,
 * and are a {@see FormStagingException}: the same request would succeed
 * on a healthy worker. The distinction is the whole reason both exist.
 */
final class StagedRequestBody
{
    /** Bytes per read. Large enough not to loop needlessly, small enough not to overshoot the ceiling by much. */
    private const int CHUNK_BYTES = 65_536;

    /**
     * Reads $body to its end, or to one byte past $maxBytes — whichever
     * comes first — and answers with a rewound, seekable stream holding
     * exactly what was accepted.
     *
     * The read stops the moment the running total passes the ceiling, so
     * an endless body costs one chunk more than the limit rather than
     * everything. The declared length is checked first and separately:
     * a request that honestly labels itself oversized is refused without
     * being read at all.
     *
     * @param ?Closure(): (resource|false) $openStream a seam for tests,
     *     which need a temporary stream that short-writes, refuses to
     *     write, or fails to close on demand — none of which
     *     `php://temp` can be made to do
     */
    public static function stage(StreamInterface $body, FormLimits $limits, ?int $declaredBytes, ?Closure $openStream = null): StreamInterface
    {
        $limits->assertBodyWithinLimit(0, $declaredBytes);

        $stream = ($openStream ?? static fn (): mixed => fopen('php://temp', 'r+'))();

        if (!is_resource($stream)) {
            throw FormStagingException::couldNotOpenTempStream();
        }

        $failure = null;
        $staged = null;

        try {
            $staged = self::copy($body, $stream, $limits);
        } catch (Throwable $e) {
            $failure = $e;
        }

        if ($failure !== null) {
            // The primary failure wins, and the handle goes either way:
            // a close that also fails says nothing about why the request
            // could not be served.
            self::closeQuietly($stream);

            throw $failure;
        }

        return $staged ?? throw FormStagingException::couldNotOpenTempStream();
    }

    /**
     * @param resource $stream
     */
    private static function copy(StreamInterface $body, $stream, FormLimits $limits): StreamInterface
    {
        if ($body->isSeekable()) {
            // A body already read by something upstream would otherwise
            // stage as its own remainder — a shorter body that looks
            // genuine.
            $body->rewind();
        }

        $total = 0;

        // `eof()` answers about the stream as it stands, and a read moves
        // it on — so the answer is a value with a moment attached, taken
        // fresh after every read rather than asked for twice as if it
        // could not have changed in between.
        $atEnd = $body->eof();

        while (!$atEnd) {
            $chunk = $body->read(self::CHUNK_BYTES);
            $atEnd = $body->eof();

            if ($chunk === '') {
                // An empty read is how the end of a stream is reached at
                // all: `eof()` only becomes true once a read has already
                // hit it, so an empty body reports "not at the end" until
                // something tries. The state after that read is what
                // separates it from a stream that has stopped yielding
                // while still claiming to have more — which will never
                // yield anything, and would spin here forever instead of
                // failing.
                if ($atEnd) {
                    break;
                }

                throw FormStagingException::bodyReadStalled($total);
            }

            $total += strlen($chunk);

            if ($total > $limits->maxBodyBytes) {
                throw BodyTooLargeException::exceeds($limits->maxBodyBytes);
            }

            self::writeAll($stream, $chunk);
        }

        rewind($stream);

        return Stream::create($stream);
    }

    /**
     * @param resource $stream
     */
    private static function writeAll($stream, string $payload): void
    {
        $length = strlen($payload);
        $written = 0;

        while ($written < $length) {
            $chunk = fwrite($stream, substr($payload, $written));

            // Zero as well as false: a stream that accepts nothing while
            // bytes are still outstanding will never accept them, and
            // looping on it would spin forever instead of failing.
            if ($chunk === false || $chunk === 0) {
                throw FormStagingException::bodyWriteFailed($written, $length);
            }

            $written += $chunk;
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
            // caller needs. Reported on its own in the success path,
            // where there is nothing to lose it behind.
        }
    }
}
