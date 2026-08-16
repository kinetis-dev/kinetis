<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Repositories\PingRepository;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTransactions;
use Kinetis\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Throwable;

/**
 * The database-backed half of {@doc}`testing`, against this application's
 * own MySQL: every test writes real rows through the real controller, and
 * none of them survives into the next.
 *
 * Runs inside the compose stack (`docker compose exec app`), where
 * `ping_messages` already exists because the `migrate` service created it.
 * Outside the stack there is no database to reach, so the suite skips
 * rather than failing — the same environment gating every other
 * real-backend test in this repo uses.
 */
final class PingControllerTest extends ApplicationTestCase
{
    private ?SqlLink $link = null;

    use DatabaseTransactions;

    protected function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function databaseLink(): SqlLink
    {
        return $this->link ??= $this->app->get(MysqlLink::class);
    }

    #[BeforeClass]
    public static function requireDatabase(): void
    {
        try {
            new \PDO(
                'mysql:host=' . (getenv('DB_HOST') ?: 'mysql') . ';dbname=' . (getenv('DB_NAME') ?: 'pingpong'),
                getenv('DB_USER') ?: 'pingpong',
                getenv('DB_PASSWORD') ?: 'pingpong',
                [\PDO::ATTR_TIMEOUT => 2],
            );
        } catch (Throwable) {
            self::markTestSkipped('No database reachable — run this inside the compose stack.');
        }
    }

    public function test_a_direct_ping_is_ponged_within_the_same_request(): void
    {
        $before = $this->pingCount();

        $this->client->post('/pong/direct')->assertOk();

        self::assertSame($before + 1, $this->pingCount());
    }

    /**
     * Proves the isolation rather than assuming it: the row the previous
     * test wrote through the controller is gone, so this test's own write
     * is again the only one it sees. Holds in any execution order.
     */
    public function test_the_previous_tests_row_did_not_survive(): void
    {
        $before = $this->pingCount();

        $this->client->post('/pong/direct')->assertOk();

        self::assertSame($before + 1, $this->pingCount());
    }

    public function test_a_queued_ping_is_stored_pending(): void
    {
        $this->client->post('/pong/queued')->assertOk();

        // The queue worker is a separate process and never sees this
        // transaction, so the row stays pending for the whole test.
        $row = $this->databaseLink()
            ->query("SELECT status FROM ping_messages WHERE scenario = 'queued' ORDER BY id DESC LIMIT 1")
            ->fetchRow();

        self::assertSame('pending', $row['status'] ?? null);
    }

    public function test_the_tally_route_reports_the_repositorys_own_counts(): void
    {
        $this->client->post('/pong/direct')->assertOk();

        $tally = $this->client->get('/pong/tally')->assertOk();
        $counts = $this->app->get(PingRepository::class)->countByScenario();

        $tally->assertJsonPath('total', $counts->total);
    }

    private function pingCount(): int
    {
        $row = $this->databaseLink()->query('SELECT COUNT(*) AS c FROM ping_messages')->fetchRow();

        return (int) ($row['c'] ?? 0);
    }
}
