<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Psr\Http\Message\UploadedFileInterface;

/**
 * An element DTO whose picture is optional: an entry may be captioned
 * before anything is uploaded for it, so a file bound to the wrong
 * entry would hydrate without a violation.
 */
final readonly class GalleryEntry
{
    public function __construct(
        public string $caption,
        public ?UploadedFileInterface $image = null,
    ) {}
}
