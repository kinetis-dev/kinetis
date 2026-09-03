<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Query;

final readonly class TagSearchController
{
    #[Get('/tag-search')]
    public function search(#[Query] array $tags = []): array
    {
        return ['tags' => $tags];
    }
}
