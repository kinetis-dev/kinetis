<?php

declare(strict_types=1);

namespace Kinetis\Http\Middleware\Support;

use Kinetis\Http\Middleware\Exception\BodyTooLargeException;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Wraps a real request-body stream and enforces a byte cap against what's
 * actually read, regardless of what — or whether — Content-Length
 * claimed. read() and getContents() throw BodyTooLargeException once the
 * running total crosses $maxBytes; every other method delegates to the
 * wrapped stream unchanged.
 *
 * getContents() and read() share one running count — getContents() loops
 * through this class's own read() rather than delegating straight to the
 * wrapped stream's getContents(), which is what makes both paths
 * enforce the same cap. __toString() can't throw (the interface it
 * implements forbids it, to stay safe as a plain PHP string cast), so it
 * reports an empty string instead once the cap is exceeded — callers
 * that need the cap enforced call getContents() directly, which
 * Kinetis's own Dispatcher does for exactly this reason.
 */
final class SizeLimitedStream implements StreamInterface
{
    private int $bytesRead = 0;

    public function __construct(
        private readonly StreamInterface $wrapped,
        private readonly int $maxBytes,
    ) {}

    #[\Override]
    public function read(int $length): string
    {
        $chunk = $this->wrapped->read($length);
        $this->bytesRead += strlen($chunk);

        if ($this->bytesRead > $this->maxBytes) {
            throw BodyTooLargeException::exceeds($this->maxBytes);
        }

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    #[\Override]
    public function close(): void
    {
        $this->wrapped->close();
    }

    #[\Override]
    public function detach()
    {
        return $this->wrapped->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->wrapped->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->wrapped->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->wrapped->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->wrapped->isSeekable();
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->wrapped->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->wrapped->rewind();
        $this->bytesRead = 0;
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->wrapped->isWritable();
    }

    #[\Override]
    public function write(string $string): int
    {
        return $this->wrapped->write($string);
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->wrapped->isReadable();
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $this->wrapped->getMetadata($key);
    }
}
