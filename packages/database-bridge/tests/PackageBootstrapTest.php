<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\FakeSqlLink;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\PdoMysqlClient;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use Kinetis\Persistence\TransactionGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class PackageBootstrapTest extends TestCase
{
    public function test_without_db_connection_no_connection_is_bound(): void
    {
        $app = self::bootedApp([]);

        self::assertFalse($app->has(MysqlLink::class));
        self::assertFalse($app->has(PostgresLink::class));
    }

    public function test_the_default_connection_is_bound_under_its_dialect_contract(): void
    {
        $mysql = self::bootedApp(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret']);
        $postgres = self::bootedApp(['DB_CONNECTION' => 'pgsql', 'DB_PASSWORD' => 'secret']);

        self::assertInstanceOf(PdoMysqlClient::class, $mysql->get(MysqlLink::class));
        self::assertFalse($mysql->has(PostgresLink::class));
        self::assertInstanceOf(PdoPgsqlClient::class, $postgres->get(PostgresLink::class));
        self::assertFalse($postgres->has(MysqlLink::class));
    }

    public function test_a_named_connection_stays_explicit_wiring(): void
    {
        $app = self::bootedApp(['DB_REPORTS_CONNECTION' => 'pgsql', 'DB_REPORTS_PASSWORD' => 'secret']);

        self::assertFalse($app->has(MysqlLink::class));
        self::assertFalse($app->has(PostgresLink::class));
    }

    public function test_the_application_bootstrap_wins_over_the_bound_connection(): void
    {
        $own = $this->createStub(MysqlLink::class);
        $app = new AppScope();
        new PackageBootstrap()->register($app, new Config(['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret']));
        $app->instance(MysqlLink::class, $own);
        $app->boot();

        self::assertSame($own, $app->get(MysqlLink::class));
    }

    /**
     * The guard's own construction is what resolves the logger, so a
     * logger never resolved is a guard never built.
     */
    public function test_a_scope_that_never_resolves_the_guard_constructs_none_and_registers_no_cleanup(): void
    {
        $resolutions = 0;
        $app = new AppScope();
        $app->bind(LoggerInterface::class, static function () use (&$resolutions): LoggerInterface {
            $resolutions++;

            return new NullLogger();
        }, shared: false);
        new PackageBootstrap()->register($app, new Config([]));
        $app->boot();

        $scope = $app->createRequestScope();

        self::assertSame([], self::disposeCallbacks($scope));
        $scope->dispose();
        self::assertSame(0, $resolutions);
    }

    public function test_resolving_the_guard_registers_exactly_one_cleanup_on_that_scope(): void
    {
        $app = self::bootedApp([]);
        $scope = $app->createRequestScope();

        $guard = $scope->get(TransactionGuard::class);

        self::assertSame($guard, $scope->get(TransactionGuard::class));
        self::assertCount(1, self::disposeCallbacks($scope));
    }

    public function test_each_unit_of_work_cleans_up_its_own_guard_only(): void
    {
        $app = self::bootedApp([]);
        $first = $app->createRequestScope();
        $second = $app->createRequestScope();
        $link = new FakeSqlLink();

        $first->get(TransactionGuard::class)->beginTransaction($link);
        self::assertNotSame($first->get(TransactionGuard::class), $second->get(TransactionGuard::class));

        $second->dispose();
        self::assertFalse($link->transactions[0]->rolledBack, 'another unit of work ending leaves this transaction alone');

        $first->dispose();
        self::assertTrue($link->transactions[0]->rolledBack);
    }

    /** @param array<string, string> $values */
    private static function bootedApp(array $values): AppScope
    {
        $app = new AppScope();
        $app->instance(LoggerInterface::class, new NullLogger());
        new PackageBootstrap()->register($app, new Config($values));
        $app->boot();

        return $app;
    }

    /** @return list<callable(): void> */
    private static function disposeCallbacks(RequestScope $scope): array
    {
        /** @var list<callable(): void> */
        return new ReflectionProperty(RequestScope::class, 'disposeCallbacks')->getValue($scope);
    }
}
