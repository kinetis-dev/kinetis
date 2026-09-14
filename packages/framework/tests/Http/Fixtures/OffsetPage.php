<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/** An application-owned page-number wrapper whose `data` holds the items. */
final readonly class OffsetPage
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
