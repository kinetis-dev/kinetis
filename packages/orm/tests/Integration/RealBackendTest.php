<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Integration;

use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityRepository;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Exception\OptimisticLockException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\StoredArticle;
use Kinetis\Orm\Tests\Fixtures\StoredAuthor;
use Kinetis\Orm\Tests\Fixtures\StoredDocument;
use Kinetis\Orm\Tests\Fixtures\StoredInvoice;
use Kinetis\Orm\Tests\Fixtures\StoredOrganization;
use Kinetis\Orm\Tests\Fixtures\StoredPost;
use Kinetis\Orm\Tests\Fixtures\StoredProfile;
use Kinetis\Orm\Tests\Fixtures\StoredTicket;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Exception\QueryException;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\LockWait;
use Kinetis\QueryBuilder\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entity reads, relationships, flushes and transaction sessions against real
 * servers, through both the native and the PDO driver of each family: every
 * row spelling those drivers produce for ints, floats, booleans, nulls and
 * UUID text passes the conversion domain, and every write, generated key,
 * affected-row count, constraint failure and row lock is the server's own.
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

    /** A second client, for contention the factory's client cannot observe on itself. */
    private null|MysqlLink|PostgresLink $other = null;

    protected function tearDown(): void
    {
        $this->link?->close();
        $this->link = null;
        $this->other?->close();
        $this->other = null;
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
     * A row an entity query locks inside OrmFactory::transaction() stays
     * locked through the session's flush and until its COMMIT. A second
     * client probes with NOWAIT, which never waits, so contention fails the
     * probe at once instead of blocking the test.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_locking_entity_read_holds_its_row_through_the_bound_flush_until_commit(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $other = $this->other = $this->connect($dialect, $driver);
        $held = [];
        $versionBeforeCommit = null;

        $invoice = $factory->transaction(static function (EntityManager $entities) use ($other, &$held, &$versionBeforeCommit): StoredInvoice {
            $invoice = $entities->repository(StoredInvoice::class)->query()->where('id', '=', 1)->lockForUpdate()->first();
            self::assertNotNull($invoice);
            $held[] = self::lockedElsewhere($other, 'kin_orm_invoices', 1);

            $invoice->status = 'paid';
            $entities->builder()->table('kin_orm_tickets')->insert(['subject' => 'Paid']);
            $entities->flush();
            $held[] = self::lockedElsewhere($other, 'kin_orm_invoices', 1);
            $versionBeforeCommit = $invoice->version;

            return $invoice;
        });

        self::assertSame([true, true], $held, 'locked after the read and after the flush');
        self::assertSame(1, $versionBeforeCommit);
        self::assertSame(2, $invoice->version);
        self::assertFalse(self::lockedElsewhere($other, 'kin_orm_invoices', 1), 'COMMIT released the lock');
        self::assertSame(['paid', 2], self::invoice($factory));
        self::assertSame(2, new Query($other)->table('kin_orm_tickets')->count());
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_nested_and_nullable_relationships_load_into_one_identity_map(string $dialect, string $driver): void
    {
        $entities = $this->factory($dialect, $driver)->open();
        $posts = $entities->repository(StoredPost::class);

        $loaded = $posts->query()->with('author.organization')->orderBy('id')->get();

        self::assertSame([1, 2, 3], array_map(static fn (StoredPost $post): int => $post->id, $loaded));
        self::assertSame($loaded[0]->author, $loaded[2]->author);
        self::assertSame($loaded[0]->author, $entities->repository(StoredAuthor::class)->find(7));
        self::assertSame('Acme', $loaded[0]->author->organization?->name);
        self::assertNull($loaded[1]->author->organization);
        self::assertSame([$loaded[1]], $posts->query()->where('author', '=', '8')->with('author')->get());
    }

    /**
     * The posts are inserted out of identifier order, so only the inverse
     * select's ORDER BY puts each author's posts in order.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_inverse_relationships_load_ordered_nested_and_into_one_identity_map(string $dialect, string $driver): void
    {
        $entities = $this->factory($dialect, $driver)->open();
        $held = $entities->repository(StoredPost::class)->findOrFail(2);

        [$ada, $grace, $lin] = $entities->repository(StoredAuthor::class)
            ->query()
            ->with('profile', 'posts.author.organization')
            ->orderBy('id')
            ->get();

        self::assertSame('Mathematician', $ada->profile?->bio);
        self::assertNull($grace->profile);
        self::assertNull($lin->profile);
        self::assertSame([1, 3], array_column($ada->posts, 'id'));
        self::assertSame([$held], $grace->posts);
        self::assertSame([], $lin->posts);
        self::assertSame($ada, $ada->posts[1]->author);
        self::assertSame('Acme', $ada->organization?->name);
        self::assertNull($grace->organization);
    }

    /**
     * StoredOrganization's #[HasOne] runs over a foreign key with no unique
     * constraint, so the database holds what the ORM refuses.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_non_nullable_has_one_refuses_a_missing_and_a_duplicate_row(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        new Query($this->link())->table('kin_orm_organizations')->insert(['id' => 2, 'name' => 'Initech']);

        try {
            $factory->open()->repository(StoredOrganization::class)->query()->with('soleMember')->get();
            self::fail('An organization without a member was loaded.');
        } catch (MappingException $e) {
            self::assertSame(
                MappingException::missingInverseTarget(StoredOrganization::class, 'soleMember', StoredAuthor::class, 'organization_id')->getMessage(),
                $e->getMessage(),
            );
        }

        new Query($this->link())->table('kin_orm_authors')->where('id', '=', 9)->update(['organization_id' => 2]);
        $organizations = $factory->open()->repository(StoredOrganization::class)->query()->with('soleMember')->orderBy('id')->get();
        self::assertSame(['Ada', 'Lin'], array_map(static fn (StoredOrganization $organization): string => $organization->soleMember->name, $organizations));

        new Query($this->link())->table('kin_orm_authors')->where('id', '=', 8)->update(['organization_id' => 1]);

        try {
            $factory->open()->repository(StoredOrganization::class)->query()->with('soleMember')->get();
            self::fail('An organization with two members was loaded.');
        } catch (MappingException $e) {
            self::assertSame(
                MappingException::ambiguousInverseTarget(StoredOrganization::class, 'soleMember', StoredAuthor::class, 'organization_id')->getMessage(),
                $e->getMessage(),
            );
        }
    }

    /**
     * Each of the session's locking reads locks the row it selects. What it
     * loads — an author through #[BelongsTo], posts through #[HasMany] — is
     * selected without a lock, so a second client's NOWAIT lock on those rows
     * succeeds.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_locking_read_locks_its_root_rows_and_not_the_relationships_it_loads(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $other = $this->other = $this->connect($dialect, $driver);

        $held = $factory->transaction(static function (EntityManager $entities) use ($other): array {
            $post = $entities->repository(StoredPost::class)->query()->where('id', '=', 1)->lockForUpdate()->with('author')->first();
            self::assertSame('Ada', $post?->author->name);
            $grace = $entities->repository(StoredAuthor::class)->query()->where('id', '=', 8)->lockForUpdate()->with('posts')->first();
            self::assertSame([2], array_column($grace->posts ?? [], 'id'));

            return [
                self::lockedElsewhere($other, 'kin_orm_posts', 1),
                self::lockedElsewhere($other, 'kin_orm_authors', 7),
                self::lockedElsewhere($other, 'kin_orm_authors', 8),
                self::lockedElsewhere($other, 'kin_orm_posts', 2),
            ];
        });

        self::assertSame([true, false, true, false], $held);
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_committed_reassignment_is_what_a_new_manager_loads(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $post = $entities->repository(StoredPost::class)->findOrFail(1);
        $post->author = $entities->repository(StoredAuthor::class)->findOrFail(8);

        $entities->flush();

        $reloaded = $factory->open()->repository(StoredPost::class)->query()->where('id', '=', 1)->with('author')->first();
        self::assertNotSame($post, $reloaded);
        self::assertSame('Grace', $reloaded?->author->name);

        $authors = $factory->open()->repository(StoredAuthor::class)->query()->where('id', '<', 9)->with('posts')->orderBy('id')->get();
        self::assertSame([[3], [1, 2]], array_map(static fn (StoredAuthor $author): array => array_column($author->posts, 'id'), $authors));
    }

    /**
     * The flush inserts an organization and then deletes an author a post
     * still references. The database's foreign key refuses the DELETE, and
     * the rollback removes the insert.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_removing_a_referenced_target_fails_on_its_foreign_key_and_rolls_the_flush_back(string $dialect, string $driver): void
    {
        $entities = $this->factory($dialect, $driver)->open();
        $organization = new StoredOrganization();
        [$organization->id, $organization->name] = [2, 'Initech'];
        $entities->persist($organization);
        $ada = $entities->repository(StoredAuthor::class)->findOrFail(7);
        $entities->remove($ada);

        try {
            $entities->flush();
            self::fail('A referenced author was deleted.');
        } catch (QueryException) {
        }

        self::assertFalse($entities->isClosed());
        self::assertTrue($entities->contains($ada));
        self::assertSame(0, new Query($this->link())->table('kin_orm_organizations')->where('id', '=', 2)->count());
        self::assertSame(1, new Query($this->link())->table('kin_orm_authors')->where('id', '=', 7)->count());
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    private function factory(string $dialect, string $driver): OrmFactory
    {
        $link = $this->connect($dialect, $driver);
        $this->link = $link;
        self::seed($link, $dialect);

        return OrmFactory::create(
            $link,
            MetadataRegistry::fromClasses([
                StoredArticle::class,
                StoredAuthor::class,
                StoredDocument::class,
                StoredInvoice::class,
                StoredOrganization::class,
                StoredPost::class,
                StoredProfile::class,
                StoredTicket::class,
            ]),
        );
    }

    /**
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    private function connect(string $dialect, string $driver): MysqlLink|PostgresLink
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

        return SqlConnectionFactory::create(new ConnectionDefinition(
            dialect: $dialect,
            host: $host,
            database: getenv("{$prefix}_DATABASE") ?: 'testdb',
            user: getenv("{$prefix}_USER") ?: 'testuser',
            password: getenv("{$prefix}_PASSWORD") ?: 'testpass',
            port: (int) (getenv("{$prefix}_PORT") ?: ($dialect === 'mysql' ? 3306 : 5432)),
            driver: $driver,
        ));
    }

    private function link(): MysqlLink|PostgresLink
    {
        return $this->link ?? throw new \LogicException('No link: factory() opens it.');
    }

    /**
     * Whether $other's NOWAIT lock on the row of $table with identifier $id
     * fails, in a transaction of its own that is rolled back either way.
     */
    private static function lockedElsewhere(MysqlLink|PostgresLink $other, string $table, int $id): bool
    {
        $probe = $other->beginTransaction();

        try {
            new Query($probe)->table($table)->where('id', '=', $id)->lockForUpdate(LockWait::NoWait)->get();
            $locked = false;
        } catch (QueryException) {
            $locked = true;
        }

        $probe->rollback();

        return $locked;
    }

    private static function seed(MysqlLink|PostgresLink $link, string $dialect): void
    {
        $link->execute('DROP TABLE IF EXISTS kin_orm_posts');
        $link->execute('DROP TABLE IF EXISTS kin_orm_profiles');
        $link->execute('DROP TABLE IF EXISTS kin_orm_authors');
        $link->execute('DROP TABLE IF EXISTS kin_orm_organizations');
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
        $link->execute('CREATE TABLE kin_orm_organizations (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL)');
        $link->execute(
            'CREATE TABLE kin_orm_authors (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, organization_id INT NULL, '
            . 'FOREIGN KEY (organization_id) REFERENCES kin_orm_organizations (id))',
        );
        $link->execute(
            'CREATE TABLE kin_orm_posts (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL, written_by INT NOT NULL, '
            . 'FOREIGN KEY (written_by) REFERENCES kin_orm_authors (id))',
        );
        $link->execute(
            'CREATE TABLE kin_orm_profiles (id INT PRIMARY KEY, author_id INT NOT NULL UNIQUE, bio VARCHAR(100) NOT NULL, '
            . 'FOREIGN KEY (author_id) REFERENCES kin_orm_authors (id))',
        );

        new Query($link)->table('kin_orm_articles')->insert([
            ['id' => 1, 'title' => 'First', 'summary' => null, 'status' => 'published', 'priority' => null, 'featured' => true, 'rating' => 4.5, 'author_id' => 7],
            ['id' => 2, 'title' => 'Second', 'summary' => 'Lead', 'status' => 'draft', 'priority' => 2, 'featured' => false, 'rating' => 3.0, 'author_id' => 8],
            ['id' => 3, 'title' => 'Third', 'summary' => null, 'status' => 'draft', 'priority' => 1, 'featured' => false, 'rating' => 1.25, 'author_id' => 8],
        ]);
        new Query($link)->table('kin_orm_documents')->insert(['id' => self::UUID, 'title' => 'Spec']);
        new Query($link)->table('kin_orm_tickets')->insert(['subject' => 'Seeded']);
        new Query($link)->table('kin_orm_invoices')->insert(['id' => 1, 'status' => 'open', 'version' => 1]);
        new Query($link)->table('kin_orm_organizations')->insert(['id' => 1, 'name' => 'Acme']);
        new Query($link)->table('kin_orm_authors')->insert([
            ['id' => 7, 'name' => 'Ada', 'organization_id' => 1],
            ['id' => 8, 'name' => 'Grace', 'organization_id' => null],
            ['id' => 9, 'name' => 'Lin', 'organization_id' => null],
        ]);
        new Query($link)->table('kin_orm_posts')->insert([
            ['id' => 3, 'title' => 'Third', 'written_by' => 7],
            ['id' => 2, 'title' => 'Second', 'written_by' => 8],
            ['id' => 1, 'title' => 'First', 'written_by' => 7],
        ]);
        new Query($link)->table('kin_orm_profiles')->insert(['id' => 1, 'author_id' => 7, 'bio' => 'Mathematician']);
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
