<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Psr\Http\Message\UploadedFileInterface;

final readonly class AvatarUploadRequest
{
    public function __construct(
        public string $name,
        public UploadedFileInterface $avatar,
    ) {}
}
