<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\ScenarioCounts;
use App\Events\ActionEvent;
use Amp\Mysql\MysqlConnectionPool;
use Kinetis\Events\EventDispatcher;
use Kinetis\QueryBuilder\Query;

final readonly class PingRepository
{
    private const array SCENARIOS = ['direct', 'queued', 'cron'];

    public function __construct(
        private MysqlConnectionPool $db,
        private EventDispatcher $events,
    ) {}

    public function create(string $scenario): int
    {
        $id = new Query($this->db)->table('ping_messages')->insertGetId([
            'scenario' => $scenario,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $id;

        $this->events->dispatch(new ActionEvent('db', $id));

        return $id;
    }

    public function markPonged(int $id): void
    {
        new Query($this->db)->table('ping_messages')->where('id', '=', $id)->update([
            'status' => 'ponged',
            'ponged_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function countByScenario(): ScenarioCounts
    {
        $total = new Query($this->db)->table('ping_messages')->count();
        $counts = [];

        foreach (self::SCENARIOS as $scenario) {
            $counts[$scenario] = new Query($this->db)->table('ping_messages')->where('scenario', '=', $scenario)->count();
        }

        return new ScenarioCounts($total, $counts);
    }
}
