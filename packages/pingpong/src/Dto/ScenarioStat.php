<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class ScenarioStat
{
    public function __construct(
        public int $count,
        public float $percentage,
    ) {}
}
