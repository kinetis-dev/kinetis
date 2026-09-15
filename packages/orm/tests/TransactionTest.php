<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use DomainException;
use Fiber;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CommitNotAcknowledgedException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Counted;
use Kinetis\Orm\Tests\Fixtures\Invoice;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\Ticket;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\Exception\TransactionException;
use Kinetis\QueryBuilder\LockWait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * OrmFactory::transaction() against a scripted client and transaction: the
 * one transaction every statement runs on, when entity state is applied,
 * which operations a flush or a failure refuses, and how each way out ends
 * the transaction.
 */
final class TransactionTest extends TestCase
{
    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Counted::class, Invoice::class, Ticket::class]));
    }

    public function test_every_read_lock_raw_statement_and_the_flush_run_on_the_one_transaction_the_factory_began(): void
    {
        $this->transaction->queue([self::invoiceRow(1, 3)], self::affected(1), self::insertId(42), self::affected(1));
        $endsAtFlush = null;

        $result = $this->factory->transaction(function (EntityManager $entities) use (&$endsAtFlush): string {
            $invoice = $entities->repository(Invoice::class)->query()->where('id', '=', 1)->lockForUpdate()->first();
            self::assertInstanceOf(Invoice::class, $invoice);
            $invoice->status = 'paid';
            $entities->builder()->table('audit_log')->insert(['message' => 'paid']);
            $entities->persist(new Ticket('Receipt'));
            $entities->flush();
            $endsAtFlush = $this->transaction->ends;

            return 'paid';
        });

        self::assertSame('paid', $result);
        self::assertSame(1, $this->link->begins);
        self::assertSame([], $this->link->calls, 'nothing ran on the client');
        self::assertSame([
            ['sql' => 'SELECT `id`, `status`, `row_version`, `total` FROM `invoices` WHERE `id` = 1 LIMIT 1 FOR UPDATE', 'params' => []],
            ['sql' => 'INSERT INTO `audit_log` (`message`) VALUES (?)', 'params' => ['paid']],
            ['sql' => 'INSERT INTO `tickets` (`subject`) VALUES (?)', 'params' => ['Receipt']],
            ['sql' => 'UPDATE `invoices` SET `status` = ?, `row_version` = ? WHERE `id` = ? AND `row_version` = ?', 'params' => ['paid', 4, 1, 3]],
        ], $this->transaction->calls);
        self::assertSame([], $endsAtFlush, 'flush() sent its work without ending the transaction');
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_an_identity_map_miss_and_each_lock_mode_read_on_the_transaction_as_the_query_builder_compiles_them(): void
    {
        $this->transaction->queue([['id' => 1, 'score' => 1]], [['id' => 2, 'score' => 1]], [['id' => 3, 'score' => 1]]);

        $this->factory->transaction(static function (EntityManager $entities): void {
            $counted = $entities->repository(Counted::class);
            $counted->find(1);
            $counted->find(1);
            $counted->query()->lockForUpdate(LockWait::SkipLocked)->get();
            $counted->query()->lockForShare()->first();
        });

        self::assertSame([
            'SELECT `id`, `score` FROM `counted` WHERE `id` = 1 LIMIT 1',
            'SELECT `id`, `score` FROM `counted` FOR UPDATE SKIP LOCKED',
            'SELECT `id`, `score` FROM `counted` LIMIT 1 LOCK IN SHARE MODE',
        ], $this->transaction->statements());
        self::assertSame([], $this->link->calls);
    }

    public function test_a_generated_identifier_and_a_version_are_applied_only_once_the_factory_commit_returns(): void
    {
        $this->transaction->queue([self::invoiceRow(1, 3)], self::insertId(42), self::affected(1));
        $ticket = new Ticket('Receipt');
        $invoice = null;
        $afterFlush = null;
        $atCommit = null;
        $this->transaction->onCommit = static function () use ($ticket, &$invoice, &$atCommit): void {
            self::assertInstanceOf(Invoice::class, $invoice);
            $atCommit = [$ticket->id, $invoice->version];
        };

        $this->factory->transaction(static function (EntityManager $entities) use ($ticket, &$invoice, &$afterFlush): void {
            $invoice = $entities->repository(Invoice::class)->findOrFail(1);
            $invoice->status = 'paid';
            $entities->persist($ticket);
            $entities->flush();
            $afterFlush = [$ticket->id, $invoice->version];
        });

        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame([null, 3], $afterFlush);
        self::assertSame([null, 3], $atCommit);
        self::assertSame([42, 4], [$ticket->id, $invoice->version]);
    }

    /**
     * @return iterable<string, array{Closure(SpyMysqlTransaction): void, bool, class-string<Throwable>, list<string>}>
     */
    public static function sessionsThatDoNotCommit(): iterable
    {
        yield 'the callback throws' => [static function (): void {}, true, DomainException::class, ['rollback']];
        yield 'the callback throws and the rollback throws' => [
            static function (SpyMysqlTransaction $transaction): void {
                $transaction->onRollback = static fn (): never => throw new ConnectionException('MySQL connection lost');
            },
            true,
            RollbackFailedException::class,
            ['rollback'],
        ];
        yield 'COMMIT is not acknowledged' => [
            static function (SpyMysqlTransaction $transaction): void {
                $transaction->onCommit = static fn (): never => throw new TransactionException('Commit refused.');
            },
            false,
            CommitNotAcknowledgedException::class,
            ['commit'],
        ];
    }

    /**
     * @param Closure(SpyMysqlTransaction): void $script
     * @param class-string<Throwable> $expected
     * @param list<string> $ends
     */
    #[DataProvider('sessionsThatDoNotCommit')]
    public function test_a_session_that_does_not_commit_applies_nothing_and_closes_the_manager(Closure $script, bool $throw, string $expected, array $ends): void
    {
        $this->transaction->queue([self::invoiceRow(1, 3)], self::insertId(42), self::affected(1));
        $script($this->transaction);
        $ticket = new Ticket('Receipt');
        $declined = new DomainException('The payment was declined.');
        [$invoice, $manager, $thrown] = [null, null, null];

        try {
            $this->factory->transaction(static function (EntityManager $entities) use ($ticket, $throw, $declined, &$invoice, &$manager): void {
                $manager = $entities;
                $invoice = $entities->repository(Invoice::class)->findOrFail(1);
                $invoice->status = 'paid';
                $entities->persist($ticket);
                $entities->flush();

                if ($throw) {
                    throw $declined;
                }
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf($expected, $thrown);
        self::assertSame(
            $throw ? $declined : TransactionException::class,
            $throw ? ($thrown instanceof RollbackFailedException ? $thrown->getPrevious() : $thrown) : $thrown->getPrevious()::class,
        );
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame([null, 3], [$ticket->id, $invoice->version]);
        self::assertInstanceOf(EntityManager::class, $manager);
        self::assertTrue($manager->isClosed());
        self::assertCount(3, $this->transaction->calls);
        self::assertSame($ends, $this->transaction->ends);
    }

    public function test_the_result_passes_through_and_everything_the_callback_returns_is_closed_or_detached(): void
    {
        gc_collect_cycles();
        Counted::$destroyed = 0;
        $this->transaction->queue([['id' => 1, 'score' => 1]]);

        [$entities, $repository, $query, $counted, $label] = $this->factory->transaction(static function (EntityManager $entities): array {
            $repository = $entities->repository(Counted::class);

            return [$entities, $repository, $repository->query(), $repository->findOrFail(1), 'loaded'];
        });

        self::assertSame('loaded', $label);
        self::assertTrue($entities->isClosed());

        foreach ([
            static fn (): mixed => $entities->contains($counted),
            static fn (): mixed => $entities->builder(),
            static fn (): mixed => $repository->find(1),
            static fn (): mixed => $query->get(),
        ] as $use) {
            self::assertThrows(ClosedEntityManagerException::class, $use);
        }

        unset($counted);
        gc_collect_cycles();

        self::assertSame(1, Counted::$destroyed, 'the closed manager holds no reference to the returned entity');
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_a_no_op_flush_leaves_the_session_usable_and_a_flush_that_writes_seals_every_orm_operation(): void
    {
        $this->transaction->queue([['id' => 1, 'score' => 1]], self::affected(1));
        $refusals = [];

        $this->factory->transaction(static function (EntityManager $entities) use (&$refusals): void {
            $entities->flush();
            $repository = $entities->repository(Counted::class);
            $query = $repository->query();
            $counted = $repository->findOrFail(1);
            $counted->score = 2;
            $entities->flush();
            $counted->score = 3;

            foreach ([
                static fn (): mixed => $entities->flush(),
                static fn (): mixed => $entities->repository(Counted::class),
                static fn (): mixed => $entities->contains($counted),
                static fn (): mixed => $entities->persist(new Ticket('Receipt')),
                static fn (): mixed => $entities->remove($counted),
                static fn (): mixed => $entities->clear(),
                static fn (): mixed => $entities->builder(),
                static fn (): mixed => $repository->find(2),
                static fn (): mixed => $repository->query(),
                static fn (): mixed => $query->where('id', '=', 2),
                static fn (): mixed => $query->lockForUpdate(),
                static fn (): mixed => $query->get(),
                static fn (): mixed => $query->builder(),
            ] as $use) {
                try {
                    $use();
                    $refusals[] = 'accepted';
                } catch (Throwable $e) {
                    $refusals[] = $e->getMessage();
                }
            }

            self::assertFalse($entities->isClosed());
        });

        self::assertSame(array_fill(0, 13, InvalidEntityStateException::sessionFlushed()->getMessage()), $refusals);
        self::assertSame(
            ['SELECT `id`, `score` FROM `counted` WHERE `id` = 1 LIMIT 1', 'UPDATE `counted` SET `score` = 2 WHERE `id` = 1'],
            $this->transaction->statements(),
            'the second flush wrote nothing',
        );
        self::assertSame(['commit'], $this->transaction->ends, 'a refusal does not fail the session');
    }

    /**
     * @return iterable<string, array{Closure(EntityManager, SpyMysqlTransaction): mixed, int}>
     */
    public static function managedFailures(): iterable
    {
        yield 'flush()' => [static function (EntityManager $entities, SpyMysqlTransaction $transaction): mixed {
            $entities->persist(new Ticket('Receipt'));
            $transaction->onStatement = static fn (): never => throw new QueryException('Duplicate entry', 'INSERT', null, 1062, '23000');

            return $entities->flush();
        }, 1];
        yield 'an identity-map miss' => [static function (EntityManager $entities, SpyMysqlTransaction $transaction): mixed {
            $transaction->onStatement = static fn (): never => throw new ConnectionException('MySQL connection lost');

            return $entities->repository(Counted::class)->find(1);
        }, 1];
        yield 'a lock Query refuses before SQL' => [
            static fn (EntityManager $entities): mixed => $entities->repository(Counted::class)->query()->lockForUpdate()->count(),
            0,
        ];
        yield 'a locked row that fails conversion' => [static function (EntityManager $entities, SpyMysqlTransaction $transaction): mixed {
            $transaction->queue([['id' => 1, 'score' => 'ten']]);

            return $entities->repository(Counted::class)->query()->lockForShare()->get();
        }, 1];
    }

    /**
     * @param Closure(EntityManager, SpyMysqlTransaction): mixed $fail
     */
    #[DataProvider('managedFailures')]
    public function test_a_caught_managed_failure_refuses_later_use_and_the_session_rolls_back_and_rethrows_it(Closure $fail, int $statements): void
    {
        [$caught, $later, $thrown] = [null, null, null];

        try {
            $this->factory->transaction(function (EntityManager $entities) use ($fail, &$caught, &$later): string {
                try {
                    $fail($entities, $this->transaction);
                } catch (Throwable $e) {
                    $caught = $e;
                }

                $this->transaction->onStatement = null;

                try {
                    $entities->repository(Counted::class);
                } catch (InvalidEntityStateException $e) {
                    $later = $e;
                }

                return 'handled';
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($caught);
        self::assertSame($caught, $thrown);
        self::assertInstanceOf(InvalidEntityStateException::class, $later);
        self::assertSame($caught, $later->getPrevious());
        self::assertCount($statements, $this->transaction->calls);
        self::assertSame(['rollback'], $this->transaction->ends);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function rollbackOutcomes(): iterable
    {
        yield 'the rollback returns' => [false];
        yield 'the rollback throws' => [true];
    }

    #[DataProvider('rollbackOutcomes')]
    public function test_a_caught_managed_failure_stays_primary_when_the_callback_then_throws_its_own_exception(bool $rollbackThrows): void
    {
        $managedFailure = new ConnectionException('MySQL connection lost');
        $rollbackFailure = new ConnectionException('MySQL connection lost during ROLLBACK');
        $this->transaction->onStatement = static fn (): never => throw $managedFailure;

        if ($rollbackThrows) {
            $this->transaction->onRollback = static fn (): never => throw $rollbackFailure;
        }

        $thrown = null;

        try {
            $this->factory->transaction(static function (EntityManager $entities): never {
                try {
                    $entities->repository(Counted::class)->find(1);
                } catch (ConnectionException) {
                }

                throw new DomainException('The payment was declined.');
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        if ($rollbackThrows) {
            self::assertInstanceOf(RollbackFailedException::class, $thrown);
            self::assertSame($managedFailure, $thrown->getPrevious());
            self::assertSame($rollbackFailure, $thrown->rollbackFailure);
        } else {
            self::assertSame($managedFailure, $thrown);
        }

        self::assertSame(['rollback'], $this->transaction->ends);
    }

    public function test_a_refusal_before_a_terminal_reaches_the_query_builder_does_not_fail_the_session(): void
    {
        $this->transaction->queue([['id' => 1, 'score' => 4]]);

        $score = $this->factory->transaction(static function (EntityManager $entities): int {
            $counted = $entities->repository(Counted::class);

            foreach ([
                static fn (): mixed => $counted->query()->where('points', '=', 1),
                static fn (): mixed => $counted->find('one'),
                static fn (): mixed => $counted->query()->cursorPaginate(2, null, 'points'),
            ] as $refused) {
                self::assertThrows(MappingException::class, $refused);
            }

            return $counted->findOrFail(1)->score;
        });

        self::assertSame(4, $score);
        self::assertCount(1, $this->transaction->calls);
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_a_raw_builder_failure_the_callback_catches_leaves_commit_to_decide_and_a_failed_commit_is_not_acknowledged(): void
    {
        $rawFailure = new QueryException('Deadlock found', 'INSERT', null, 1213, '40001');
        $commitFailure = new TransactionException('The transaction is no longer open.');
        $this->transaction->onStatement = static fn (): never => throw $rawFailure;
        $this->transaction->onCommit = static fn (): never => throw $commitFailure;
        $thrown = null;

        try {
            $this->factory->transaction(static function (EntityManager $entities): string {
                try {
                    $entities->builder()->table('audit_log')->insert(['message' => 'paid']);
                } catch (QueryException) {
                }

                return 'handled';
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(CommitNotAcknowledgedException::class, $thrown);
        self::assertSame($commitFailure, $thrown->getPrevious());
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_a_failed_begin_is_rethrown_without_running_the_callback_or_keeping_the_session(): void
    {
        $failure = new ConnectionException('MySQL connection lost');
        $this->link->onBegin = static fn (): never => throw $failure;
        $called = false;

        try {
            $this->factory->transaction(static function () use (&$called): void {
                $called = true;
            });
        } catch (Throwable $e) {
            self::assertSame($failure, $e);
        }

        self::assertFalse($called);
        self::assertSame([], $this->transaction->ends);

        $this->link->onBegin = null;

        self::assertSame('again', $this->factory->transaction(static fn (): string => 'again'));
        self::assertSame(2, $this->link->begins);
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_closing_the_manager_in_the_callback_ends_the_transaction_and_nothing_is_committed(): void
    {
        $this->transaction->queue(self::insertId(42));
        $ticket = new Ticket('Receipt');
        $thrown = null;

        try {
            $this->factory->transaction(static function (EntityManager $entities) use ($ticket): string {
                $entities->persist($ticket);
                $entities->flush();
                $entities->close();

                return 'closed';
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(ClosedEntityManagerException::class, $thrown);
        self::assertSame(['close'], $this->transaction->ends);
        self::assertNull($ticket->id);
    }

    public function test_a_close_from_another_fiber_ends_the_transaction_and_the_session_fails_without_commit(): void
    {
        $fiber = new Fiber(function (): mixed {
            try {
                return $this->factory->transaction(static function (EntityManager $entities): string {
                    Fiber::suspend($entities);

                    return 'resumed';
                });
            } catch (Throwable $e) {
                return $e;
            }
        });

        $entities = $fiber->start();
        self::assertInstanceOf(EntityManager::class, $entities);
        $entities->close();
        self::assertSame(['close'], $this->transaction->ends);

        $fiber->resume();

        self::assertInstanceOf(ClosedEntityManagerException::class, $fiber->getReturn());
        self::assertSame(['close'], $this->transaction->ends);
    }

    public function test_a_nested_session_on_the_same_factory_and_fiber_is_refused_before_it_begins(): void
    {
        $attempt = function (): ?Throwable {
            return $this->factory->transaction(function (): ?Throwable {
                try {
                    $this->factory->transaction(static fn (): string => 'nested');
                } catch (Throwable $e) {
                    return $e;
                }

                return null;
            });
        };

        $inMain = $attempt();
        $fiber = new Fiber($attempt);
        $fiber->start();

        foreach ([$inMain, $fiber->getReturn()] as $refusal) {
            self::assertInstanceOf(InvalidEntityStateException::class, $refusal);
            self::assertSame(InvalidEntityStateException::nestedTransaction()->getMessage(), $refusal->getMessage());
        }

        self::assertSame(2, $this->link->begins);
        self::assertSame(['commit', 'commit'], $this->transaction->ends, 'a refused nested call does not fail the outer session');

        self::assertSame('after', $this->factory->transaction(static fn (): string => 'after'));
        self::assertSame(3, $this->link->begins);
    }

    public function test_concurrent_fibers_hold_separate_sessions_on_one_factory_and_refuse_each_others_manager(): void
    {
        $transactions = [new SpyMysqlTransaction(), new SpyMysqlTransaction()];
        $unclaimed = $transactions;
        $this->link->onBegin = function () use (&$unclaimed): void {
            $this->link->transaction = array_shift($unclaimed);
        };
        $fibers = [];
        $managers = [];

        foreach ([5, 7] as $i => $score) {
            $transactions[$i]->queue([['id' => 1, 'score' => 1]], self::affected(1));
            $fibers[$i] = new Fiber(function () use ($score): int {
                return $this->factory->transaction(static function (EntityManager $entities) use ($score): int {
                    $counted = $entities->repository(Counted::class)->findOrFail(1);
                    Fiber::suspend($entities);
                    $counted->score = $score;
                    $entities->flush();

                    return $score;
                });
            });
            $managers[$i] = $fibers[$i]->start();
        }

        self::assertInstanceOf(EntityManager::class, $managers[0]);
        self::assertNotSame($managers[0], $managers[1]);
        self::assertThrows(CrossFiberAccessException::class, static fn (): mixed => $managers[0]->repository(Counted::class));
        $foreign = new Fiber(static fn (): mixed => self::assertThrows(CrossFiberAccessException::class, static fn (): mixed => $managers[1]->flush()));
        $foreign->start();

        $fibers[1]->resume();
        $fibers[0]->resume();

        self::assertSame([5, 7], [$fibers[0]->getReturn(), $fibers[1]->getReturn()]);

        foreach ([5, 7] as $i => $score) {
            self::assertSame(
                ['SELECT `id`, `score` FROM `counted` WHERE `id` = 1 LIMIT 1', "UPDATE `counted` SET `score` = {$score} WHERE `id` = 1"],
                $transactions[$i]->statements(),
            );
            self::assertSame(['commit'], $transactions[$i]->ends);
        }

        self::assertSame(2, $this->link->begins);
        self::assertSame([], $this->link->calls);
    }

    /**
     * @return array<string, int|string>
     */
    private static function invoiceRow(int $id, int $version): array
    {
        return ['id' => $id, 'status' => 'open', 'row_version' => $version, 'total' => 100];
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }

    private static function insertId(int $id): SqlResult
    {
        return new BufferedSqlResult([], 1, null, $id);
    }

    /**
     * @param class-string<Throwable> $expected
     * @param Closure(): mixed $call
     */
    private static function assertThrows(string $expected, Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            self::assertInstanceOf($expected, $e);

            return;
        }

        self::fail("Expected {$expected}.");
    }
}
