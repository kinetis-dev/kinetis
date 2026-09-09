<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Post;
use Kinetis\Validation\Absent;
use Kinetis\Validation\Constraints\FileExtension;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Every shape a route can bind an uploaded file in: presence variants
 * on a #[Body] DTO field, a repeated file control, a nested DTO whose
 * text and file fields arrive in different trees, an element DTO of a
 * list, and a direct parameter carrying its own rule.
 *
 * Each handler reports client filenames only. Nothing here opens a
 * stream, so a test may bind a file whose stream would throw.
 */
final readonly class UploadBindingController
{
    #[Post('/avatars/presence')]
    public function presence(#[Body] AvatarPresenceRequest $data): array
    {
        return [
            'avatar' => $data->avatar?->getClientFilename(),
            'thumbnail' => $data->thumbnail instanceof Absent ? 'absent' : $data->thumbnail->getClientFilename(),
        ];
    }

    #[Post('/photos')]
    public function photos(#[Body] PhotoUploadRequest $data): array
    {
        return ['photos' => array_map(static fn (UploadedFileInterface $p): ?string => $p->getClientFilename(), $data->photos)];
    }

    #[Post('/profiles')]
    public function profile(#[Body] ProfileUploadRequest $data): array
    {
        return ['name' => $data->profile->name, 'avatar' => $data->profile->avatar->getClientFilename()];
    }

    #[Post('/galleries')]
    public function gallery(#[Body] GalleryUploadRequest $data): array
    {
        return ['entries' => array_map(
            static fn (GalleryEntry $e): array => ['caption' => $e->caption, 'image' => $e->image?->getClientFilename()],
            $data->entries,
        )];
    }

    #[Post('/scans')]
    public function scan(#[FileExtension(['png'])] UploadedFileInterface $file): array
    {
        return ['filename' => $file->getClientFilename()];
    }

    #[Post('/thumbnails')]
    public function thumbnail(?UploadedFileInterface $file = null): array
    {
        return ['filename' => $file?->getClientFilename()];
    }
}
