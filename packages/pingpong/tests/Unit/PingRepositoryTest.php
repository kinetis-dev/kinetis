<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Events\ActionEvent;
use App\Repositories\PingRepository;
use App\Tests\Fixtures\CannedMysqlLink;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The repository's own SQL and its event side effects, without a
 * database. The connection is substituted rather than the repository
 * doubled, so what runs is the repository's real queries — the
 * statements it builds are what these assert on.
 *
 * The database-backed behaviour is covered separately by
 * App\Tests\Http\PingControllerTest, which needs the compose stack.
 */
final class PingRepositoryTest extends TestCase
{
    private function repository(CannedMysqlLink $link): PingRepository
    {
        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, new EventListenerRegistry());
        $app->boot();

        $scope = $app->createRequestScope();

        return new PingRepository($link, new EventDispatcher(
            $scope,
            $scope->get(EventListenerRegistry::class),
            $scope->get(ListenerInvokerInterface::class),
        ));
    }

    public function test_creating_a_ping_inserts_it_as_pending_and_returns_its_id(): void
    {
        $link = new CannedMysqlLink(insertId: 42);

        $id = $this->repository($link)->create('direct');

        self::assertSame(42, $id);
        self::assertCount(1, $link->statements);
        [$sql, $params] = $link->statements[0];
        self::assertStringContainsString('INSERT INTO', $sql);
        self::assertStringContainsString('ping_messages', $sql);
        self::assertContains('direct', $params);
        self::assertContains('pending', $params);
    }

    public function test_marking_a_ping_ponged_updates_that_row_only(): void
    {
        $link = new CannedMysqlLink();

        $this->repository($link)->markPonged(7);

        [$sql, $params] = $link->statements[0];
        self::assertStringContainsString('UPDATE', $sql);
        self::assertStringContainsString('WHERE', $sql);
        self::assertContains('ponged', $params);
    }

    /**
     * One total plus one count per scenario — a scenario dropped from the
     * list stops being reported without anything else changing.
     */
    public function test_counting_by_scenario_asks_for_the_total_and_each_scenario(): void
    {
        $link = new CannedMysqlLink([['aggregate' => 5]]);

        $counts = $this->repository($link)->countByScenario();

        self::assertSame(5, $counts->total);
        self::assertSame(['direct' => 5, 'queued' => 5, 'cron' => 5], $counts->counts);
        self::assertCount(4, $link->statements);
    }
}
