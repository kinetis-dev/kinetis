<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use Kinetis\AwsSigV4\Exception\StreamException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * A minimal, always-seekable PSR-7 stream over already-in-memory string
 * content — backed by `php://temp` rather than a plain PHP string
 * property, so this stream's own *storage* spills to a real temp file
 * past `php://temp`'s 2MB in-memory threshold instead of holding a
 * second full copy of the body forever, without this package taking on
 * a full PSR-7 implementation as a runtime dependency (only
 * `psr/http-message`'s interfaces are required; no concrete
 * implementation of them is). This does *not* bound the *peak* memory a
 * signed request costs — see SigV4SigningClient's own docblock for what
 * still isn't streamed.
 *
 * Resource ownership is explicit: `$resource` is `null` once `close()`
 * or `detach()` has run, matching StreamInterface's own "the stream is
 * in an unusable state" description of a detached stream. Both are
 * idempotent — a second `close()`/`detach()` call is a safe no-op rather
 * than a native error over an already-closed/absent resource. Every
 * other method checks for `null` first and throws this package's own
 * `StreamException` rather than letting the missing resource surface as
 * a native `TypeError`/`Error`/warning: `getSize()`/`getMetadata()`
 * return `null` (matching StreamInterface's own "or null if unknown"
 * convention for `getSize()`), the three capability methods
 * (`isSeekable()`/`isWritable()`/`isReadable()`) return `false`, and
 * every operationally-meaningful method (`tell()`/`seek()`/`rewind()`/
 * `write()`/`read()`/`getContents()`) throws.
 *
 * @internal Constructed only by SigV4SigningClient itself, to replace a
 * request's original body with one guaranteed seekable regardless of
 * what the original was — see its own docblock for why that guarantee
 * matters.
 */
final class SpooledStream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    public function __construct(string $contents)
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw StreamException::couldNotOpenTempStream();
        }

        // A single fwrite() call may write fewer bytes than given —
        // real PHP behavior, not a hypothetical edge case, more likely
        // under real memory/disk pressure for exactly the kind of large
        // body this class exists to spool without holding a second full
        // copy in memory. Looping until every byte is confirmed written
        // is what makes this stream's own content match $contents
        // exactly; a request signed and sent from a silently truncated
        // body would be a real, security-relevant integrity failure,
        // not just a correctness bug. A write returning false (a
        // genuine I/O error) or 0 (no progress at all — looping forever
        // on that would hang, not eventually succeed) both fail loudly,
        // closing the resource first so this constructor never leaves a
        // leaked, half-written temp stream behind on failure.
        $length = strlen($contents);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($resource, substr($contents, $offset));

            if ($written === false || $written === 0) {
                fclose($resource);

                throw StreamException::couldNotWriteToStream();
            }

            $offset += $written;
        }

        // rewind()'s own boolean result has to be checked too, the same
        // "fail loudly, don't silently accept a half-done operation"
        // discipline as the write loop above: an unchecked failure here
        // would leave construction succeeding with the cursor wherever
        // the last write left it (at EOF, past every byte just written)
        // rather than at the start — SigV4SigningClient's own docblock
        // depends on a freshly constructed SpooledStream genuinely being
        // positioned at zero, so silently violating that would mean
        // signing an empty or partial body while believing otherwise.
        if (!rewind($resource)) {
            fclose($resource);

            throw StreamException::couldNotSeekStream();
        }

        $this->resource = $resource;
    }

    /**
     * StreamInterface explicitly forbids this from ever throwing, which
     * sets it apart from every other method here: no exception this
     * class itself can produce (a detached stream, a genuine I/O
     * failure) is allowed to escape a plain `(string) $stream` cast.
     */
    #[\Override]
    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    #[\Override]
    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    /**
     * @return resource|null
     */
    #[\Override]
    public function detach()
    {
        $resource = $this->resource;
        $this->resource = null;

        return $resource;
    }

    #[\Override]
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        $stats = fstat($this->resource);

        return $stats === false ? null : $stats['size'];
    }

    #[\Override]
    public function tell(): int
    {
        $position = $this->resource === null ? false : ftell($this->resource);

        if ($position === false) {
            throw StreamException::couldNotDetermineStreamPosition();
        }

        return $position;
    }

    #[\Override]
    public function eof(): bool
    {
        // No resource reads the same as "nothing left to read" — the
        // same convention StreamInterface's own reference
        // implementations (Guzzle's Stream among them) already use for
        // a detached stream.
        return $this->resource === null || feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->resource !== null;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->resource === null || fseek($this->resource, $offset, $whence) === -1) {
            throw StreamException::couldNotSeekStream();
        }
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    // Genuinely, independently always true for this stream while it has
    // a resource — the same as isSeekable() above and isReadable()
    // below — not a copy-paste artifact of one of the three.
    #[\Override]
    public function isWritable(): bool
    {
        return $this->resource !== null;
    }

    #[\Override]
    public function write(string $string): int
    {
        if ($this->resource === null) {
            throw StreamException::couldNotWriteToStream();
        }

        $written = fwrite($this->resource, $string);

        if ($written === false) {
            throw StreamException::couldNotWriteToStream();
        }

        return $written;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->resource !== null;
    }

    #[\Override]
    public function read(int $length): string
    {
        // The resource check runs first, ahead of the zero/negative-
        // length short-circuit below — a detached stream must throw
        // regardless of $length, the same as every other operational
        // method here, not silently succeed with an empty string just
        // because the caller happened to ask for zero/negative bytes.
        if ($this->resource === null) {
            throw StreamException::couldNotReadFromStream();
        }

        // fread() itself raises a ValueError for a length below 1 on
        // PHP 8, rather than the empty-string StreamInterface::read()
        // documents as the correct response to "nothing was asked for".
        if ($length < 1) {
            return '';
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw StreamException::couldNotReadFromStream();
        }

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = $this->resource === null ? false : stream_get_contents($this->resource);

        if ($contents === false) {
            throw StreamException::couldNotReadRemainingStreamContents();
        }

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            // The same "null if unknown" convention getSize() already
            // uses — this is informational, not an operational failure,
            // so a detached stream reports "nothing to report" rather
            // than throwing.
            return $key === null ? [] : null;
        }

        $metadata = stream_get_meta_data($this->resource);

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
