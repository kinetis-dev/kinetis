<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Dto\ScenarioCounts;
use App\Entities\Ping;
use App\Events\ActionEvent;
use Kinetis\Events\EventDispatcher;
use Kinetis\Orm\EntityManager;

/**
 * Every ping this unit of work touches, through the EntityManager
 * kinetis/database-bridge opens for the request, job, MCP message or
 * command that resolved it. The manager is request-scoped, so this class
 * is too, and its identity map never outlives the unit of work that
 * asked for it.
 */
final readonly class PingRepository
{
    private const array SCENARIOS = ['direct', 'queued', 'cron'];

    public function __construct(
        private EntityManager $entities,
        private EventDispatcher $events,
    ) {}

    /**
     * Flushes before announcing anything: the id is MySQL's, assigned by
     * the INSERT, and the `db` stage the dashboard draws means the row is
     * committed. A flush that fails, or whose outcome is unknown, throws
     * here and announces nothing.
     */
    public function create(string $scenario): int
    {
        $ping = new Ping($scenario);

        $this->entities->persist($ping);
        $this->entities->flush();

        $id = $ping->id();
        $this->events->dispatch(new ActionEvent('db', $id));

        return $id;
    }

    /**
     * The caller always passes an id a flush committed, so a missing row
     * is a broken assumption rather than a race to retry: findOrFail()
     * throws instead of silently updating nothing.
     */
    public function markPonged(int $id): void
    {
        $ping = $this->entities->repository(Ping::class)->findOrFail($id);
        $ping->pong();

        $this->entities->flush();
    }

    /**
     * Four counts, one after another: a manager belongs to the Fiber that
     * opened it, so they cannot be spread over concurrent Fibers.
     */
    public function countByScenario(): ScenarioCounts
    {
        $pings = $this->entities->repository(Ping::class);
        $total = $pings->query()->count();
        $counts = [];

        foreach (self::SCENARIOS as $scenario) {
            $counts[$scenario] = $pings->query()->where('scenario', '=', $scenario)->count();
        }

        return new ScenarioCounts($total, $counts);
    }
}
