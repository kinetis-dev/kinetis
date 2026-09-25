<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Fiber;
use InvalidArgumentException;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\EntityRepository;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Orm\Tests\Fixtures\Account;
use Kinetis\Orm\Tests\Fixtures\LedgerAccount;
use Kinetis\Orm\Tests\Fixtures\LedgerEntry;
use Kinetis\Orm\Tests\Fixtures\Metric;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\SpyPostgresLink;
use Kinetis\Orm\Tests\Fixtures\Ticket;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\TestCase;

/**
 * Connection affinity at run time: an OrmFactory maps the entities of one
 * connection, OrmFactoryRegistry holds one factory per connection, and
 * EntityManagerRegistry opens one manager per connection for one unit of
 * work, on that connection's link alone.
 */
final class ConnectionRegistryTest extends TestCase
{
    private SpyMysqlLink $default;

    private SpyMysqlLink $ledger;

    private SpyPostgresLink $analytics;

    private MetadataRegistry $metadata;

    private OrmFactoryRegistry $factories;

    protected function setUp(): void
    {
        $this->default = new SpyMysqlLink();
        $this->ledger = new SpyMysqlLink();
        $this->analytics = new SpyPostgresLink();
        $this->metadata = MetadataRegistry::fromClasses([Account::class, Ticket::class, LedgerAccount::class, LedgerEntry::class, Metric::class]);
        $this->factories = OrmFactoryRegistry::create(
            ['default' => $this->default, 'ledger' => $this->ledger, 'analytics' => $this->analytics],
            $this->metadata,
        );
    }

    public function test_a_factory_maps_only_the_entities_of_its_connection(): void
    {
        $ledger = OrmFactory::create($this->ledger, $this->metadata, 'ledger')->open();
        $default = OrmFactory::create($this->default, $this->metadata)->open();

        self::assertInstanceOf(EntityRepository::class, $ledger->repository(LedgerEntry::class));
        self::assertInstanceOf(EntityRepository::class, $default->repository(Ticket::class));

        foreach ([[$ledger, Ticket::class], [$default, LedgerEntry::class]] as [$manager, $class]) {
            try {
                $manager->repository($class);
                self::fail("{$class} was mapped on another connection's factory.");
            } catch (MappingException $e) {
                self::assertSame(
                    "{$class} is not an entity of this OrmFactory: it is not in the factory's MetadataRegistry, or it "
                    . 'lives on another connection.',
                    $e->getMessage(),
                );
            }
        }
    }

    public function test_a_connection_no_entity_names_gives_a_factory_that_maps_none(): void
    {
        $manager = OrmFactory::create($this->default, $this->metadata, 'archive')->open();

        $this->expectException(MappingException::class);

        $manager->repository(Account::class);
    }

    public function test_each_connection_reads_through_its_own_link_and_dialect(): void
    {
        $this->analytics->queue([['id' => 7, 'name' => 'signups']]);
        $this->ledger->queue([['id' => 3, 'name' => 'cash']]);
        $managers = EntityManagerRegistry::create($this->factories);

        $metric = $managers->managerFor(Metric::class)->repository(Metric::class)->find(7);
        $account = $managers->manager('ledger')->repository(LedgerAccount::class)->find(3);

        self::assertInstanceOf(Metric::class, $metric);
        self::assertInstanceOf(LedgerAccount::class, $account);
        self::assertSame(['SELECT "id", "name" FROM "metrics" WHERE "id" = 7 LIMIT 1'], $this->analytics->statements());
        self::assertSame(['SELECT `id`, `name` FROM `ledger_accounts` WHERE `id` = 3 LIMIT 1'], $this->ledger->statements());
        self::assertSame([], $this->default->calls);
    }

    public function test_the_registry_resolves_the_factory_of_each_connection_and_entity(): void
    {
        self::assertSame($this->factories->factory('ledger'), $this->factories->factoryFor(LedgerEntry::class));
        self::assertSame($this->factories->factory('default'), $this->factories->factoryFor(Account::class));
        self::assertNotSame($this->factories->factory('ledger'), $this->factories->factory('default'));
    }

    public function test_a_metadata_connection_without_a_link_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The entities on the "ledger" connection need its link, and none was given.');

