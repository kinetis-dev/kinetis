<?php

declare(strict_types=1);

namespace Kinetis\DatabaseBridge\Tests;

use Closure;
use Fiber;
use InvalidArgumentException;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\Exception\DatabaseNotConfiguredException;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\DatabaseBridge\PackageBootstrap;
use Kinetis\DatabaseBridge\Tests\Fixtures\NamedOrm\Report;
use Kinetis\DatabaseBridge\Tests\Fixtures\NamedOrm\Snapshot;
use Kinetis\DatabaseBridge\Tests\Fixtures\OrmProject\Entities\Post;
use Kinetis\DatabaseBridge\Tests\Fixtures\RowsMysqlLink;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Driver\PdoPgsqlClient;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use stdClass;

/**
 * The connections entities name beyond the default one: where each link
 * comes from, who closes it, and how the request's managers stay one per
 * connection and unit of work.
 */
final class NamedOrmConnectionTest extends TestCase
{
    private const array DEFAULT = ['DB_CONNECTION' => 'mysql', 'DB_PASSWORD' => 'secret'];

    private const array REPORTING = [
        'DB_REPORTING_CONNECTION' => 'pgsql',
        'DB_REPORTING_HOST' => 'reporting.internal',
        'DB_REPORTING_NAME' => 'reports',
        'DB_REPORTING_PASSWORD' => 'reporting-secret',
    ];

    public function test_a_named_connection_is_built_from_its_own_keys_and_dialect(): void
    {
        $app = self::app([...self::DEFAULT, ...self::REPORTING], [Post::class, Report::class]);
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);
        $reporting = self::linkOf($factories->factoryFor(Report::class));

