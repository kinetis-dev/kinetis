<?php

declare(strict_types=1);

namespace Kinetis\Http\Pagination;

final readonly class CursorPaginator
{
    /**
     * @param list<mixed> $data
     */
    public function __construct(
        public array $data,
        public ?string $nextCursor,
        public bool $hasMore,
    ) {}
}
