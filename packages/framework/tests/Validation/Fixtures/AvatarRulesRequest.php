<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraints\FileExtension;
use Kinetis\Validation\Constraints\FileSize;
use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarRulesRequest
{
    public function __construct(
        #[FileExtension(['png'])]
        #[FileSize(10)]
        public UploadedFileInterface $avatar,
    ) {}
}
