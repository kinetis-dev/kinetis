<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\FileMediaType;
use Kinetis\Validation\Each;
use Kinetis\Validation\ListOf;
use Psr\Http\Message\UploadedFileInterface;

final readonly class ListOfMediaTypedUploadsRequest
{
    /**
     * @param list<UploadedFileInterface> $scans
     */
    public function __construct(
        #[ListOf(UploadedFileInterface::class)]
        #[Each(FileMediaType::class, ['application/pdf'])]
        public array $scans,
    ) {}
}
