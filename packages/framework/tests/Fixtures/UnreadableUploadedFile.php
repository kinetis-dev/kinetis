<?php

declare(strict_types=1);

namespace Kinetis\Tests\Fixtures;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * An uploaded file that answers metadata and refuses content.
 *
 * Every operation that would reach the bytes — getStream() and
 * moveTo() — throws, so a test binding one of these proves that no
 * framework code path read the file: metadata validation that touched
 * content would fail with this exception rather than with a violation.
 * It is also the only way to express what Nyholm's own UploadedFile
 * cannot: a null size, and a PSR-7 error status the supported multipart
 * parser never produces.
 */
final readonly class UnreadableUploadedFile implements UploadedFileInterface
{
    public function __construct(
        private int $error = UPLOAD_ERR_OK,
        private ?int $size = null,
        private ?string $clientFilename = null,
        private ?string $clientMediaType = null,
    ) {}

    #[\Override]
    public function getStream(): StreamInterface
    {
        throw new RuntimeException('This uploaded file must never be opened.');
    }

    #[\Override]
    public function moveTo(string $targetPath): void
    {
        throw new RuntimeException('This uploaded file must never be moved.');
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->size;
    }

    #[\Override]
    public function getError(): int
    {
        return $this->error;
    }

    #[\Override]
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    #[\Override]
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }
}
