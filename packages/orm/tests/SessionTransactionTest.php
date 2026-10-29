<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use DomainException;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\SessionTransaction;
use Kinetis\Orm\Tests\Fixtures\Counted;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The SessionTransaction an OrmFactory::transaction() callback receives: its
 * statements run on the session's transaction, and every attempt to end or
 * nest that transaction through it is refused without reaching it.
 */
final class SessionTransactionTest extends TestCase
{
    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Counted::class]));
    }

    public function test_the_callback_receives_a_view_whose_statements_and_state_are_the_sessions_transaction(): void
    {
        $inserted = new BufferedSqlResult([], 1, null, 7);
        $this->transaction->queue($inserted, [['n' => 1]]);
        $seen = null;

        $result = $this->factory->transaction(function (EntityManager $entities, SessionTransaction $tx) use ($inserted, &$seen): string {
            $seen = $tx;

            self::assertSame($inserted, $tx->execute('INSERT INTO jobs (payload) VALUES (?)', ['receipt']));
            self::assertSame(['n' => 1], $tx->query('SELECT 1 AS n')->fetchRow());
            self::assertTrue($tx->isActive());
            self::assertFalse($tx->isClosed());
            self::assertSame([], $this->transaction->ends);

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(
            [
                ['sql' => 'INSERT INTO jobs (payload) VALUES (?)', 'params' => ['receipt']],
                ['sql' => 'SELECT 1 AS n', 'params' => []],
            ],
            $this->transaction->calls,
        );
        self::assertSame([], $this->link->calls);
        self::assertSame(['commit'], $this->transaction->ends);
        self::assertInstanceOf(SessionTransaction::class, $seen);
        self::assertFalse($seen->isActive());
        self::assertTrue($seen->isClosed());
    }

    public function test_a_one_argument_callback_still_runs_the_session(): void
    {
        self::assertSame('one', $this->factory->transaction(static fn (EntityManager $entities): string => 'one'));
        self::assertSame(['commit'], $this->transaction->ends);
    }

    /**
     * @return iterable<string, array{'commit'|'rollback'|'close'|'beginTransaction'}>
     */
    public static function endings(): iterable
    {
        yield 'commit()' => ['commit'];
        yield 'rollback()' => ['rollback'];
        yield 'close()' => ['close'];
        yield 'beginTransaction()' => ['beginTransaction'];
    }

    /**
     * @param 'commit'|'rollback'|'close'|'beginTransaction' $method
     */
    #[DataProvider('endings')]
    public function test_ending_or_nesting_through_the_view_is_refused_and_a_caught_refusal_still_commits(string $method): void
    {
        $refusal = null;

        $this->factory->transaction(function (EntityManager $entities, SessionTransaction $tx) use ($method, &$refusal): void {
            try {
                $tx->{$method}();
            } catch (InvalidEntityStateException $e) {
                $refusal = $e;
            }

            self::assertSame([], $this->transaction->ends);
            self::assertTrue($this->transaction->isActive());
            self::assertTrue($tx->isActive());
        });

        self::assertInstanceOf(InvalidEntityStateException::class, $refusal);
        self::assertStringContainsString("SessionTransaction::{$method}()", $refusal->getMessage());
        self::assertSame(1, $this->link->begins);
        self::assertSame(['commit'], $this->transaction->ends);
    }

    /**
     * @param 'commit'|'rollback'|'close'|'beginTransaction' $method
     */
    #[DataProvider('endings')]
    public function test_an_uncaught_refusal_rolls_the_session_back(string $method): void
    {
        $thrown = null;

        try {
            $this->factory->transaction(static function (EntityManager $entities, SessionTransaction $tx) use ($method): void {
                $tx->{$method}();
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(InvalidEntityStateException::class, $thrown);
        self::assertSame(['rollback'], $this->transaction->ends);
    }

    public function test_a_callback_throw_after_a_view_statement_rolls_that_statement_back_with_the_session(): void
    {
        $failure = new DomainException('The payment was declined.');
        $thrown = null;

        try {
            $this->factory->transaction(static function (EntityManager $entities, SessionTransaction $tx) use ($failure): never {
                $tx->execute('INSERT INTO jobs (payload) VALUES (?)', ['receipt']);

                throw $failure;
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertSame($failure, $thrown);
        self::assertSame(['INSERT INTO jobs (payload) VALUES (?)'], $this->transaction->statements());
        self::assertSame(['rollback'], $this->transaction->ends);
    }
}