        OrmFactoryRegistry::create(['default' => $this->default, 'analytics' => $this->analytics], $this->metadata);
    }

    public function test_an_extra_link_gives_an_empty_factory_and_an_unknown_one_is_refused(): void
    {
        $factories = OrmFactoryRegistry::create(['default' => $this->default, 'ledger' => $this->ledger], MetadataRegistry::fromClasses([LedgerEntry::class, LedgerAccount::class]));

        self::assertInstanceOf(OrmFactory::class, $factories->factory('default'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This OrmFactoryRegistry was given no link for the "reporting" connection.');

        $factories->factory('reporting');
    }

    public function test_an_entity_outside_the_metadata_has_no_factory(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(self::class . ' is not an entity in this MetadataRegistry.');

        EntityManagerRegistry::create($this->factories)->managerFor(self::class);
    }

    public function test_a_unit_of_work_opens_one_manager_per_connection_on_first_use(): void
    {
        $managers = EntityManagerRegistry::create($this->factories);
        $ledger = $managers->manager('ledger');

        self::assertSame($ledger, $managers->manager('ledger'));
        self::assertSame($ledger, $managers->managerFor(LedgerEntry::class));
        self::assertSame($managers->manager('default'), $managers->managerFor(Ticket::class));
        self::assertNotSame($ledger, $managers->manager('default'));
        self::assertNotSame($ledger, $managers->manager('analytics'));
    }

    public function test_separate_units_of_work_share_neither_managers_nor_identities(): void
    {
        $this->ledger->queue([['id' => 3, 'name' => 'cash']], [['id' => 3, 'name' => 'cash']]);
        $first = EntityManagerRegistry::create($this->factories);
        $second = EntityManagerRegistry::create($this->factories);

        $firstManager = $first->manager('ledger');
        $firstAccount = $firstManager->repository(LedgerAccount::class)->find(3);
        $first->close();
        $secondManager = $second->manager('ledger');
        $secondAccount = $secondManager->repository(LedgerAccount::class)->find(3);

        self::assertNotSame($firstManager, $secondManager);
        self::assertNotSame($firstAccount, $secondAccount);
        self::assertTrue($firstManager->isClosed());
        self::assertFalse($secondManager->isClosed());
    }

    public function test_close_closes_every_opened_manager_without_flushing_and_refuses_later_use(): void
    {
        $managers = EntityManagerRegistry::create($this->factories);
        $ledger = $managers->manager('ledger');
        $default = $managers->manager('default');
        $account = new LedgerAccount();
        [$account->id, $account->name] = [1, 'cash'];
        $ledger->persist($account);

        $managers->close();
        $managers->close();

        self::assertTrue($ledger->isClosed());
        self::assertTrue($default->isClosed());
        self::assertSame(0, $this->ledger->begins, 'close() never flushes');
        self::assertSame(0, $this->ledger->closeCalls + $this->default->closeCalls + $this->analytics->closeCalls, 'the links stay open');

        foreach ([static fn (): EntityManager => $managers->manager('analytics'), static fn (): EntityManager => $managers->managerFor(Ticket::class)] as $resolve) {
            try {
                $resolve();
                self::fail('A closed registry opened a manager.');
            } catch (ClosedEntityManagerException $e) {
                self::assertStringStartsWith('This EntityManagerRegistry is closed', $e->getMessage());
            }
        }
    }

    public function test_another_fiber_can_neither_use_the_registry_nor_its_managers_but_can_close_them(): void
    {
        $managers = EntityManagerRegistry::create($this->factories);
        $ledger = $managers->manager('ledger');

        foreach ([
            static fn (): EntityManager => $managers->manager('ledger'),
            static fn (): EntityManager => $managers->managerFor(Metric::class),
            static fn (): mixed => $ledger->repository(LedgerAccount::class),
        ] as $use) {
            try {
                new Fiber($use)->start();
                self::fail('A Fiber that did not create the registry used it.');
            } catch (CrossFiberAccessException $e) {
                self::assertStringContainsString('belongs to the Fiber that', $e->getMessage());
            }
        }

        new Fiber($managers->close(...))->start();

        self::assertTrue($ledger->isClosed());
    }

    public function test_a_flush_and_a_transaction_run_on_their_own_connection_alone(): void
    {
        $this->ledger->transaction = $flush = new SpyMysqlTransaction();
        $flush->queue(new BufferedSqlResult([], 1, null));
        $managers = EntityManagerRegistry::create($this->factories);
        $account = new LedgerAccount();
        [$account->id, $account->name] = [1, 'cash'];

        $managers->managerFor(LedgerAccount::class)->persist($account);
        $managers->manager('default')->flush();
        $managers->manager('ledger')->flush();

        self::assertSame(['INSERT INTO `ledger_accounts` (`id`, `name`) VALUES (?, ?)'], $flush->statements());
        self::assertSame(['commit'], $flush->ends);
        self::assertSame([1, 0, 0], [$this->ledger->begins, $this->default->begins, $this->analytics->begins]);

        try {
            $managers->manager('default')->persist(new LedgerAccount());
            self::fail("The default connection's manager accepted a ledger entity.");
        } catch (InvalidEntityStateException $e) {
            self::assertStringContainsString('lives on another connection', $e->getMessage());
        }

        $this->ledger->transaction = $session = new SpyMysqlTransaction();
        $this->factories->factory('ledger')->transaction(static function (EntityManager $entities): void {
            $entities->repository(LedgerAccount::class)->find(1);
        });

        self::assertSame(['commit'], $session->ends);
        self::assertSame([2, 0, 0], [$this->ledger->begins, $this->default->begins, $this->analytics->begins]);
        self::assertSame([], $this->default->calls);
    }
}
