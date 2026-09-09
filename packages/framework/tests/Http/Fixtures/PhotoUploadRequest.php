<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;
use Psr\Http\Message\UploadedFileInterface;

final readonly class PhotoUploadRequest
{
    public function __construct(
        #[ListOf(UploadedFileInterface::class)]
        #[Each(FileExtension::class, ['png'])]
        public array $photos,
    ) {}
}
