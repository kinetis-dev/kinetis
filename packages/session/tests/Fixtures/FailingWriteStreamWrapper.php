<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

/**
 * A stream wrapper simulating file_put_contents() returning false *after*
 * genuinely persisting some bytes to disk — the "the disk fills up
 * mid-write" scenario file_put_contents()'s own documentation describes,
 * which is otherwise impractical to reproduce portably (root inside the
 * test container bypasses every permission-based trick, and there's no
 * portable way to induce a real ENOSPC on demand). Every path under this
 * wrapper's scheme is translated onto a real backing directory, so both
 * the constructor's is_dir() check and the resulting partial file are
 * genuinely inspectable with plain filesystem functions afterward.
 *
 * @internal test fixture only
 */
final class FailingWriteStreamWrapper
{
    public const string SCHEME = 'kinetis-test-failwrite';

    public static string $backingDirectory = '';

    /** @var resource|null PHP sets this itself; declared to avoid the dynamic-property deprecation. */
    public $context;

    /** @var resource|null */
    private $handle;

    private bool $wroteFirstChunk = false;

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
        if ($this->wroteFirstChunk) {
            // The short write file_put_contents() treats as failure —
            // simulating running out of disk space partway through.
            return 0;
        }

        $this->wroteFirstChunk = true;
        $partial = \substr($data, 0, \min(8, \strlen($data)));
        $written = $this->handle === null ? false : \fwrite($this->handle, $partial);

        return $written === false ? 0 : $written;
    }

    public function stream_close(): void
    {
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
        return @\unlink($this->realPath($path));
    }

    /**
     * The bare scheme root (no suffix at all) always resolves to the real
     * backing directory itself — so FileSessionStore's own is_dir()
     * constructor check sees an existing directory and never tries to
     * mkdir() it.
     */
    private function realPath(string $path): string
    {
        $suffix = \ltrim(\substr($path, \strlen(self::SCHEME . '://')), '/');

        return $suffix === '' ? self::$backingDirectory : self::$backingDirectory . '/' . $suffix;
    }
}
