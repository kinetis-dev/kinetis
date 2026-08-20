<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Fixtures;

/**
 * A stream wrapper controlling exactly how many bytes fwrite() accepts
 * per call — reproducing scenarios real local disk I/O makes impractical
 * to force portably (root inside the test container bypasses every
 * permission-based trick, and there's no portable way to induce a real
 * ENOSPC on demand). Every path under this wrapper's scheme is
 * translated onto a real backing directory, so the resulting file (or
 * its absence, when cleanup is what's under test) is genuinely
 * inspectable with plain filesystem functions afterward.
 *
 * Two modes, chosen per test via the static $mode property:
 *
 * - STALL: real progress on the first call, zero further progress on
 *   every call after — reproducing PHP's own fwrite() returning a
 *   genuine, positive-but-short byte count once it gives up retrying
 *   against a stream that's stopped making progress (the "the disk
 *   fills up mid-write" scenario fwrite()'s own documentation
 *   describes). Proves writeAll() detects a genuine failure and the
 *   caller cleans up the partial file.
 * - CHUNKED: alternates between writing a few bytes of *whatever data it
 *   was actually given* and reporting zero progress, always eventually
 *   completing. The alternation is the load-bearing part: PHP's own
 *   fwrite() already retries internally against a userland stream for as
 *   long as each call keeps succeeding, silently absorbing any run of
 *   always-succeeding stream_write() calls into a single top-level
 *   fwrite() return value — a mode that never stalls would therefore
 *   never actually make writeAll()'s own outer loop iterate more than
 *   once, no matter how small each individual stream_write() chunk is.
 *   Forcing exactly one zero-progress call after every real one is what
 *   makes each top-level fwrite() call return a genuine, real short
 *   count, handing control back to writeAll() and making the exact bytes
 *   passed on *its* next call observable in the final file — which is
 *   what actually exercises whether writeAll() correctly slices the
 *   *remaining* data on each iteration (not the same data over and over)
 *   and correctly accumulates its offset across them.
 *
 * @internal test fixture only
 */
final class FailingWriteStreamWrapper
{
    public const string SCHEME = 'kinetis-test-failwrite';

    public const string MODE_STALL = 'stall';

    public const string MODE_CHUNKED = 'chunked';

    public const int CHUNK_SIZE = 3;

    public static string $backingDirectory = '';

    public static string $mode = self::MODE_STALL;

    /**
     * Which of stream_close()/unlink() ran, in order — PHP invokes
     * unlink() on a *separate* wrapper instance from the one that
     * handled stream_open()/stream_write()/stream_close(), so this has
     * to be static to observe both from one test. Reset per test via
     * self::$eventLog = [].
     *
     * @var list<string>
     */
    public static array $eventLog = [];

    /** @var resource|null PHP sets this itself; declared to avoid the dynamic-property deprecation. */
    public $context;

    /** @var resource|null */
    private $handle;

    private bool $wroteFirstChunk = false;

    private bool $chunkedShouldWrite = true;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $handle = \fopen($this->realPath($path), $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$mode === self::MODE_CHUNKED) {
            $shouldWrite = $this->chunkedShouldWrite;
            $this->chunkedShouldWrite = !$this->chunkedShouldWrite;

            if (!$shouldWrite) {
                return 0;
            }

            $chunk = \substr($data, 0, self::CHUNK_SIZE);
            $written = $this->handle === null ? false : \fwrite($this->handle, $chunk);

            return $written === false ? 0 : $written;
        }

        if ($this->wroteFirstChunk) {
            // No further progress at all -- the disk is now completely full.
            return 0;
        }

        $this->wroteFirstChunk = true;
        $partial = \substr($data, 0, \max(1, \intdiv(\strlen($data), 2)));
        $written = $this->handle === null ? false : \fwrite($this->handle, $partial);

        return $written === false ? 0 : $written;
    }

    public function stream_close(): void
    {
        self::$eventLog[] = 'stream_close';

        if ($this->handle !== null) {
            \fclose($this->handle);
        }
    }

    public function stream_eof(): bool
    {
        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return @\stat($this->realPath($path)) ?: false;
    }

    public function unlink(string $path): bool
    {
        self::$eventLog[] = 'unlink';

        return @\unlink($this->realPath($path));
    }

    /** The bare scheme root always resolves to the real backing directory itself. */
    private function realPath(string $path): string
    {
        $suffix = \ltrim(\substr($path, \strlen(self::SCHEME . '://')), '/');

        return $suffix === '' ? self::$backingDirectory : self::$backingDirectory . '/' . $suffix;
    }
}
