<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PingScenarioBreakdown
{
    /**
     * @param array<string, ScenarioStat> $byScenario
     */
    public function __construct(
        public int $total,
        public array $byScenario,
    ) {}
}
