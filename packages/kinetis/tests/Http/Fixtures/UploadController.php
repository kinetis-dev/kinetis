<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Psr\Http\Message\UploadedFileInterface;

final readonly class UploadController
{
    #[Post('/avatars')]
    public function upload(#[Body] AvatarUploadRequest $data): array
    {
        return [
            'name' => $data->name,
            'filename' => $data->avatar->getClientFilename(),
            'contents' => (string) $data->avatar->getStream(),
        ];
    }

    #[Post('/files')]
    public function receiveFile(UploadedFileInterface $file): array
    {
        return ['filename' => $file->getClientFilename()];
    }
}
