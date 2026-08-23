<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Dto\PingScenarioBreakdown;
use App\Dto\ScenarioStat;
use App\Repositories\PingRepository;
use Kinetis\Mcp\Attributes\McpTool;

final readonly class PingStatsToolController
{
    public function __construct(
        private PingRepository $pings,
    ) {}

    #[McpTool(
        name: 'ping_scenario_breakdown',
        description: 'Reports how many ping messages came from each scenario (direct, queued, cron) and what percentage of the total each represents',
    )]
    public function pingScenarioBreakdown(): PingScenarioBreakdown
    {
        $counts = $this->pings->countByScenario();
        $byScenario = [];

        foreach ($counts->counts as $scenario => $count) {
            $byScenario[$scenario] = new ScenarioStat(
                $count,
                $counts->total > 0 ? round($count / $counts->total * 100, 1) : 0.0,
            );
        }

        return new PingScenarioBreakdown($counts->total, $byScenario);
    }
}
