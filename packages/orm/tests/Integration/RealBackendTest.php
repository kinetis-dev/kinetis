<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Integration;

use Kinetis\Orm\EntityRepository;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\StoredArticle;
use Kinetis\Orm\Tests\Fixtures\StoredDocument;
use Kinetis\Orm\Tests\Fixtures\StoredInvoice;
use Kinetis\Orm\Tests\Fixtures\StoredTicket;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entity reads and flushes against real servers, through both the native
 * and the PDO driver of each family: every row spelling those drivers
 * produce for ints, floats, booleans, nulls and UUID text passes the
 * conversion domain, and every write, generated key, affected-row count
 * and constraint failure is the server's own.
 *
 * Environment-gated: each case skips unless MYSQL_HOST or POSTGRES_HOST is
 * set and its driver's extension is loaded. CI's integration workflow runs
 * it against MySQL 8.4, MariaDB 11.4 and PostgreSQL 16.
 */
final class RealBackendTest extends TestCase
{
    private const string UUID = '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    private const string NEW_UUID = '0b7e3f52-8c4d-4a1e-9f6b-2d5c8e1a7b30';

    private null|MysqlLink|PostgresLink $link = null;

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->link = null;
    }

    /**
     * @return iterable<string, array{'mysql'|'pgsql', 'native'|'pdo'}>
     */
    public static function drivers(): iterable
    {
        yield 'MySQL family, native' => ['mysql', 'native'];
        yield 'MySQL family, PDO' => ['mysql', 'pdo'];
        yield 'PostgreSQL, native' => ['pgsql', 'native'];
        yield 'PostgreSQL, PDO' => ['pgsql', 'pdo'];
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_entities_hydrate_from_the_driver_row_spellings(string $dialect, string $driver): void
    {
        $articles = $this->factory($dialect, $driver)->open()->repository(StoredArticle::class);

        $first = $articles->find(1);
        $second = $articles->findOrFail(2);

        self::assertNotNull($first);
        self::assertSame([1, 'First', null, ArticleStatus::Published, null, true, 4.5, 7], self::values($first));
        self::assertSame([2, 'Second', 'Lead', ArticleStatus::Draft, Priority::High, false, 3.0, 8], self::values($second));
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_converted_predicates_and_every_terminal_share_one_identity_map(string $dialect, string $driver): void
    {
        $articles = $this->factory($dialect, $driver)->open()->repository(StoredArticle::class);
        $first = $articles->find(1);

        self::assertSame([$first], $articles->query()->where('featured', '=', true)->where('status', '=', ArticleStatus::Published)->get());
        self::assertSame([2], array_map(
            static fn (StoredArticle $article): int => $article->id,
            $articles->query()->whereIn('priority', [Priority::High])->where('summary', '!=', null)->get(),
        ));
        self::assertSame(3, $articles->query()->count());
        self::assertTrue($articles->query()->where('authorId', '=', '8')->exists());

        $page = $articles->query()->orderBy('id')->paginate(2);
        self::assertSame([3, 2], [$page->total, $page->lastPage]);
        self::assertSame($first, $page->data[0]);

        $cursor = $articles->query()->cursorPaginate(2, null);
        self::assertSame($page->data, $cursor->data);
        self::assertTrue($cursor->hasMore);

        $next = $articles->query()->cursorPaginate(2, $cursor->nextCursor);
        self::assertSame([3], array_map(static fn (StoredArticle $article): int => $article->id, $next->data));
        self::assertFalse($next->hasMore);
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_uuid_identity_follows_the_identifier_the_database_returns(string $dialect, string $driver): void
    {
        /** @var EntityRepository<StoredDocument> $documents */
        $documents = $this->factory($dialect, $driver)->open()->repository(StoredDocument::class);

        $document = $documents->find(self::UUID);

        self::assertNotNull($document);
        self::assertSame(self::UUID, $document->id);
        self::assertSame($document, $documents->find(strtoupper(self::UUID)));
        self::assertSame([$document], $documents->findBy(['title' => 'Spec']));
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_flush_inserts_updates_and_deletes_and_assigns_the_generated_key(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $articles = $entities->repository(StoredArticle::class);
        $first = $articles->findOrFail(1);
        $first->title = 'First, edited';
        $first->priority = Priority::Low;
        $first->featured = false;
        $second = $articles->findOrFail(2);
        $entities->remove($second);
        $entities->persist(self::article(4, ArticleStatus::Published, Priority::High));
        $document = new StoredDocument();
        [$document->id, $document->title] = [self::NEW_UUID, 'Draft spec'];
        $entities->persist($document);
        $ticket = new StoredTicket('Printer on fire');
        $entities->persist($ticket);

        $entities->flush();

        self::assertIsInt($ticket->id);
        self::assertSame($ticket, $entities->repository(StoredTicket::class)->find($ticket->id));
        self::assertFalse($entities->contains($second));

        $reloaded = $factory->open();
        self::assertSame(
            [1, 'First, edited', null, ArticleStatus::Published, Priority::Low, false, 4.5, 7],
            self::values($reloaded->repository(StoredArticle::class)->findOrFail(1)),
        );
        self::assertNull($reloaded->repository(StoredArticle::class)->find(2));
        self::assertSame(
            [4, 'Fourth', null, ArticleStatus::Published, Priority::High, true, 1.5, 8],
            self::values($reloaded->repository(StoredArticle::class)->findOrFail(4)),
        );
        self::assertSame('Draft spec', $reloaded->repository(StoredDocument::class)->findOrFail(self::NEW_UUID)->title);
        self::assertSame('Printer on fire', $reloaded->repository(StoredTicket::class)->findOrFail($ticket->id)->subject);
    }

    /**
     * The MySQL family reports changed rows, so this UPDATE affects none
     * there; PostgreSQL reports the matched row.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_an_update_writing_the_values_its_row_already_holds_succeeds(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $third = $entities->repository(StoredArticle::class)->findOrFail(3);
        new Query($this->link())->table('kin_orm_articles')->where('id', '=', 3)->update(['title' => 'Third, elsewhere']);
        $third->title = 'Third, elsewhere';

        $entities->flush();
        $entities->flush();

        self::assertSame('Third, elsewhere', $factory->open()->repository(StoredArticle::class)->findOrFail(3)->title);
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_constraint_failure_rolls_back_and_an_explicit_retry_writes_the_corrected_work(string $dialect, string $driver): void
    {
        $entities = $this->factory($dialect, $driver)->open();
        $third = $entities->repository(StoredArticle::class)->findOrFail(3);
        $third->title = 'Third, edited';
        $fresh = new StoredTicket('Fresh');
        $duplicate = new StoredTicket('Seeded');
        $entities->persist($fresh);
        $entities->persist($duplicate);

        try {
            $entities->flush();
            self::fail('The duplicate subject was written.');
        } catch (QueryException $e) {
            self::assertTrue($e->isUniqueViolation());
        }

        self::assertFalse($entities->isClosed());
        self::assertNull($fresh->id);
        self::assertTrue($entities->contains($duplicate));
        self::assertSame(1, new Query($this->link())->table('kin_orm_tickets')->count(), 'the insert before the failure was rolled back');
        self::assertSame('Third', new Query($this->link())->table('kin_orm_articles')->where('id', '=', 3)->value('title'));

        $duplicate->subject = 'Second';
        $entities->flush();

        self::assertIsInt($fresh->id);
        self::assertIsInt($duplicate->id);
        self::assertSame(3, new Query($this->link())->table('kin_orm_tickets')->count());
        self::assertSame('Third, edited', new Query($this->link())->table('kin_orm_articles')->where('id', '=', 3)->value('title'));
    }

    /**
     * Two managers hold one versioned row, and each flush ends before the
     * next begins, so the stale writer never waits on a lock the other holds.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_stale_writer_conflicts_until_it_reloads_and_reapplies_its_change(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $writer = $factory->open();
        $stale = $factory->open();
        $current = $writer->repository(StoredInvoice::class)->findOrFail(1);
        $outdated = $stale->repository(StoredInvoice::class)->findOrFail(1);

        $current->status = 'paid';
        $writer->flush();
        self::assertSame(2, $current->version);

        $outdated->status = 'void';

        foreach (['The stale UPDATE', 'The repeated stale UPDATE'] as $attempt) {
            try {
                $stale->flush();
                self::fail("{$attempt} overwrote the newer row.");
            } catch (OptimisticLockException $e) {
                self::assertStringStartsWith('The UPDATE of a ' . StoredInvoice::class, $e->getMessage());
            }
        }

        self::assertFalse($stale->isClosed());
        self::assertSame(1, $outdated->version);
        self::assertSame(['paid', 2], self::invoice($factory));

        $stale->clear();
        $reloaded = $stale->repository(StoredInvoice::class)->findOrFail(1);
        self::assertNotSame($outdated, $reloaded);
        $reloaded->status = 'void';
        $stale->flush();

        self::assertSame(3, $reloaded->version);
        self::assertSame(['void', 3], self::invoice($factory));

        $writer->remove($current);

        try {
            $writer->flush();
            self::fail('The stale DELETE removed the newer row.');
        } catch (OptimisticLockException $e) {
            self::assertStringStartsWith('The DELETE of a ' . StoredInvoice::class, $e->getMessage());
        }

        self::assertSame(['void', 3], self::invoice($factory));

        $writer->clear();
        $writer->remove($writer->repository(StoredInvoice::class)->findOrFail(1));
        $writer->flush();

        self::assertNull($factory->open()->repository(StoredInvoice::class)->find(1));
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    private function factory(string $dialect, string $driver): OrmFactory
    {
        $prefix = $dialect === 'mysql' ? 'MYSQL' : 'POSTGRES';
        $host = getenv("{$prefix}_HOST");

        if ($host === false) {
            self::markTestSkipped("{$prefix}_HOST is not set — real-backend tests are environment-gated.");
        }

        $extension = match ([$dialect, $driver]) {
            ['mysql', 'native'] => 'mysqli',
            ['mysql', 'pdo'] => 'pdo_mysql',
            ['pgsql', 'native'] => 'pgsql',
            ['pgsql', 'pdo'] => 'pdo_pgsql',
        };

        if (!extension_loaded($extension)) {
            self::markTestSkipped("ext-{$extension} is not loaded.");
        }

        $link = SqlConnectionFactory::create(new ConnectionDefinition(
            dialect: $dialect,
            host: $host,
            database: getenv("{$prefix}_DATABASE") ?: 'testdb',
            user: getenv("{$prefix}_USER") ?: 'testuser',
            password: getenv("{$prefix}_PASSWORD") ?: 'testpass',
            port: (int) (getenv("{$prefix}_PORT") ?: ($dialect === 'mysql' ? 3306 : 5432)),
            driver: $driver,
        ));
        $this->link = $link;
        self::seed($link, $dialect);

        return OrmFactory::create(
            $link,
            MetadataRegistry::fromClasses([StoredArticle::class, StoredDocument::class, StoredInvoice::class, StoredTicket::class]),
        );
    }

    private function link(): MysqlLink|PostgresLink
    {
        return $this->link ?? throw new \LogicException('No link: factory() opens it.');
    }

    private static function seed(MysqlLink|PostgresLink $link, string $dialect): void
    {
        $link->execute('DROP TABLE IF EXISTS kin_orm_articles');
        $link->execute('DROP TABLE IF EXISTS kin_orm_documents');
        $link->execute('DROP TABLE IF EXISTS kin_orm_tickets');
        $link->execute('DROP TABLE IF EXISTS kin_orm_invoices');
        $link->execute(
            'CREATE TABLE kin_orm_articles (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL, summary VARCHAR(200) NULL, '
            . 'status VARCHAR(20) NOT NULL, priority INT NULL, featured BOOLEAN NOT NULL, rating DOUBLE PRECISION NOT NULL, '
            . 'author_id BIGINT NOT NULL)',
        );
        $link->execute(
            'CREATE TABLE kin_orm_documents (id ' . ($dialect === 'mysql' ? 'CHAR(36)' : 'UUID') . ' PRIMARY KEY, '
            . 'title VARCHAR(100) NOT NULL)',
        );
        $link->execute(
            'CREATE TABLE kin_orm_tickets (id BIGINT ' . ($dialect === 'mysql' ? 'AUTO_INCREMENT' : 'GENERATED BY DEFAULT AS IDENTITY')
            . ' PRIMARY KEY, subject VARCHAR(100) NOT NULL UNIQUE)',
        );
        $link->execute('CREATE TABLE kin_orm_invoices (id INT PRIMARY KEY, status VARCHAR(20) NOT NULL, version BIGINT NOT NULL)');

        new Query($link)->table('kin_orm_articles')->insert([
            ['id' => 1, 'title' => 'First', 'summary' => null, 'status' => 'published', 'priority' => null, 'featured' => true, 'rating' => 4.5, 'author_id' => 7],
            ['id' => 2, 'title' => 'Second', 'summary' => 'Lead', 'status' => 'draft', 'priority' => 2, 'featured' => false, 'rating' => 3.0, 'author_id' => 8],
            ['id' => 3, 'title' => 'Third', 'summary' => null, 'status' => 'draft', 'priority' => 1, 'featured' => false, 'rating' => 1.25, 'author_id' => 8],
        ]);
        new Query($link)->table('kin_orm_documents')->insert(['id' => self::UUID, 'title' => 'Spec']);
        new Query($link)->table('kin_orm_tickets')->insert(['subject' => 'Seeded']);
        new Query($link)->table('kin_orm_invoices')->insert(['id' => 1, 'status' => 'open', 'version' => 1]);
    }

    /**
     * The stored invoice's status and version, read by a new manager.
     *
     * @return array{string, int}
     */
    private static function invoice(OrmFactory $factory): array
    {
        $invoice = $factory->open()->repository(StoredInvoice::class)->findOrFail(1);

        return [$invoice->status, $invoice->version];
    }

    private static function article(int $id, ArticleStatus $status, ?Priority $priority): StoredArticle
    {
        $article = new StoredArticle();
        [$article->id, $article->title, $article->summary, $article->status, $article->priority, $article->featured, $article->rating, $article->authorId]
            = [$id, 'Fourth', null, $status, $priority, true, 1.5, 8];

        return $article;
    }

    /**
     * @return list<mixed>
     */
    private static function values(StoredArticle $article): array
    {
        return [$article->id, $article->title, $article->summary, $article->status, $article->priority, $article->featured, $article->rating, $article->authorId];
    }
}
