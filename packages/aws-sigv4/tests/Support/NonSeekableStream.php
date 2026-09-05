<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A sequential-read-only PSR-7 stream: isSeekable() reports false and
 * both seek()/rewind() throw, as a real non-seekable implementation's
 * would.
 */
final class NonSeekableStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly string $contents) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->getContents();
    }

    #[\Override]
    public function close(): void {}

    #[\Override]
    public function detach()
    {
        return null;
    }

    #[\Override]
    public function getSize(): ?int
    {
        return strlen($this->contents);
    }

    #[\Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    #[\Override]
    public function rewind(): void
    {
        throw new RuntimeException('This stream is not seekable.');
    }

    #[\Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[\Override]
    public function write(string $string): int
    {
        throw new RuntimeException('This stream is not writable.');
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    #[\Override]
    public function getContents(): string
    {
        $remaining = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $remaining;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
