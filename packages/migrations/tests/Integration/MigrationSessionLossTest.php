<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Integration;

use Kinetis\Migrations\MigrationRunner;
use Kinetis\Migrations\SqlMigrationRepository;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\SqlConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * A run whose session goes, against a real MySQL. The advisory lock lives
 * in the session a run holds from start to finish, so a client that
 * opened a replacement would carry on marking and running migrations with
 * nothing holding a concurrent deploy off. The single-session client
 * SqlConnectionFactory::singleSession() builds closes instead, and the
 * run stops where its session did.
 *
 * The first migration abandons a transaction — begun and dropped, so its
 * destructor hands the session back with no ROLLBACK on the wire, which
 * is what a migration can do to lose the session without failing on its
 * own account.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository.
 */
final class MigrationSessionLossTest extends TestCase
{
    private const string LOSES_THE_SESSION = '20260101000000_lose_the_session';

    private const string WOULD_RUN_NEXT = '20260101000001_create_widgets_after_the_loss';

    private string $migrationsPath;

    protected function setUp(): void
    {
        // Ahead of the fixture files, so a skipped run writes nothing.
        $link = self::client();
        $link->execute('DROP TABLE IF EXISTS widgets_after_the_loss');
        $link->execute('DROP TABLE IF EXISTS kinetis_migrations');
        $link->close();

        $this->migrationsPath = \sys_get_temp_dir() . '/kinetis-migrations-session-loss-' . \bin2hex(\random_bytes(8));
        \mkdir($this->migrationsPath);

        \file_put_contents($this->migrationsPath . '/' . self::LOSES_THE_SESSION . '.php', <<<'PHP'
            <?php

            use Kinetis\Persistence\Contract\MysqlLink;
            use Kinetis\Persistence\Contract\PostgresLink;
            use Kinetis\Migrations\Migration;

            return new class implements Migration {
                public function up(MysqlLink|PostgresLink $db): void
                {
                    // Dropped where it is begun: the destructor gives the
                    // session up rather than sending a ROLLBACK nobody
                    // would read.
                    $db->beginTransaction();
                }

                public function down(MysqlLink|PostgresLink $db): void {}
            };
            PHP);

        \file_put_contents($this->migrationsPath . '/' . self::WOULD_RUN_NEXT . '.php', <<<'PHP'
            <?php

            use Kinetis\Persistence\Contract\MysqlLink;
            use Kinetis\Persistence\Contract\PostgresLink;
            use Kinetis\Migrations\Migration;

            return new class implements Migration {
                public function up(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('CREATE TABLE widgets_after_the_loss (id INT PRIMARY KEY)');
                }

                public function down(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('DROP TABLE widgets_after_the_loss');
                }
            };
            PHP);
    }

    protected function tearDown(): void
    {
        if (!isset($this->migrationsPath)) {
            // setUp() skipped before it wrote anything.
            return;
        }

        foreach (\glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->migrationsPath);
    }

    public function test_a_run_that_loses_its_locked_session_cannot_go_on(): void
    {
        $db = SqlConnectionFactory::singleSession(self::definition());
        $runner = new MigrationRunner($db, new SqlMigrationRepository($db), $this->migrationsPath);

        try {
            $runner->migrate();
            self::fail('Expected the run to fail with the session it holds the lock in.');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('one database session', $e->getMessage());
        }

        // Neither migration was marked, and the one after the loss never
        // ran: both would have needed a replacement session, and there
        // is none.
        $link = self::client();
        self::assertSame([], new SqlMigrationRepository($link)->applied());
        self::assertSame([], \iterator_to_array($link->query("SHOW TABLES LIKE 'widgets_after_the_loss'")));
        $link->close();
    }

    /** The MySQL server the CI services publish through MYSQL_*. */
    private static function definition(): ConnectionDefinition
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend migration tests are environment-gated.');
        }

        return new ConnectionDefinition(
            dialect: 'mysql',
            host: $host,
            database: \getenv('MYSQL_DATABASE') ?: 'testdb',
            user: \getenv('MYSQL_USER') ?: 'testuser',
            password: \getenv('MYSQL_PASSWORD') ?: 'testpass',
            port: (int) (\getenv('MYSQL_PORT') ?: 3306),
        );
    }

    /** A plain client for the assertions, which run after the migration client is gone. */
    private static function client(): SqlLink
    {
        $definition = self::definition();

        return new PdoMysqlClient(
            $definition->host,
            $definition->user,
            $definition->password,
            $definition->database,
            $definition->port,
        );
    }
}
