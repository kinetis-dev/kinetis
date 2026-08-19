<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Repositories\PingRepository;
use App\Services\SoketiPublisher;
use App\Tests\Fixtures\RecordingQueue;
use Kinetis\Queue\QueueInterface;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Testing\DatabaseTransactions;
use Kinetis\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Pusher\Pusher;
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

    /**
     * Where the database is, which differs by where the suite runs: the
     * compose stack supplies this application's own DB_* values, while
     * CI supplies a shared MySQL under the repository-wide MYSQL_* ones.
     * Returning them as config overrides is what lets the same tests run
     * in both, rather than only inside the stack.
     *
     * @return array<string, string>
     */
    private static function databaseEnv(): array
    {
        $ciHost = getenv('MYSQL_HOST');

        if ($ciHost !== false && $ciHost !== '') {
            return [
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => $ciHost,
                'DB_NAME' => getenv('MYSQL_DATABASE') ?: 'testdb',
                'DB_USER' => getenv('MYSQL_USER') ?: 'testuser',
                'DB_PASSWORD' => getenv('MYSQL_PASSWORD') ?: 'testpass',
                'DB_PORT' => getenv('MYSQL_PORT') ?: '3306',
            ];
        }

        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => getenv('DB_HOST') ?: 'mysql',
            'DB_NAME' => getenv('DB_NAME') ?: 'pingpong',
            'DB_USER' => getenv('DB_USER') ?: 'pingpong',
            'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'pingpong',
            'DB_PORT' => getenv('DB_PORT') ?: '3306',
        ];
    }

    #[\Override]
    protected function configOverrides(): array
    {
        return self::databaseEnv();
    }

    /**
     * The controller announces every stage to Soketi and defers one
     * scenario to a Redis-backed queue, neither of which this suite has
     * any reason to need: these tests are about what reaches the
     * database. Substituting both is what lets them run against a bare
     * MySQL rather than only inside the full stack.
     */
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $app->instance(SoketiPublisher::class, new SoketiPublisher($this->createStub(Pusher::class)));
        // Redis, for the same reason. A queue that holds the job rather
        // than running it is what keeps a queued ping pending, which is
        // what the test below asserts.
        $app->instance(QueueInterface::class, new RecordingQueue());
    }

    #[BeforeClass]
    public static function requireDatabase(): void
    {
        $env = self::databaseEnv();

        try {
            $pdo = new \PDO(
                'mysql:host=' . $env['DB_HOST'] . ';port=' . $env['DB_PORT'] . ';dbname=' . $env['DB_NAME'],
                $env['DB_USER'],
                $env['DB_PASSWORD'],
                [\PDO::ATTR_TIMEOUT => 2, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable) {
            self::markTestSkipped('No database reachable — run this inside the compose stack, or set MYSQL_HOST.');
        }

        // The compose stack's migrate service creates this; anywhere
        // else the suite creates it itself, so it needs no stack.
        $pdo->exec('CREATE TABLE IF NOT EXISTS ping_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            scenario VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            created_at DATETIME NOT NULL,
            ponged_at DATETIME NULL
        )');
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
