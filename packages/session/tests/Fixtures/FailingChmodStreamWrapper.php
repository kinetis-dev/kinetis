<?php

declare(strict_types=1);

namespace Kinetis\Session\Tests\Fixtures;

/**
 * A stream wrapper behaving like a normal filesystem — every path is
 * translated onto a real backing directory and genuinely persisted
 * there — except its chmod() (STREAM_META_ACCESS) support is
 * deliberately configurable. Proves FileSessionStore::write() treats
 * both a failing chmod() call and a chmod() that reports success
 * without the file's real, resulting mode actually matching as write
 * failures, with cleanup, rather than publishing a temp file whose
 * private permissions were never genuinely verified.
 *
 * @internal test fixture only
 */
final class FailingChmodStreamWrapper
{
    public const string SCHEME = 'kinetis-test-failchmod';

    public static string $backingDirectory = '';

    /** When true, the chmod() call itself reports failure. */
    public static bool $failChmodCall = false;

    /**
     * When true, stat()/fileperms() against the temp file reports
     * failure after a chmod() call that otherwise reported success —
     * the "stat failure" half of "treat chmod/stat failures as write
     * failures."
     */
    public static bool $failStatAfterChmod = false;

    /**
     * When set, stat()/fileperms() against the temp file always reports
     * this mode instead of whatever chmod() actually applied —
     * simulating a chmod() that reports success without the file's real
     * permissions ever actually becoming private.
     */
    public static ?int $reportedModeAfterChmod = null;

    /** @var resource|null PHP sets this itself; declared to avoid the dynamic-property deprecation. */
    public $context;

    /** @var resource|null */
    private $handle;

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
        $written = $this->handle === null ? false : \fwrite($this->handle, $data);

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
        if (self::$failStatAfterChmod) {
            return false;
        }

        $stat = @\stat($this->realPath($path)) ?: false;

        if ($stat !== false && self::$reportedModeAfterChmod !== null) {
            // Overwrite only the permission bits, keeping the real
            // S_IFREG/S_IFDIR type bits intact — a real stat() always
            // reports both together, even though FileSessionStore's own
            // check only ever masks the permission bits out with & 0777.
            $typeBits = $stat['mode'] & ~0777;
            $mode = $typeBits | self::$reportedModeAfterChmod;
            $stat['mode'] = $mode;
            $stat[2] = $mode;
        }

        return $stat;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        if ($option !== \STREAM_META_ACCESS) {
            return false;
        }

        if (self::$failChmodCall) {
            return false;
        }

        \assert(\is_int($value));

        return @\chmod($this->realPath($path), $value);
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
