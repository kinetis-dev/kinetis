<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;
use Psr\Http\Message\UploadedFileInterface;

final readonly class ListOfUploadedFilesRequest
{
    public function __construct(
        #[ListOf(UploadedFileInterface::class)]
        #[Each(FileExtension::class, ['png'])]
        public array $photos,
    ) {}
}
