<?php

declare(strict_types=1);

namespace Kinetis\Tests\Http\Fixtures;

/** An application-owned cursor wrapper whose `data` holds the items. */
final readonly class CursorPage
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
