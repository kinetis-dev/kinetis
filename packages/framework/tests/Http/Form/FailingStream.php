<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Form;

use RuntimeException;

/**
 * A stream wrapper that fails on demand, so
 * {@see \Kinetis\Http\Form\StagedMultipartBody}'s and
 * {@see \Kinetis\Http\Form\StagedRequestBody}'s write, close and read
 * paths can be driven deterministically. `php://temp` cannot be made to
 * short write, refuse a write, fail to close, or hand back nothing while
 * reporting more to come, and those are exactly the failures worth
 * proving: each one, unhandled, produces a truncated body that still
 * parses.
 *
 * Registered under its own scheme by {@see register()} and configured
 * through {@see $chunkSize}/{@see $refuseAfter}/{@see $failOnClose}/
 * {@see $stallReads}, which are static because a stream wrapper is
 * instantiated by PHP itself and takes no arguments.
 */
final class FailingStream
{
    public const string SCHEME = 'kinetis-conformance-failing';

    /** Bytes accepted per write() call — the short-write knob. */
    public static int $chunkSize = PHP_INT_MAX;

    /** Accept this many bytes in total, then accept zero forever. */
    public static int $refuseAfter = PHP_INT_MAX;

    public static bool $failOnClose = false;

    /** Answer every read with nothing while still reporting more to come — the stalled-read knob. */
    public static bool $stallReads = false;

    public static bool $closed = false;

    /** @var resource */
    public $context;

    private string $buffer = '';

    private int $position = 0;

    public static function register(): void
    {
        if (in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_unregister(self::SCHEME);
        }

        stream_wrapper_register(self::SCHEME, self::class);
    }

    public static function reset(): void
    {
        self::$chunkSize = PHP_INT_MAX;
        self::$refuseAfter = PHP_INT_MAX;
        self::$failOnClose = false;
        self::$stallReads = false;
        self::$closed = false;
    }

    /**
     * @return resource|false
     */
    public static function open()
    {
        return fopen(self::SCHEME . '://staged', 'r+');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $remaining = self::$refuseAfter - strlen($this->buffer);

        if ($remaining <= 0) {
            return 0;
        }

        $accepted = min(strlen($data), self::$chunkSize, $remaining);
        $this->buffer .= substr($data, 0, $accepted);

        return $accepted;
    }

    public function stream_read(int $count): string
    {
        if (self::$stallReads) {
            return '';
        }

        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return !self::$stallReads && $this->position >= strlen($this->buffer);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->position = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->buffer) + $offset,
            default => $offset,
        };

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->buffer)];
    }

    public function stream_close(): void
    {
        self::$closed = true;

        if (self::$failOnClose) {
            throw new RuntimeException('the staged stream refused to close');
        }
    }
}
