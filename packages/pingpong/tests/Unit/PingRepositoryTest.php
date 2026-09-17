<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\PingRepository;
use App\Tests\Fixtures\CannedMysqlLink;
use App\Tests\Fixtures\CannedOrm;
use App\Tests\Fixtures\RecordingActionListener;
use Kinetis\Container\AppScope;
use Kinetis\Events\EventDispatcher;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Events\ListenerInvokerInterface;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The repository's own unit of work and its event side effects, without a
 * database. The connection under a real EntityManager is substituted
 * rather than the repository doubled, so what runs is the ORM's real
 * mapping, statements and transaction — they are what these assert on.
 *
 * The database-backed behaviour is covered separately by
 * App\Tests\Http\PingControllerTest, which needs a real MySQL.
 */
final class PingRepositoryTest extends TestCase
{
    private RecordingActionListener $listener;

    private function repository(CannedMysqlLink $link): PingRepository
    {
        $this->listener = new RecordingActionListener();

        $registry = new EventListenerRegistry();
        $registry->register(RecordingActionListener::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $registry);
        $app->instance(RecordingActionListener::class, $this->listener);
        $app->boot();

        $scope = $app->createRequestScope();

        return new PingRepository(CannedOrm::manager($link), new EventDispatcher(
            $scope,
            $scope->get(EventListenerRegistry::class),
            $scope->get(ListenerInvokerInterface::class),
        ));
    }

    /**
     * The `db` stage the dashboard draws claims the row is in the
     * database, so it may only be announced once the flush has committed
     * — and with the id that INSERT generated.
     */
    public function test_creating_a_ping_commits_before_announcing_the_db_stage(): void
    {
        $link = new CannedMysqlLink(insertId: 42);
        $endsWhenAnnounced = null;
        $repository = $this->repository($link);
        $this->listener->onEvent = static function () use ($link, &$endsWhenAnnounced): void {
            $endsWhenAnnounced = $link->ends;
        };

        $id = $repository->create('direct');

        self::assertSame(42, $id);
        self::assertSame([['stage' => 'db', 'id' => 42]], $this->listener->events);
        self::assertSame(['commit'], $endsWhenAnnounced, 'the db stage was announced before COMMIT returned');
    }

    /**
     * One INSERT, of a pending ping, with a UTC timestamp to the
     * microsecond — the precision the DATETIME(6) columns hold and the
     * reason the migration widened them.
     */
    public function test_a_created_ping_is_inserted_pending_and_timestamped_in_utc(): void
    {
        $link = new CannedMysqlLink(insertId: 7);

        $this->repository($link)->create('queued');

        self::assertCount(1, $link->statements);
        [$sql, $params] = $link->statements[0];
        self::assertSame(
            'INSERT INTO `ping_messages` (`status`, `created_at`, `ponged_at`, `scenario`) VALUES (?, ?, ?, ?)',
            $sql,
            'the generated id is left to MySQL, so the INSERT names every other column',
        );
        self::assertSame('pending', $params[0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) $params[1]);
        self::assertNull($params[2], 'a new ping has not been ponged');
        self::assertSame('queued', $params[3]);
    }

    /**
     * A COMMIT whose outcome nobody knows may or may not have stored the
     * row, so announcing it would tell the dashboard something that is
     * not established. Nothing is replayed either — that is what could
     * store it twice.
     */
    public function test_an_unknown_commit_outcome_announces_nothing(): void
    {
        $link = new CannedMysqlLink(insertId: 42);
        $link->onCommit = static fn (): never => throw new RuntimeException('connection lost');

        $this->expectException(UnknownFlushOutcomeException::class);

        try {
            $this->repository($link)->create('direct');
        } finally {
            self::assertSame([], $this->listener->events);
        }
    }

    public function test_a_failed_insert_rolls_back_and_announces_nothing(): void
    {
        $link = new CannedMysqlLink(insertId: 42);
        $link->onStatement = static fn (): never => throw new RuntimeException('duplicate key');
        $repository = $this->repository($link);

        try {
            $repository->create('direct');
            self::fail('the flush was expected to fail');
        } catch (Throwable $failure) {
            self::assertSame('duplicate key', $failure->getMessage());
        }

        self::assertSame(['rollback'], $link->ends);
        self::assertSame([], $this->listener->events);
    }

    /**
     * The ping is loaded, changed as an object, and written by the flush
     * — one UPDATE of the row the id names, committed before the method
     * returns.
     */
    public function test_marking_a_ping_ponged_loads_it_and_writes_the_pong(): void
    {
        $link = new CannedMysqlLink([CannedOrm::pendingRow(7, 'queued')]);

        $this->repository($link)->markPonged(7);

        self::assertCount(2, $link->statements, 'expected one load and one update');
        self::assertStringContainsString('SELECT', $link->statements[0][0]);
        self::assertStringContainsString('`ping_messages` WHERE `id` = 7', $link->statements[0][0]);

        [$sql, $params] = $link->statements[1];
        self::assertStringContainsString('UPDATE `ping_messages` SET', $sql);
        self::assertStringContainsString('WHERE `id` = ?', $sql);
        self::assertSame('ponged', $params[0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/', (string) $params[1]);
        self::assertSame(7, $params[2]);
        self::assertSame(['commit'], $link->ends);
    }

    /**
     * One total plus one count per scenario, in turn on the request's own
     * manager — a manager belongs to the Fiber that opened it, so these
     * cannot be spread over concurrent Fibers. A scenario dropped from
     * the list stops being reported without anything else changing.
     */
    public function test_counting_by_scenario_asks_for_the_total_then_each_scenario(): void
    {
        $link = new CannedMysqlLink([['aggregate' => 5]]);

        $counts = $this->repository($link)->countByScenario();

        self::assertSame(5, $counts->total);
        self::assertSame(['direct' => 5, 'queued' => 5, 'cron' => 5], $counts->counts);
        self::assertCount(4, $link->statements);
        self::assertSame([[], ['direct'], ['queued'], ['cron']], array_column($link->statements, 1));
        self::assertSame([], $link->ends, 'counting writes nothing, so it opens no transaction');
    }
}