        self::assertInstanceOf(PdoPgsqlClient::class, $reporting);
        self::assertSame('reporting.internal', new ReflectionProperty(PdoPgsqlClient::class, 'host')->getValue($reporting));
        self::assertSame('reports', new ReflectionProperty(PdoPgsqlClient::class, 'database')->getValue($reporting));
        self::assertSame($factories->factory('reporting'), $factories->factoryFor(Report::class));
        self::assertSame($app->get(MysqlLink::class), self::linkOf($factories->factoryFor(Post::class)));
        self::assertFalse($app->has(PostgresLink::class), 'a named link is no dialect binding');
        self::assertFalse($app->has('db.reporting'), 'a built named link stays inside the registry');
    }

    public function test_a_named_link_the_bridge_builds_closes_with_the_application(): void
    {
        $app = self::app(self::REPORTING, [Report::class]);
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);
        /** @var PdoPgsqlClient $link */
        $link = self::linkOf($factories->factory('reporting'));

        self::assertCount(1, self::disposeCallbacks($app));
        self::assertFalse($link->isClosed());

        $app->dispose();

        self::assertTrue($link->isClosed());
    }

    public function test_a_failed_resolution_never_builds_a_named_link_twice(): void
    {
        $app = self::app(['DB_ARCHIVE_CONNECTION' => 'pgsql', 'DB_ARCHIVE_PASSWORD' => 'secret'], [Report::class, Snapshot::class]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $app->get(OrmFactoryRegistry::class);
                self::fail('The registry resolved without the reporting connection.');
            } catch (DatabaseNotConfiguredException $e) {
                self::assertStringContainsString('DB_REPORTING_CONNECTION is not set', $e->getMessage());
            }
        }

        self::assertCount(1, self::disposeCallbacks($app), 'the archive link was built once');
    }

    public function test_a_missing_named_connection_is_reported_by_its_connection_key(): void
    {
        $app = self::app([...self::DEFAULT, 'DB_REPORTING_HOST' => 'reporting.internal'], [Post::class, Report::class]);

        $this->expectException(DatabaseNotConfiguredException::class);
        $this->expectExceptionMessage(
            OrmFactoryRegistry::class . ' needs the "reporting" database connection, which an entity names, and '
            . 'DB_REPORTING_CONNECTION is not set. Set DB_REPORTING_CONNECTION and the other DB_REPORTING_* keys, or '
            . 'bind that connection\'s MysqlLink or PostgresLink as "db.reporting" in bootstrap.php.',
        );

        $app->get(OrmFactoryRegistry::class);
    }

    public function test_an_application_link_for_a_named_connection_is_used_and_left_open(): void
    {
        $own = new RowsMysqlLink([['id' => 1, 'title' => 'Q3']]);
        $app = self::app(
            [...self::DEFAULT, ...self::REPORTING],
            [Post::class, Report::class],
            static fn (AppScope $app) => $app->instance('db.reporting', $own),
        );
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);
        $scope = $app->createRequestScope();
        /** @var EntityManagerRegistry $managers */
        $managers = $scope->get(EntityManagerRegistry::class);
        $managers->managerFor(Report::class)->repository(Report::class)->find(1);
        $scope->dispose();

        self::assertSame($own, self::linkOf($factories->factory('reporting')));
        self::assertSame(['SELECT `id`, `title` FROM `reports` WHERE `id` = 1 LIMIT 1'], $own->statements);
        self::assertCount(1, self::disposeCallbacks($app), 'only the default link is the bridge\'s to close');

        $app->dispose();

        self::assertSame(0, $own->closeCalls);
    }

    public function test_a_named_binding_that_is_no_client_is_refused(): void
    {
        $app = self::app(self::DEFAULT, [Report::class], static fn (AppScope $app) => $app->instance('db.reporting', new stdClass()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"db.reporting" is bound to stdClass, and the entities on the "reporting" connection need a MysqlLink or PostgresLink.');

        $app->get(OrmFactoryRegistry::class);
    }

    public function test_each_request_opens_its_managers_lazily_and_closes_them_with_the_scope(): void
    {
        $app = self::app(self::DEFAULT, [Post::class, Report::class], self::rows());
        $first = $app->createRequestScope();

        self::assertSame([], self::disposeCallbacks($first));

        /** @var EntityManagerRegistry $managers */
        $managers = $first->get(EntityManagerRegistry::class);
        $reporting = $managers->managerFor(Report::class);
        $report = $reporting->repository(Report::class)->find(1);
        $default = $first->get(EntityManager::class);

        self::assertSame($managers, $first->get(EntityManagerRegistry::class));
        self::assertSame($managers->manager('default'), $default);
        self::assertNotSame($default, $reporting);
        self::assertCount(1, self::disposeCallbacks($first));

        $first->dispose();

        self::assertTrue($reporting->isClosed());
        self::assertTrue($default->isClosed());

        $second = $app->createRequestScope();
        /** @var EntityManagerRegistry $next */
        $next = $second->get(EntityManagerRegistry::class);

        self::assertNotSame($managers, $next);
        self::assertNotSame($reporting, $next->managerFor(Report::class));
        self::assertNotSame($report, $next->managerFor(Report::class)->repository(Report::class)->find(1));

        try {
            new Fiber(static fn (): EntityManager => $next->managerFor(Report::class))->start();
            self::fail('A Fiber that did not create the request registry used it.');
        } catch (CrossFiberAccessException) {
        }

        $second->dispose();
    }

    public function test_named_entities_work_without_a_default_connection_whose_aliases_still_name_it(): void
    {
        $own = new RowsMysqlLink([['id' => 1, 'title' => 'Q3']]);
        $app = self::app([], [Report::class], static fn (AppScope $app) => $app->instance('db.reporting', $own));
        $scope = $app->createRequestScope();
        /** @var EntityManagerRegistry $managers */
        $managers = $scope->get(EntityManagerRegistry::class);

        self::assertInstanceOf(Report::class, $managers->manager('reporting')->repository(Report::class)->find(1));

        foreach ([
            static fn (): mixed => $app->get(OrmFactory::class),
            static fn (): mixed => $scope->get(EntityManager::class),
        ] as $resolve) {
            try {
                $resolve();
                self::fail('A default alias resolved without DB_CONNECTION.');
            } catch (DatabaseNotConfiguredException $e) {
                self::assertStringContainsString('DB_CONNECTION is not set', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);

        $managers->manager('default');
    }

    public function test_default_entities_without_db_connection_name_it(): void
    {
        $app = self::app([], [Post::class, Report::class], static fn (AppScope $app) => $app->instance('db.reporting', new RowsMysqlLink()));

        $this->expectException(DatabaseNotConfiguredException::class);
        $this->expectExceptionMessage(OrmFactoryRegistry::class . ' needs the default database connection, and DB_CONNECTION is not set.');

        $app->get(OrmFactoryRegistry::class);
    }

    public function test_the_configured_default_link_is_included_without_default_entities(): void
    {
        $app = self::app(self::DEFAULT, [Report::class], self::rows());
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);

        self::assertSame($factories->factory('default'), $app->get(OrmFactory::class));
        self::assertSame($app->get(MysqlLink::class), self::linkOf($factories->factory('default')));
    }

    public function test_an_application_metadata_override_is_observed(): void
    {
        $app = self::app(self::DEFAULT, [Post::class], static function (AppScope $app): void {
            self::rows()($app);
            $app->instance(OrmMetadata::class, OrmMetadata::fromArray(MetadataRegistry::fromClasses([Post::class, Report::class])->toArray()));
        });
        /** @var OrmFactoryRegistry $factories */
        $factories = $app->get(OrmFactoryRegistry::class);

        self::assertSame($factories->factory('reporting'), $factories->factoryFor(Report::class));
    }

    /**
     * Boots the way the framework does: OrmMetadata is bound before package
     * bootstraps run, and $bootstrap, the application's bootstrap.php, after.
     *
     * @param array<string, string> $config
     * @param list<class-string> $entities
     * @param (Closure(AppScope): mixed)|null $bootstrap
     */
    private static function app(array $config, array $entities, ?Closure $bootstrap = null): AppScope
    {
        $app = new AppScope();
        $app->instance(LoggerInterface::class, new NullLogger());
        $app->instance(OrmMetadata::class, OrmMetadata::fromArray(MetadataRegistry::fromClasses($entities)->toArray()));
        new PackageBootstrap()->register($app, new Config($config));

        if ($bootstrap !== null) {
            $bootstrap($app);
        }

        $app->boot();

        return $app;
    }

    /**
     * An application bootstrap binding both links to fixed rows.
     *
     * @return Closure(AppScope): void
     */
    private static function rows(): Closure
    {
        return static function (AppScope $app): void {
            $app->instance(MysqlLink::class, new RowsMysqlLink([['id' => 1, 'title' => 'Hello']]));
            $app->instance('db.reporting', new RowsMysqlLink([['id' => 1, 'title' => 'Q3']]));
        };
    }

    private static function linkOf(OrmFactory $factory): MysqlLink|PostgresLink
    {
        /** @var MysqlLink|PostgresLink */
        return new ReflectionProperty(OrmFactory::class, 'link')->getValue($factory);
    }

    /** @return list<callable(): void> */
    private static function disposeCallbacks(AppScope|RequestScope $scope): array
    {
        /** @var list<callable(): void> */
        return new ReflectionProperty($scope::class, 'disposeCallbacks')->getValue($scope);
    }
}
