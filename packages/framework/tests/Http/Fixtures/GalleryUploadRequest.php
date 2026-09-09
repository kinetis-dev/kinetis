<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\ListOf;

/**
 * The deepest place an upload can hide from a media-type decision: an
 * element DTO of a #[ListOf] field declares it.
 */
final readonly class GalleryUploadRequest
{
    public function __construct(
        #[ListOf(GalleryEntry::class)]
        public array $entries,
    ) {}
}
