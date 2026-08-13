<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Body;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\Query;

final readonly class NoteController
{
    #[Post('/notes', status: 201)]
    public function create(#[Body] CreateNoteRequest $data): array
    {
        return ['title' => $data->title, 'subtitle' => $data->subtitle];
    }

    #[Get('/notes/search')]
    public function search(#[Query] string $term): array
    {
        return ['term' => $term];
    }

    #[Get('/notes/filter')]
    public function filter(#[Query] ?string $term): array
    {
        return ['term' => $term];
    }
}
