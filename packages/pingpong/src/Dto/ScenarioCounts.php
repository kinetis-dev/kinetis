<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ScenarioCounts
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        public int $total,
        public array $counts,
    ) {}
}
