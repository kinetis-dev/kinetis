<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Fiber;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Orm\Exception\RollbackFailedException;
use Kinetis\Orm\Exception\UnknownFlushOutcomeException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Counted;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Edition;
use Kinetis\Orm\Tests\Fixtures\Invoice;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\StoredArticle;
use Kinetis\Orm\Tests\Fixtures\Ticket;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use Kinetis\Persistence\Exception\ConnectionException;
use Kinetis\Persistence\Exception\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * flush() against a scripted transaction: the statements it sends, the one
 * transaction they run on, and what each failure, re-entry and close()
 * leaves behind.
 */
final class FlushTest extends TestCase
{
    private const string FIRST_UUID = '1a2b3c4d-0000-4000-8000-000000000001';

    private const string SECOND_UUID = '1a2b3c4d-0000-4000-8000-000000000002';

    private const string THIRD_UUID = '1a2b3c4d-0000-4000-8000-000000000003';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create(
            $this->link,
            MetadataRegistry::fromClasses([Article::class, Counted::class, Document::class, Edition::class, Invoice::class, StoredArticle::class, Ticket::class]),
        );
    }

    public function test_a_flush_with_nothing_to_write_begins_no_transaction(): void
    {
        $manager = $this->factory->open();
        $manager->flush();

        $this->link->queue([Article::row(['featured' => '1', 'rating' => '4.5', 'priority' => '2', 'author' => '7'])]);
        $manager->repository(Article::class)->findOrFail(1);
        $manager->flush();

        self::assertSame(0, $this->link->begins);
        self::assertCount(1, $this->link->calls, 'every value read from its driver spelling compares equal to the property');
    }

    public function test_every_statement_runs_on_the_one_transaction_inserts_first_then_by_class_and_identifier(): void
    {
        $manager = $this->factory->open();
        $this->link->queue(
            [['id' => 10, 'score' => 1], ['id' => 9, 'score' => 1]],
            [['id' => self::SECOND_UUID, 'title' => 'Second'], ['id' => self::FIRST_UUID, 'title' => 'First']],
        );
        [$tenth, $ninth] = $manager->repository(Counted::class)->query()->get();
        [$second, $first] = $manager->repository(Document::class)->query()->get();

        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $manager->persist(self::document(self::THIRD_UUID, 'Third'));
        $tenth->score = 5;
        $ninth->score = 6;
        $second->title = 'Second, edited';
        $manager->remove($first);

        $this->transaction->queue(self::insertId(42), self::affected(1), self::affected(1), self::affected(1), self::affected(1), self::affected(1));
        $manager->flush();

        self::assertSame(1, $this->link->begins);
        self::assertCount(2, $this->link->calls, 'no statement ran on the client');
        self::assertSame([
            ['sql' => 'INSERT INTO `tickets` (`subject`) VALUES (?)', 'params' => ['Printer on fire']],
            ['sql' => 'INSERT INTO `documents` (`id`, `title`) VALUES (?, ?)', 'params' => [self::THIRD_UUID, 'Third']],
            ['sql' => 'UPDATE `counted` SET `score` = 6 WHERE `id` = 9', 'params' => []],
            ['sql' => 'UPDATE `counted` SET `score` = 5 WHERE `id` = 10', 'params' => []],
            ['sql' => 'DELETE FROM `documents` WHERE `id` = ?', 'params' => [self::FIRST_UUID]],
            ['sql' => 'UPDATE `documents` SET `title` = ? WHERE `id` = ?', 'params' => ['Second, edited', self::SECOND_UUID]],
        ], $this->transaction->calls);
        self::assertSame(['commit'], $this->transaction->ends);

        self::assertFalse($manager->contains($first));
        self::assertNull($manager->repository(Document::class)->find(self::FIRST_UUID));
        self::assertCount(3, $this->link->calls, 'the deleted identity is released');

        $manager->flush();
        self::assertSame(1, $this->link->begins, 'every written entity is clean');
    }

    public function test_a_generated_identifier_is_assigned_and_registered_only_once_commit_returns(): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId('42'));
        $atCommit = 'unset';
        $this->transaction->onCommit = static function () use ($ticket, &$atCommit): void {
            $atCommit = $ticket->id;
        };

        $manager->flush();

        self::assertNull($atCommit);
        self::assertSame(42, $ticket->id);
        self::assertSame($ticket, $manager->repository(Ticket::class)->find(42));
        self::assertSame([], $this->link->calls);
    }

    /**
     * @return iterable<string, array{int|string|null}>
     */
    public static function refusedGeneratedKeys(): iterable
    {
        yield 'no key' => [null];
        yield 'a non-numeric string' => ['42a'];
        yield 'a key past PHP_INT_MAX' => ['9223372036854775808'];
        yield 'a non-canonical decimal string' => ['042'];
    }

    #[DataProvider('refusedGeneratedKeys')]
    public function test_a_generated_key_outside_the_property_domain_fails_before_commit(int|string|null $key): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId($key));

        try {
            $manager->flush();
            self::fail('The key was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertStringContainsString('reported a generated identifier that is null or not an int', $e->getMessage());
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertNull($ticket->id);
        self::assertTrue($manager->contains($ticket));
    }

    public function test_an_update_affecting_no_row_succeeds_when_the_row_exists(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'score' => 1]]);
        $manager->repository(Counted::class)->findOrFail(1)->score = 2;
        $this->transaction->queue(self::affected(0), [['aggregate' => 1]]);

        $manager->flush();

        self::assertSame([
            'UPDATE `counted` SET `score` = 2 WHERE `id` = 1',
            'SELECT CASE WHEN EXISTS (SELECT * FROM `counted` WHERE `id` = 1) THEN 1 ELSE 0 END AS aggregate',
        ], $this->transaction->statements());
        self::assertSame(['commit'], $this->transaction->ends);

        $manager->flush();
        self::assertSame(1, $this->link->begins);
    }

    /**
     * @return iterable<string, array{bool, list<SqlResult|list<array<string, mixed>>>, string, int}>
     */
    public static function rowIntegrityFailures(): iterable
    {
        yield 'an update of a missing row' => [false, [self::affected(0), [['aggregate' => 0]]], 'does not exist, so its UPDATE affected no row', 2];
        yield 'an update of two rows' => [false, [self::affected(2)], 'The UPDATE of one ' . Counted::class . ' entity by its identifier affected 2 rows', 1];
        yield 'a delete of a missing row' => [true, [self::affected(0)], 'does not exist, so its DELETE affected no row', 1];
        yield 'a delete of two rows' => [true, [self::affected(2)], 'The DELETE of one ' . Counted::class . ' entity by its identifier affected 2 rows', 1];
    }

    /**
     * @param list<SqlResult|list<array<string, mixed>>> $results
     */
    #[DataProvider('rowIntegrityFailures')]
    public function test_a_row_count_that_disagrees_with_the_entity_rolls_back(bool $remove, array $results, string $message, int $statements): void
    {
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'score' => 1]]);
        $counted = $manager->repository(Counted::class)->findOrFail(1);
        $remove ? $manager->remove($counted) : $counted->score = 2;
        $this->transaction->queue(...$results);

        try {
            $manager->flush();
            self::fail('The flush was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertCount($statements, $this->transaction->calls);
        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertFalse($manager->isClosed());

        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::affected(1));
        $manager->flush();

        self::assertSame([$this->transaction->statements()[0]], $retry->statements(), 'the work stayed pending');
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function driverFailures(): iterable
    {
        yield 'a QueryException' => [new QueryException('Duplicate entry', 'UPDATE', null, 1062, '23000')];
        yield 'a ConnectionException' => [new ConnectionException('MySQL connection lost')];
    }

    #[DataProvider('driverFailures')]
    public function test_a_statement_failure_rolls_back_rethrows_it_unwrapped_and_keeps_the_work_for_a_retry(Throwable $failure): void
    {
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'score' => 1]]);
        $counted = $manager->repository(Counted::class)->findOrFail(1);
        $counted->score = 2;
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId(7));
        $statements = 0;
        $this->transaction->onStatement = static function () use (&$statements, $failure): void {
            if (++$statements === 2) {
                throw $failure;
            }
        };

        try {
            $manager->flush();
            self::fail('The flush was accepted.');
        } catch (Throwable $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertFalse($manager->isClosed());
        self::assertTrue($manager->contains($ticket));
        self::assertNull($ticket->id);

        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::insertId(8), self::affected(1));
        $manager->flush();

        self::assertSame(2, $this->link->begins);
        self::assertSame($this->transaction->calls, $retry->calls, 'the retry sent the whole flush again');
        self::assertSame(['commit'], $retry->ends);
        self::assertSame(8, $ticket->id);
    }

    public function test_a_failed_rollback_closes_the_manager_and_keeps_both_failures(): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $failure = new QueryException('Duplicate entry', 'INSERT', null, 1062, '23000');
        $rollbackFailure = new ConnectionException('MySQL connection lost');
        $this->transaction->onStatement = static fn (): never => throw $failure;
        $this->transaction->onRollback = static fn (): never => throw $rollbackFailure;

        try {
            $manager->flush();
            self::fail('The flush was accepted.');
        } catch (RollbackFailedException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertSame($rollbackFailure, $e->rollbackFailure);
        }

        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
    }

    public function test_a_failed_commit_closes_the_manager_with_an_unknown_outcome(): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId(7));
        $failure = new ConnectionException('MySQL connection lost');
        $this->transaction->onCommit = static fn (): never => throw $failure;

        try {
            $manager->flush();
            self::fail('The flush was accepted.');
        } catch (UnknownFlushOutcomeException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString('whether the database applied the flush is unknown', $e->getMessage());
        }

        self::assertSame(['commit'], $this->transaction->ends);
        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
    }

    public function test_a_committed_snapshot_holds_the_values_sent_not_a_later_change(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([Article::row(['featured' => '1', 'rating' => '4.5', 'priority' => '2'])]);
        $article = $manager->repository(Article::class)->findOrFail(1);
        $article->summary = 'Lead';
        $article->status = ArticleStatus::Draft;
        $article->priority = null;
        $article->featured = false;
        $article->rating = 5.0;
        $new = new StoredArticle();
        [$new->id, $new->title, $new->summary, $new->status, $new->priority, $new->featured, $new->rating, $new->authorId]
            = [4, 'Fourth', null, ArticleStatus::Published, Priority::High, true, 1.5, 8];
        $manager->persist($new);
        $this->transaction->queue(self::affected(1), self::affected(1));
        $this->transaction->onCommit = static function () use ($article): void {
            $article->summary = 'Changed while COMMIT was answered';
        };

        $manager->flush();

        self::assertSame([
            [
                'sql' => 'INSERT INTO `kin_orm_articles` (`id`, `title`, `summary`, `status`, `priority`, `featured`, `rating`, `author_id`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'params' => [4, 'Fourth', null, 'published', 2, true, 1.5, 8],
            ],
            [
                'sql' => 'UPDATE `articles` SET `summary` = ?, `status` = ?, `priority` = ?, `featured` = ?, `rating` = ? WHERE `id` = ?',
                'params' => ['Lead', 'draft', null, false, 5.0, 1],
            ],
        ], $this->transaction->calls);

        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1));
        $manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `articles` SET `summary` = ? WHERE `id` = ?', 'params' => ['Changed while COMMIT was answered', 1]]],
            $next->calls,
        );
    }

    public function test_an_insert_writes_the_version_the_entity_holds_and_does_not_advance_it(): void
    {
        $manager = $this->factory->open();
        $invoice = new Invoice();
        [$invoice->id, $invoice->status, $invoice->version, $invoice->total] = [1, 'open', 7, 100];
        $manager->persist($invoice);
        $this->transaction->queue(self::affected(1));

        $manager->flush();
        $manager->flush();

        self::assertSame(
            [['sql' => 'INSERT INTO `invoices` (`id`, `status`, `row_version`, `total`) VALUES (?, ?, ?, ?)', 'params' => [1, 'open', 7, 100]]],
            $this->transaction->calls,
        );
        self::assertSame(7, $invoice->version);
        self::assertSame(1, $this->link->begins, 'a clean versioned entity writes nothing');

        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1));
        $invoice->status = 'paid';
        $manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `invoices` SET `status` = ?, `row_version` = ? WHERE `id` = ? AND `row_version` = ?', 'params' => ['paid', 8, 1, 7]]],
            $next->calls,
        );
    }

    public function test_a_version_changed_during_the_commit_of_its_insert_is_restored(): void
    {
        $manager = $this->factory->open();
        $invoice = new Invoice();
        [$invoice->id, $invoice->status, $invoice->version, $invoice->total] = [1, 'open', 7, 100];
        $manager->persist($invoice);
        $this->transaction->queue(self::affected(1));
        $this->transaction->onCommit = static function () use ($invoice): void {
            $invoice->version = 99;
        };

        $manager->flush();

        self::assertSame(7, $invoice->version, 'the version the INSERT sent');

        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1));
        $invoice->status = 'paid';
        $manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `invoices` SET `status` = ?, `row_version` = ? WHERE `id` = ? AND `row_version` = ?', 'params' => ['paid', 8, 1, 7]]],
            $next->calls,
        );
        self::assertSame(8, $invoice->version);
    }

    public function test_a_versioned_update_sets_the_next_version_where_the_loaded_one_still_holds(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, '3')]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $invoice->status = 'paid';
        $this->transaction->queue(self::affected(1));
        $atCommit = null;
        $this->transaction->onCommit = static function () use ($invoice, &$atCommit): void {
            $atCommit = $invoice->version;
        };

        $manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `invoices` SET `status` = ?, `row_version` = ? WHERE `id` = ? AND `row_version` = ?', 'params' => ['paid', 4, 1, 3]]],
            $this->transaction->calls,
        );
        self::assertSame(3, $atCommit);
        self::assertSame(4, $invoice->version);

        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1));
        $invoice->total = 120;
        $manager->flush();

        self::assertSame(['UPDATE `invoices` SET `total` = 120, `row_version` = 5 WHERE `id` = 1 AND `row_version` = 4'], $next->statements());
    }

    public function test_a_versioned_delete_matches_the_loaded_version(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3)]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $manager->remove($invoice);
        $this->transaction->queue(self::affected(1));

        $manager->flush();

        self::assertSame(['DELETE FROM `invoices` WHERE `id` = 1 AND `row_version` = 3'], $this->transaction->statements());
        self::assertFalse($manager->contains($invoice));
    }

    public function test_a_property_named_version_without_the_attribute_is_written_like_any_other(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'version' => 1]]);
        $edition = $manager->repository(Edition::class)->findOrFail(1);
        $edition->version = 5;
        $this->transaction->queue(self::affected(1));

        $manager->flush();

        self::assertSame(['UPDATE `editions` SET `version` = 5 WHERE `id` = 1'], $this->transaction->statements());
        self::assertSame(5, $edition->version);
    }

    /**
     * @return iterable<string, array{bool, 'UPDATE'|'DELETE'}>
     */
    public static function staleWrites(): iterable
    {
        yield 'a stale update' => [false, 'UPDATE'];
        yield 'a stale delete' => [true, 'DELETE'];
    }

    /**
     * @param 'UPDATE'|'DELETE' $statement
     */
    #[DataProvider('staleWrites')]
    public function test_a_stale_write_rolls_back_and_keeps_the_whole_flush_pending_until_it_is_cleared(bool $remove, string $statement): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3), self::invoiceRow(2, 3)]);
        [$first, $second] = $manager->repository(Invoice::class)->query()->get();
        $remove ? $manager->remove($first) : $first->status = 'paid';
        $second->status = 'void';
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId(7), self::affected(0));

        self::assertConflict($manager, $statement);

        self::assertCount(2, $this->transaction->calls, 'the INSERT, then the stale statement');
        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertFalse($manager->isClosed());
        self::assertSame([3, 3], [$first->version, $second->version]);
        self::assertNull($ticket->id);
        self::assertTrue($manager->contains($first));

        $this->link->transaction = $retry = new SpyMysqlTransaction();
        $retry->queue(self::insertId(8), self::affected(0));

        self::assertConflict($manager, $statement);
        self::assertSame($this->transaction->calls, $retry->calls, 'the retry sent the whole flush again');

        $manager->clear();
        $manager->flush();
        self::assertSame(2, $this->link->begins, 'clear() abandoned the stale work');
    }

    public function test_a_versioned_update_affecting_no_row_conflicts_without_an_existence_check(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3)]);
        $manager->repository(Invoice::class)->findOrFail(1)->total = 120;
        $this->transaction->queue(self::affected(0), [['aggregate' => 1]]);

        self::assertConflict($manager, 'UPDATE');

        self::assertSame(['UPDATE `invoices` SET `total` = 120, `row_version` = 4 WHERE `id` = 1 AND `row_version` = 3'], $this->transaction->statements());
        self::assertSame(['rollback'], $this->transaction->ends);
    }

    public function test_a_version_the_application_changed_is_refused_before_the_transaction_begins(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3)]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $invoice->version = 4;

        try {
            $manager->flush();
            self::fail('The changed version was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(InvalidEntityStateException::versionChanged(Invoice::class)->getMessage(), $e->getMessage());
        }

        self::assertSame(0, $this->link->begins);
    }

    public function test_an_update_that_cannot_advance_the_version_is_refused_before_the_transaction_and_a_delete_is_not(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, (string) PHP_INT_MAX)]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $invoice->total = 120;

        try {
            $manager->flush();
            self::fail('The exhausted version was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(InvalidEntityStateException::versionExhausted(Invoice::class)->getMessage(), $e->getMessage());
        }

        self::assertSame(0, $this->link->begins);

        $manager->remove($invoice);
        $this->transaction->queue(self::affected(1));
        $manager->flush();

        self::assertSame(['DELETE FROM `invoices` WHERE `id` = 1 AND `row_version` = ' . PHP_INT_MAX], $this->transaction->statements());
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_a_failed_commit_leaves_the_version_unchanged_with_an_unknown_outcome(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3)]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $invoice->status = 'paid';
        $this->transaction->queue(self::affected(1));
        $failure = new ConnectionException('MySQL connection lost');
        $this->transaction->onCommit = static fn (): never => throw $failure;

        try {
            $manager->flush();
            self::fail('The flush was accepted.');
        } catch (UnknownFlushOutcomeException $e) {
            self::assertSame($failure, $e->getPrevious());
        }

        self::assertSame(3, $invoice->version);
        self::assertTrue($manager->isClosed());
    }

    public function test_a_version_changed_during_commit_is_overwritten_while_a_business_change_stays_pending(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([self::invoiceRow(1, 3)]);
        $invoice = $manager->repository(Invoice::class)->findOrFail(1);
        $invoice->status = 'paid';
        $this->transaction->queue(self::affected(1));
        $this->transaction->onCommit = static function () use ($invoice): void {
            $invoice->version = 99;
            $invoice->status = 'void';
        };

        $manager->flush();

        self::assertSame(4, $invoice->version);

        $this->link->transaction = $next = new SpyMysqlTransaction();
        $next->queue(self::affected(1));
        $manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `invoices` SET `status` = ?, `row_version` = ? WHERE `id` = ? AND `row_version` = ?', 'params' => ['void', 5, 1, 4]]],
            $next->calls,
        );
    }

    public function test_reentry_from_the_flushing_fiber_is_refused_before_sql_or_state_changes(): void
    {
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'score' => 1]]);
        $counted = $manager->repository(Counted::class)->findOrFail(1);
        $counted->score = 2;
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $stranger = new Ticket('Re-entered');
        $refusals = [];
        $reenter = static function () use ($manager, $counted, $ticket, $stranger, &$refusals): void {
            foreach ([
                static fn (): mixed => $manager->flush(),
                static fn (): mixed => $manager->persist($stranger),
                static fn (): mixed => $manager->remove($counted),
                static fn (): mixed => $manager->clear(),
                static fn (): mixed => $manager->contains($ticket),
                static fn (): mixed => $manager->repository(Counted::class),
            ] as $use) {
                try {
                    $use();
                    $refusals[] = 'accepted';
                } catch (Throwable $e) {
                    $refusals[] = $e::class;
                }
            }
        };
        $this->link->onBegin = $reenter;
        $this->transaction->onStatement = $reenter;
        $this->transaction->onCommit = $reenter;
        $this->transaction->queue(self::insertId(3), self::affected(1));

        $manager->flush();

        self::assertSame(array_fill(0, 24, InvalidEntityStateException::class), $refusals);
        self::assertSame(1, $this->link->begins);
        self::assertCount(2, $this->transaction->calls);
        self::assertSame(['commit'], $this->transaction->ends);
        self::assertFalse($manager->contains($stranger));
        self::assertTrue($manager->contains($counted));
        self::assertSame(3, $ticket->id);
    }

    public function test_another_fiber_is_refused_while_a_flush_is_suspended(): void
    {
        [$fiber, $manager, $ticket] = $this->flushInFiber();
        $this->transaction->onStatement = static fn (): mixed => Fiber::suspend();
        $this->transaction->queue(self::insertId(3));

        $fiber->resume();
        self::assertCount(1, $this->transaction->calls, 'the flush is suspended in its insert');

        foreach ([
            static fn (): mixed => $manager->flush(),
            static fn (): mixed => $manager->persist(new Ticket('From another Fiber')),
            static fn (): mixed => $manager->contains($ticket),
            static fn (): mixed => $manager->clear(),
        ] as $use) {
            self::assertThrows(CrossFiberAccessException::class, $use);
        }

        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertNull($fiber->getReturn());
        self::assertCount(1, $this->transaction->calls);
        self::assertSame(['commit'], $this->transaction->ends);
        self::assertSame(3, $ticket->id);
    }

    public function test_a_close_during_begin_rolls_back_the_transaction_and_sends_nothing(): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->link->onBegin = static fn (): mixed => $manager->close();

        self::assertThrows(ClosedEntityManagerException::class, static fn (): mixed => $manager->flush());

        self::assertSame([], $this->transaction->calls);
        self::assertSame(['rollback'], $this->transaction->ends);
        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
    }

    public function test_a_close_from_another_fiber_during_a_statement_closes_the_transaction_and_fails_the_flush(): void
    {
        [$fiber, $manager, $ticket] = $this->flushInFiber();
        $transaction = $this->transaction;
        $transaction->onStatement = static function () use ($transaction): void {
            Fiber::suspend();

            if (!$transaction->isActive()) {
                throw new ConnectionException('The connection was discarded.');
            }
        };

        $fiber->resume();
        $manager->close();
        self::assertSame(['close'], $transaction->ends);
        $fiber->resume();

        self::assertInstanceOf(ConnectionException::class, $fiber->getReturn());
        self::assertSame(['close', 'rollback'], $transaction->ends);
        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
    }

    public function test_a_close_from_another_fiber_during_commit_leaves_the_outcome_unknown(): void
    {
        [$fiber, $manager, $ticket] = $this->flushInFiber();
        $transaction = $this->transaction;
        $transaction->queue(self::insertId(3));
        $transaction->onCommit = static function () use ($transaction): void {
            Fiber::suspend();

            if (!$transaction->isActive()) {
                throw new ConnectionException('The connection was discarded.');
            }
        };

        $fiber->resume();
        $manager->close();
        $fiber->resume();

        self::assertInstanceOf(UnknownFlushOutcomeException::class, $fiber->getReturn());
        self::assertSame(['commit', 'close'], $transaction->ends);
        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
    }

    public function test_a_commit_that_returns_after_close_changes_nothing(): void
    {
        $manager = $this->factory->open();
        $ticket = new Ticket('Printer on fire');
        $manager->persist($ticket);
        $this->transaction->queue(self::insertId(3));
        $this->transaction->onCommit = static fn (): mixed => $manager->close();

        $manager->flush();

        self::assertTrue($manager->isClosed());
        self::assertNull($ticket->id);
        self::assertSame(['commit', 'close'], $this->transaction->ends);
    }

    /**
     * A manager opened in a Fiber holding one new ticket. The Fiber
     * suspends before flushing and returns what the flush threw, or null.
     *
     * @return array{Fiber<mixed, mixed, ?Throwable, mixed>, EntityManager, Ticket}
     */
    private function flushInFiber(): array
    {
        $ticket = new Ticket('Printer on fire');
        $fiber = new Fiber(function () use ($ticket): ?Throwable {
            $manager = $this->factory->open();
            $manager->persist($ticket);
            Fiber::suspend($manager);

            try {
                $manager->flush();
            } catch (Throwable $e) {
                return $e;
            }

            return null;
        });
        $manager = $fiber->start();
        self::assertInstanceOf(EntityManager::class, $manager);

        return [$fiber, $manager, $ticket];
    }

    private static function document(string $id, string $title): Document
    {
        $document = new Document();
        $document->id = $id;
        $document->title = $title;

        return $document;
    }

    /**
     * @return array<string, int|string>
     */
    private static function invoiceRow(int $id, int|string $version): array
    {
        return ['id' => $id, 'status' => 'open', 'row_version' => $version, 'total' => 100];
    }

    /**
     * The flush throws exactly OptimisticLockException, naming the class and
     * statement and no value.
     */
    private static function assertConflict(EntityManager $manager, string $statement): void
    {
        try {
            $manager->flush();
        } catch (OptimisticLockException $e) {
            self::assertSame(OptimisticLockException::stale(Invoice::class, $statement)->getMessage(), $e->getMessage());

            return;
        }

        self::fail('The stale write was accepted.');
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }

    private static function insertId(int|string|null $id): SqlResult
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
