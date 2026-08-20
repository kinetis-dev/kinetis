<?php

declare(strict_types=1);

namespace Kinetis\AwsSigV4;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

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
 * @internal Constructed only by SigV4SigningClient itself, to replace a
 * request's original body with one guaranteed seekable regardless of
 * what the original was — see its own docblock for why that guarantee
 * matters.
 */
final class SpooledStream implements StreamInterface
{
    /** @var resource */
    private $resource;

    public function __construct(string $contents)
    {
        $resource = fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw new RuntimeException('Could not open a php://temp stream.');
        }

        $this->resource = $resource;

        fwrite($this->resource, $contents);
        rewind($this->resource);
    }

    #[\Override]
    public function __toString(): string
    {
        $this->rewind();

        return $this->getContents();
    }

    #[\Override]
    public function close(): void
    {
        fclose($this->resource);
    }

    /**
     * @return resource
     */
    #[\Override]
    public function detach()
    {
        $resource = $this->resource;
        unset($this->resource);

        return $resource;
    }

    #[\Override]
    public function getSize(): ?int
    {
        $stats = fstat($this->resource);

        return $stats === false ? null : $stats['size'];
    }

    #[\Override]
    public function tell(): int
    {
        $position = ftell($this->resource);

        if ($position === false) {
            throw new RuntimeException('Could not determine the stream position.');
        }

        return $position;
    }

    #[\Override]
    public function eof(): bool
    {
        return feof($this->resource);
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return true;
    }

    #[\Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Could not seek the stream.');
        }
    }

    #[\Override]
    public function rewind(): void
    {
        $this->seek(0);
    }

    #[\Override]
    public function isWritable(): bool
    {
        return true;
    }

    #[\Override]
    public function write(string $string): int
    {
        $written = fwrite($this->resource, $string);

        if ($written === false) {
            throw new RuntimeException('Could not write to the stream.');
        }

        return $written;
    }

    #[\Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function read(int $length): string
    {
        // fread() itself raises a ValueError for a length below 1 on
        // PHP 8, rather than the empty-string StreamInterface::read()
        // documents as the correct response to "nothing was asked for".
        if ($length < 1) {
            return '';
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new RuntimeException('Could not read from the stream.');
        }

        return $data;
    }

    #[\Override]
    public function getContents(): string
    {
        $contents = stream_get_contents($this->resource);

        if ($contents === false) {
            throw new RuntimeException('Could not read the remaining stream contents.');
        }

        return $contents;
    }

    #[\Override]
    public function getMetadata(?string $key = null): mixed
    {
        $metadata = stream_get_meta_data($this->resource);

        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }
}
