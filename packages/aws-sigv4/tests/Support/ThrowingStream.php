<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A seekable stream whose reads always throw — a body-read failure
 * distinct from the non-seekable case. The failure message is a
 * sentinel: nothing this package raises may carry it.
 */
final class ThrowingStream implements StreamInterface
{
    public const string FAILURE_MESSAGE = 'STREAM-FAILURE-SENTINEL';

    #[\Override]
    public function __toString(): string
    {
        return '';
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
        return null;
    }

    #[\Override]
    public function tell(): int
    {
        return 0;
    }

    #[\Override]
    public function eof(): bool
    {
        return false;
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void {}

    #[\Override]
    public function rewind(): void {}

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
        throw new RuntimeException(self::FAILURE_MESSAGE);
    }

    #[\Override]
    public function getContents(): string
    {
        throw new RuntimeException(self::FAILURE_MESSAGE);
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
