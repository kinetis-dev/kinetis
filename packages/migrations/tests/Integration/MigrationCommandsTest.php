<?php

declare(strict_types=1);

namespace Kinetis\Migrations\Tests\Integration;

use Kinetis\Console\CommandArguments;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Migrations\Console\MigrateCommand;
use Kinetis\Migrations\Console\RollbackCommand;
use Kinetis\Migrations\Console\StatusCommand;
use Kinetis\Migrations\Tests\Fixtures\MigrationEventRecorder;
use Kinetis\Migrations\Tests\Fixtures\OutputCapture;
use Kinetis\Events\EventListenerRegistry;
use Kinetis\Migrations\Events\MigrationApplied;
use Kinetis\Migrations\Events\MigrationRolledBack;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use PHPUnit\Framework\TestCase;

/**
 * migrate, migrate:status and migrate:rollback against a real MySQL, the
 * way bin/kinetis runs them: `bootstrap: false`, the connection read from
 * the process environment, and the project the Composer bin proxy names.
 * What each prints and the events it dispatches are the contract.
 *
 * Environment-gated on MYSQL_HOST, like every other real-backend test in
 * this repository.
 */
final class MigrationCommandsTest extends TestCase
{
    private const string MIGRATION = '20260101000000_create_bridge_widgets';

    private const array ENVIRONMENT = ['DB_CONNECTION', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'MIGRATE_CONNECTION_NAME'];

    private string $projectRoot;

    private mixed $composerBinDir;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        $host = \getenv('MYSQL_HOST');

        if ($host === false || $host === '') {
            self::markTestSkipped('MYSQL_HOST is not set — real-backend migration tests are environment-gated.');
        }

        foreach (self::ENVIRONMENT as $name) {
            $this->originalEnvironment[$name] = \getenv($name);
        }

        self::setEnvironment([
            'DB_CONNECTION' => 'mysql',
            // The commands pin PDO whatever the deployment selects.
            'DB_DRIVER' => 'native',
            'DB_HOST' => $host,
            'DB_PORT' => \getenv('MYSQL_PORT') ?: '3306',
            'DB_NAME' => \getenv('MYSQL_DATABASE') ?: 'testdb',
            'DB_USER' => \getenv('MYSQL_USER') ?: 'testuser',
            'DB_PASSWORD' => \getenv('MYSQL_PASSWORD') ?: 'testpass',
            'MIGRATE_CONNECTION_NAME' => false,
        ]);

        $link = self::client();
        $link->execute('DROP TABLE IF EXISTS bridge_widgets');
        $link->execute('DROP TABLE IF EXISTS kinetis_migrations');
        $link->close();

        $this->projectRoot = \sys_get_temp_dir() . '/kinetis-bridge-migrations-' . \bin2hex(\random_bytes(8));
        \mkdir($this->projectRoot . '/migrations', recursive: true);
        \mkdir($this->projectRoot . '/vendor/bin', recursive: true);
        $this->composerBinDir = $GLOBALS['_composer_bin_dir'] ?? null;
        $GLOBALS['_composer_bin_dir'] = $this->projectRoot . '/vendor/bin';
    }

    protected function tearDown(): void
    {
        if (!isset($this->projectRoot)) {
            // setUp() skipped before it changed anything.
            return;
        }

        $GLOBALS['_composer_bin_dir'] = $this->composerBinDir;
        self::setEnvironment($this->originalEnvironment);

        foreach (\glob($this->projectRoot . '/migrations/*.php') ?: [] as $file) {
            \unlink($file);
        }

        \rmdir($this->projectRoot . '/migrations');
        \rmdir($this->projectRoot . '/vendor/bin');
        \rmdir($this->projectRoot . '/vendor');
        \rmdir($this->projectRoot);
    }

    public function test_the_commands_print_and_dispatch_what_they_did(): void
    {
        [$scope, $recorder] = self::commandScope();

        self::assertSame([0, "No migrations found.\n"], self::runStatus($scope));
        self::assertSame([0, "Nothing to migrate.\n"], self::runMigrate($scope));
        self::assertSame([0, "Nothing to roll back.\n"], self::runRollback($scope));

        $this->writeMigration();

        self::assertSame([0, '[pending] ' . self::MIGRATION . "\n"], self::runStatus($scope));
        self::assertSame([0, 'Migrated: ' . self::MIGRATION . "\n"], self::runMigrate($scope));
        self::assertSame([0, '[applied] ' . self::MIGRATION . "\n"], self::runStatus($scope));
        self::assertSame([0, "Nothing to migrate.\n"], self::runMigrate($scope));
        self::assertSame([0, 'Rolled back: ' . self::MIGRATION . "\n"], self::runRollback($scope));
        self::assertSame([0, '[pending] ' . self::MIGRATION . "\n"], self::runStatus($scope));

        self::assertEquals(
            [new MigrationApplied(self::MIGRATION), new MigrationRolledBack(self::MIGRATION)],
            $recorder->events,
        );
    }

    /** @return array{int, string} */
    private static function runStatus(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(StatusCommand::class)->run(new CommandArguments([], [])));
    }

    /** @return array{int, string} */
    private static function runMigrate(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(MigrateCommand::class)->run(new CommandArguments([], [])));
    }

    /** @return array{int, string} */
    private static function runRollback(RequestScope $scope): array
    {
        return OutputCapture::of(\STDOUT, static fn (): int => $scope->get(RollbackCommand::class)->run(new CommandArguments([], [])));
    }

    /**
     * The container bin/kinetis resolves a command from, with the
     * discovered listener registry bound the way BootSequence binds it.
     *
     * @return array{RequestScope, MigrationEventRecorder}
     */
    private static function commandScope(): array
    {
        $recorder = new MigrationEventRecorder();
        $listeners = new EventListenerRegistry();
        $listeners->register(MigrationEventRecorder::class);

        $app = new AppScope();
        $app->instance(EventListenerRegistry::class, $listeners);
        $app->instance(MigrationEventRecorder::class, $recorder);
        $app->boot();

        return [$app->createRequestScope(), $recorder];
    }

    private function writeMigration(): void
    {
        \file_put_contents($this->projectRoot . '/migrations/' . self::MIGRATION . '.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Kinetis\Persistence\Contract\MysqlLink;
            use Kinetis\Persistence\Contract\PostgresLink;
            use Kinetis\Migrations\Migration;

            return new class implements Migration
            {
                public function up(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('CREATE TABLE bridge_widgets (id INT PRIMARY KEY)');
                }

                public function down(MysqlLink|PostgresLink $db): void
                {
                    $db->execute('DROP TABLE bridge_widgets');
                }
            };
            PHP);
    }

    /** @param array<string, string|false> $variables false unsets */
    private static function setEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            \putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    private static function client(): SqlLink
    {
        return new PdoMysqlClient(
            (string) \getenv('DB_HOST'),
            (string) \getenv('DB_USER'),
            (string) \getenv('DB_PASSWORD'),
            (string) \getenv('DB_NAME'),
            (int) \getenv('DB_PORT'),
        );
    }
}
