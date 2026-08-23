<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

/**
 * A stream wrapper that behaves like a normal filesystem — every path
 * under its scheme is translated onto a real backing directory and
 * genuinely persisted there — while recording the exact path of every
 * write, so a test can assert on the real filename FileSessionStore's
 * write() chose for its temp file without touching that class's own
 * internals.
 *
 * @internal test fixture only
 */
final class RecordingStreamWrapper
{
    public const string SCHEME = 'kinetis-test-recording';

    public static string $backingDirectory = '';

    /** @var list<string> */
    public static array $writtenPaths = [];

    /** @var resource|null PHP sets this itself; declared to avoid the dynamic-property deprecation. */
    public $context;

    /** @var resource|null */
    private $handle;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (\str_contains($mode, 'w') || \str_contains($mode, 'a') || \str_contains($mode, '+')) {
            self::$writtenPaths[] = $path;
        }

        $handle = \fopen($this->realPath($path), $mode);

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        $written = $this->handle === null ? false : \fwrite($this->handle, $data);

        return $written === false ? 0 : $written;
    }

    public function stream_read(int $count): string|false
    {
        return $this->handle === null ? false : \fread($this->handle, $count);
    }

    public function stream_close(): void
    {
        if ($this->handle !== null) {
            \fclose($this->handle);
        }
    }

    public function stream_eof(): bool
    {
        return $this->handle === null || \feof($this->handle);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return @\stat($this->realPath($path)) ?: false;
    }

    public function rename(string $from, string $to): bool
    {
        return @\rename($this->realPath($from), $this->realPath($to));
    }

    public function unlink(string $path): bool
    {
        return @\unlink($this->realPath($path));
    }

    /** The bare scheme root always resolves to the real backing directory itself. */
    private function realPath(string $path): string
    {
        $suffix = \ltrim(\substr($path, \strlen(self::SCHEME . '://')), '/');

        return $suffix === '' ? self::$backingDirectory : self::$backingDirectory . '/' . $suffix;
    }
}
