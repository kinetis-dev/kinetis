<?php

declare(strict_types=1);

namespace Kinetis\Neuron\Tests\Fixtures\Bedrock;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A read-only body answering each read() with at most the next piece, so a
 * test decides where the reads split.
 */
final class ChunkedStream implements StreamInterface
{
    public bool $closed = false;

    public int $bytesRead = 0;

    /** @param list<string> $pieces */
    public function __construct(private array $pieces) {}

    public static function of(string $bytes, int ...$splits): self
    {
        $pieces = [];
        $offset = 0;

        foreach ($splits as $split) {
            $pieces[] = substr($bytes, $offset, $split - $offset);
            $offset = $split;
        }

        $pieces[] = substr($bytes, $offset);

        return new self(array_values(array_filter($pieces, static fn (string $piece): bool => $piece !== '')));
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new RuntimeException('The stream is closed.');
        }

        $piece = array_shift($this->pieces) ?? '';

        if (\strlen($piece) > $length) {
            array_unshift($this->pieces, substr($piece, $length));
            $piece = substr($piece, 0, $length);
        }

        $this->bytesRead += \strlen($piece);

        return $piece;
    }

    public function eof(): bool
    {
        return $this->pieces === [];
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->bytesRead;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getContents(): string
    {
        $contents = '';

        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
