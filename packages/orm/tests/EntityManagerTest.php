<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Fiber;
use InvalidArgumentException;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityPlan;
use Kinetis\Orm\EntityQuery;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\CrossFiberAccessException;
use Kinetis\Orm\Exception\EntityNotFoundException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Account;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Counted;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Note;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\PostgresTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class EntityManagerTest extends TestCase
{
    private const string UUID = '3f2c8a4e-9b1d-4c7a-8e5f-0a1b2c3d4e5f';

    private SpyMysqlLink $link;

    private OrmFactory $factory;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->factory = OrmFactory::create(
            $this->link,
            MetadataRegistry::fromClasses([Article::class, Document::class, Counted::class, Note::class]),
        );
    }

    /**
     * @return iterable<string, array{class-string<MysqlTransaction|PostgresTransaction>}>
     */
    public static function transactions(): iterable
    {
        yield 'a MySQL transaction' => [MysqlTransaction::class];
        yield 'a PostgreSQL transaction' => [PostgresTransaction::class];
    }

    /**
     * A transaction carries its link's dialect marker, so the parameter
     * type alone admits one.
     *
     * @param class-string<MysqlTransaction|PostgresTransaction> $contract
     */
    #[DataProvider('transactions')]
    public function test_an_active_transaction_cannot_create_a_factory(string $contract): void
    {
        $transaction = $this->createMock($contract);
        $transaction->method('isActive')->willReturn(true);
        $transaction->expects(self::never())->method('query');
        $transaction->expects(self::never())->method('execute');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OrmFactory::create() was given a transaction');

        OrmFactory::create($transaction, MetadataRegistry::fromClasses([Article::class]));
    }

    public function test_hydration_bypasses_the_constructor_and_writes_every_visibility(): void
    {
        $this->link->queue([Article::row(['summary' => 'Lead', 'priority' => 2, 'featured' => '1', 'extra_column' => 'ignored'])]);

        $article = $this->factory->open()->repository(Article::class)->find(1);

        self::assertInstanceOf(Article::class, $article);
        self::assertSame(1, $article->id());
        self::assertSame('First', $article->title());
        self::assertSame('Lead', $article->summary);
        self::assertSame(ArticleStatus::Published, $article->status);
        self::assertSame(Priority::High, $article->priority);
        self::assertTrue($article->featured);
        self::assertSame(4.5, $article->rating);
        self::assertSame(7, $article->authorId);
        self::assertSame('first', $article->slug());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function admittedValues(): iterable
    {
        yield 'an int' => [['author' => 12], 'authorId', 12];
        yield 'a canonical int string' => [['author' => '12'], 'authorId', 12];
        yield 'a negative int string' => [['author' => '-3'], 'authorId', -3];
        yield 'a float from an int' => [['rating' => 4], 'rating', 4.0];
        yield 'a float' => [['rating' => 2.25], 'rating', 2.25];
        yield 'a float from a numeric string' => [['rating' => '1e3'], 'rating', 1000.0];
        yield 'a bool' => [['featured' => true], 'featured', true];
        yield 'a bool from 1' => [['featured' => 1], 'featured', true];
        yield 'a bool from "0"' => [['featured' => '0'], 'featured', false];
        yield 'a string-backed enum value' => [['status' => 'draft'], 'status', ArticleStatus::Draft];
        yield 'an enum case' => [['status' => ArticleStatus::Draft], 'status', ArticleStatus::Draft];
        yield 'an int-backed enum value' => [['priority' => 1], 'priority', Priority::Low];
        yield 'an int-backed enum from a canonical string' => [['priority' => '2'], 'priority', Priority::High];
        yield 'null for a nullable string' => [['summary' => null], 'summary', null];
        yield 'null for a nullable enum' => [['priority' => null], 'priority', null];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('admittedValues')]
    public function test_an_admitted_driver_value_converts_to_the_property_type(array $overrides, string $property, mixed $expected): void
    {
        $this->link->queue([Article::row($overrides)]);

        $article = $this->factory->open()->repository(Article::class)->find(1);

        self::assertNotNull($article);
        self::assertSame($expected, $article->{$property});
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedValues(): iterable
    {
        $int = Article::class . '::$authorId takes an int or its canonical decimal string, got ';
        $float = Article::class . '::$rating takes a finite int, float or numeric string, got ';
        $bool = Article::class . '::$featured takes a bool, 0, 1, "0" or "1", got ';

        yield 'an int with a leading zero' => [['author' => '012'], "{$int}string."];
        yield 'an int with a plus sign' => [['author' => '+1'], "{$int}string."];
        yield 'an int with whitespace' => [['author' => ' 1'], "{$int}string."];
        yield 'an int with a fraction' => [['author' => '1.0'], "{$int}string."];
        yield 'an int out of range' => [['author' => '9223372036854775808'], "{$int}string."];
        yield 'an int from a float' => [['author' => 1.0], "{$int}float."];
        yield 'an int from a bool' => [['author' => true], "{$int}bool."];
        yield 'a non-numeric float' => [['rating' => 'high'], "{$float}string."];
        yield 'an infinite float' => [['rating' => INF], "{$float}float."];
        yield 'a float overflowing to infinity' => [['rating' => '1e999'], "{$float}string."];
        yield 'a string from an int' => [['title' => 5], Article::class . '::$title takes a string, got int.'];
        yield 'a bool from 2' => [['featured' => 2], "{$bool}int."];
        yield 'a bool from "t"' => [['featured' => 't'], "{$bool}string."];
        yield 'an unknown backing value' => [['status' => 'archived'], '::$status takes a ' . ArticleStatus::class . ' case, and the string value names none.'];
        yield 'another enum\'s case' => [['status' => Priority::Low], '::$status takes a ' . ArticleStatus::class . ' case or its backing value as a string, got ' . Priority::class . '.'];
        yield 'a non-canonical int backing value' => [['priority' => 'high'], '::$priority takes a ' . Priority::class . ' case or its backing value as an int or its canonical decimal string, or null, got string.'];
        yield 'null for a non-nullable property' => [['title' => null], Article::class . '::$title takes a string, got null.'];
        yield 'a null identifier' => [['id' => null], Article::class . '::$id takes an int or its canonical decimal string, got null.'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('refusedValues')]
    public function test_a_refused_driver_value_fails_and_leaves_nothing_managed(array $overrides, string $message): void
    {
        $repository = $this->factory->open()->repository(Article::class);
        $this->link->queue([Article::row($overrides)], [Article::row()]);

        try {
            $repository->find(1);
            self::fail('The row was accepted.');
        } catch (MappingException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertNotNull($repository->find(1));
        self::assertCount(2, $this->link->calls, 'a failed row registers no identity, so the next find() queries again');
    }

    public function test_a_null_identifier_is_refused_even_where_the_type_admits_null(): void
    {
        $this->link->queue([['id' => null, 'body' => 'text']]);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('A row loaded for ' . Note::class . ' has a null identifier.');

        $this->factory->open()->repository(Note::class)->query()->get();
    }

    public function test_a_missing_mapped_column_fails(): void
    {
        $row = Article::row();
        unset($row['slug']);
        $this->link->queue([$row]);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage('A row loaded for ' . Article::class . ' has no "slug" column for ' . Article::class . '::$slug.');

        $this->factory->open()->repository(Article::class)->find(1);
    }

    /**
     * Every row of a result converts before any entity is allocated: an
     * allocated-then-discarded Counted would run its destructor.
     */
    public function test_a_result_with_a_failing_row_allocates_no_entity(): void
    {
        Counted::$destroyed = 0;
        $manager = $this->factory->open();
        $this->link->queue([['id' => 1, 'score' => 10], ['id' => 2, 'score' => 'ten']]);

        try {
            $manager->repository(Counted::class)->query()->get();
            self::fail('The result was accepted.');
        } catch (MappingException) {
        }

        gc_collect_cycles();
        self::assertSame(0, Counted::$destroyed, 'no entity of the failed result was allocated and dropped');

        $this->link->queue([['id' => 1, 'score' => 10]]);
        self::assertNotNull($manager->repository(Counted::class)->find(1));
        self::assertCount(2, $this->link->calls, 'no identity of the failed result was registered');

        $manager->clear();
        gc_collect_cycles();
        self::assertSame(1, Counted::$destroyed, 'the one Counted ever allocated is the one find() loaded');
    }

    public function test_an_int_identity_is_reused_across_find_and_query_terminals(): void
    {
        $manager = $this->factory->open();
        $repository = $manager->repository(Article::class);
        $this->link->queue([Article::row()], [Article::row(), Article::row(['id' => 2, 'slug' => 'second'])]);

        $article = $repository->find(1);

        self::assertSame($article, $repository->find(1));
        self::assertSame($article, $repository->find('1'));
        self::assertCount(1, $this->link->calls, 'a held identity returns without SQL');

        [$first, $second] = $repository->query()->get();

        self::assertSame($article, $first);
        self::assertSame(2, $second->id());
        self::assertSame($second, $repository->find(2));
        self::assertTrue($manager->contains($article));
        self::assertFalse($manager->contains(new \stdClass()));
        self::assertCount(2, $this->link->calls);
    }

    public function test_a_uuid_identity_is_keyed_by_the_identifier_the_database_returned(): void
    {
        $manager = $this->factory->open();
        $repository = $manager->repository(Document::class);
        $row = ['id' => self::UUID, 'title' => 'Spec'];
        $this->link->queue([$row], [$row]);

        $document = $repository->find(self::UUID);

        self::assertInstanceOf(Document::class, $document);
        self::assertSame($document, $repository->find(self::UUID));
        self::assertCount(1, $this->link->calls);

        // A differently cased lookup is not the same key, so it queries; the
        // row it returns carries the stored identifier, already held.
        self::assertSame($document, $repository->find(strtoupper(self::UUID)));
        self::assertCount(2, $this->link->calls);
        self::assertSame([strtoupper(self::UUID)], $this->link->calls[1]['params']);
    }

    public function test_a_later_row_for_a_held_identity_never_overwrites_it(): void
    {
        $repository = $this->factory->open()->repository(Article::class);
        $this->link->queue([Article::row()], [Article::row(['title' => 'Changed in the database', 'summary' => 'new'])]);

        $article = $repository->find(1);
        self::assertNotNull($article);
        $article->summary = 'edited in memory';

        self::assertSame($article, $repository->query()->first());
        self::assertSame('edited in memory', $article->summary);
        self::assertSame('First', $article->title());
    }

    public function test_clear_detaches_every_entity_without_io(): void
    {
        $manager = $this->factory->open();
        $repository = $manager->repository(Article::class);
        $this->link->queue([Article::row()], [Article::row()]);

        $before = $repository->find(1);
        self::assertNotNull($before);
        $manager->clear();

        self::assertCount(1, $this->link->calls);
        self::assertFalse($manager->contains($before));

        $after = $repository->find(1);

        self::assertNotSame($before, $after);
        self::assertTrue($manager->contains($after));
        self::assertCount(2, $this->link->calls);
    }

    /**
     * @return iterable<string, array{Closure(EntityManager): mixed}>
     */
    public static function uses(): iterable
    {
        yield 'repository()' => [static fn (EntityManager $manager): mixed => $manager->repository(Article::class)];
        yield 'contains()' => [static fn (EntityManager $manager): mixed => $manager->contains(new \stdClass())];
        yield 'clear()' => [static fn (EntityManager $manager): mixed => $manager->clear()];
        yield 'persist()' => [static fn (EntityManager $manager): mixed => $manager->persist(new Document())];
        yield 'remove()' => [static fn (EntityManager $manager): mixed => $manager->remove(new Document())];
        yield 'flush()' => [static fn (EntityManager $manager): mixed => $manager->flush()];
    }

    /**
     * @param Closure(EntityManager): mixed $use
     */
    #[DataProvider('uses')]
    public function test_a_closed_manager_refuses_every_use(Closure $use): void
    {
        $manager = $this->factory->open();
        $manager->close();

        $this->expectException(ClosedEntityManagerException::class);

        $use($manager);
    }

    public function test_close_is_idempotent_detaches_and_refuses_later_reads_before_sql_without_closing_the_link(): void
    {
        $manager = $this->factory->open();
        $repository = $manager->repository(Article::class);
        $query = $repository->query();
        $plan = self::articlePlan();
        $this->link->queue([Article::row()]);
        $repository->find(1);

        $manager->close();
        $manager->close();

        self::assertTrue($manager->isClosed());
        self::assertSame(0, $this->link->closeCalls);

        foreach ([
            static fn (): mixed => $manager->select($plan),
            static fn (): mixed => $manager->managed($plan, 1),
            static fn (): mixed => $repository->find(1),
            static fn (): mixed => $repository->findBy([]),
            static fn (): mixed => $repository->query(),
            static fn (): mixed => $query->where('id', '=', 1),
            static fn (): mixed => $query->get(),
            static fn (): mixed => $query->count(),
            static fn (): mixed => $query->builder(),
        ] as $read) {
            self::assertThrows(ClosedEntityManagerException::class, $read);
        }

        self::assertCount(1, $this->link->calls);
    }

    public function test_a_manager_used_from_another_fiber_refuses_before_sql(): void
    {
        $manager = $this->factory->open();
        $repository = $manager->repository(Article::class);
        $query = $repository->query();
        $plan = self::articlePlan();

        foreach ([
            static fn (): mixed => $manager->select($plan),
            static fn (): mixed => $manager->managed($plan, 1),
            static fn (): mixed => $manager->repository(Article::class),
            static fn (): mixed => $manager->contains(new \stdClass()),
            static fn (): mixed => $manager->clear(),
            static fn (): mixed => $manager->persist(new Document()),
            static fn (): mixed => $manager->remove(new Document()),
            static fn (): mixed => $manager->flush(),
            static fn (): mixed => $repository->find(1),
            static fn (): mixed => $query->where('id', '=', 1),
            static fn (): mixed => $query->get(),
            static fn (): mixed => $query->exists(),
        ] as $use) {
            $fiber = new Fiber(static fn (): mixed => self::assertThrows(CrossFiberAccessException::class, $use));
            $fiber->start();
            self::assertTrue($fiber->isTerminated());
        }

        self::assertSame([], $this->link->calls);
    }

    public function test_a_manager_opened_in_a_fiber_works_there_only(): void
    {
        $this->link->queue([Article::row()]);
        $fiber = new Fiber(function (): EntityManager {
            $manager = $this->factory->open();
            self::assertNotNull($manager->repository(Article::class)->find(1));

            return $manager;
        });
        $fiber->start();
        $manager = $fiber->getReturn();

        self::assertInstanceOf(EntityManager::class, $manager);
        self::assertThrows(CrossFiberAccessException::class, static fn (): mixed => $manager->repository(Article::class));
        self::assertCount(1, $this->link->calls);
    }

    public function test_concurrent_fibers_hold_separate_identities(): void
    {
        $this->link->queue([Article::row()], [Article::row()]);
        $loaded = [];

        foreach ([0, 1] as $i) {
            $fiber = new Fiber(function () use (&$loaded, $i): void {
                $manager = $this->factory->open();
                $article = $manager->repository(Article::class)->find(1);
                Fiber::suspend();
                self::assertSame($article, $manager->repository(Article::class)->find(1));
                $loaded[$i] = $article;
            });
            $fiber->start();
            $fibers[] = $fiber;
        }

        foreach ($fibers as $fiber) {
            $fiber->resume();
        }

        self::assertNotSame($loaded[0], $loaded[1]);
        self::assertCount(2, $this->link->calls);
    }

    /**
     * @return iterable<string, array{Closure(EntityQuery<Article>): mixed, list<array<string, mixed>>}>
     */
    public static function suspendedTerminals(): iterable
    {
        yield 'get() with a row' => [static fn (EntityQuery $query): mixed => $query->get(), [Article::row()]];
        yield 'first() with no row' => [static fn (EntityQuery $query): mixed => $query->first(), []];
        yield 'exists()' => [static fn (EntityQuery $query): mixed => $query->exists(), [['aggregate' => 1]]];
        yield 'count()' => [static fn (EntityQuery $query): mixed => $query->count(), [['aggregate' => 3]]];
    }

    /**
     * close() is how the owner of a unit of work ends it, from whichever
     * Fiber that is; a terminal suspended in its SQL at that moment answers
     * nothing when it resumes.
     *
     * @param Closure(EntityQuery<Article>): mixed $terminal
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('suspendedTerminals')]
    public function test_a_terminal_suspended_in_sql_while_its_manager_closes_answers_nothing(Closure $terminal, array $rows): void
    {
        $refused = false;
        $fiber = new Fiber(function () use ($terminal, &$refused): void {
            $manager = $this->factory->open();
            $query = $manager->repository(Article::class)->query();
            Fiber::suspend($manager);

            try {
                $terminal($query);
            } catch (ClosedEntityManagerException) {
                $refused = true;
            }
        });

        $manager = $fiber->start();
        self::assertInstanceOf(EntityManager::class, $manager);

        $this->link->queue($rows);
        $this->link->onStatement = static fn (): mixed => Fiber::suspend();
        $fiber->resume();
        self::assertCount(1, $this->link->calls, 'the terminal is suspended inside its statement');

        $manager->close();
        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertTrue($refused, 'the result that arrived after close() was not returned');
    }

    public function test_an_entity_outside_the_metadata_is_refused(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Account::class . ' is not an entity in this OrmFactory\'s MetadataRegistry.');

        $this->factory->open()->repository(Account::class);
    }

    public function test_an_inadmissible_identifier_fails_before_sql(): void
    {
        self::assertThrows(MappingException::class, fn (): mixed => $this->factory->open()->repository(Article::class)->find('one'));
        self::assertThrows(MappingException::class, fn (): mixed => $this->factory->open()->repository(Document::class)->find(5));
        self::assertSame([], $this->link->calls);
    }

    public function test_find_or_fail_names_the_class_and_not_the_identifier(): void
    {
        try {
            $this->factory->open()->repository(Document::class)->findOrFail(self::UUID);
            self::fail('No exception.');
        } catch (EntityNotFoundException $e) {
            self::assertSame('No ' . Document::class . ' entity exists with the requested identifier.', $e->getMessage());
        }
    }

    public function test_find_by_matches_every_criterion_and_refuses_an_unknown_property_before_sql(): void
    {
        $repository = $this->factory->open()->repository(Article::class);
        $this->link->queue([Article::row()]);

        $articles = $repository->findBy(['authorId' => 7, 'status' => ArticleStatus::Published]);

        self::assertCount(1, $articles);
        self::assertStringEndsWith('WHERE `author` = ? AND `status` = ?', $this->link->calls[0]['sql']);
        self::assertSame([7, 'published'], $this->link->calls[0]['params']);

        self::assertThrows(MappingException::class, static fn (): mixed => $repository->findBy(['author' => 7]));
        self::assertCount(1, $this->link->calls);
    }

    /**
     * @return EntityPlan<Article>
     */
    private static function articlePlan(): EntityPlan
    {
        return new EntityPlan(MetadataRegistry::fromClasses([Article::class])->toArray()['entities'][0]);
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
