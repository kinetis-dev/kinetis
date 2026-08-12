<?php

declare(strict_types=1);

namespace Kinetis\Http\Pagination;

final readonly class Paginator
{
    /**
     * @param list<mixed> $data
     */
    public function __construct(
        public array $data,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {}
}
