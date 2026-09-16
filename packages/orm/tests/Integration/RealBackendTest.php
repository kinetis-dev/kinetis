<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
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
use Kinetis\Orm\Tests\Fixtures\StoredCell;
use Kinetis\Orm\Tests\Fixtures\StoredCrate;
use Kinetis\Orm\Tests\Fixtures\StoredDocument;
use Kinetis\Orm\Tests\Fixtures\StoredEvent;
use Kinetis\Orm\Tests\Fixtures\StoredInvoice;
use Kinetis\Orm\Tests\Fixtures\StoredItem;
use Kinetis\Orm\Tests\Fixtures\StoredOrganization;
use Kinetis\Orm\Tests\Fixtures\StoredParcel;
use Kinetis\Orm\Tests\Fixtures\StoredPost;
use Kinetis\Orm\Tests\Fixtures\StoredProfile;
use Kinetis\Orm\Tests\Fixtures\StoredRibbon;
use Kinetis\Orm\Tests\Fixtures\StoredSeal;
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
 * row spelling those drivers produce for ints, floats, booleans, nulls, UUID
 * text and timestamps passes the conversion domain, and every write,
 * generated key, affected-row count, constraint failure and row lock is the
 * server's own.
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
     * Instants written from another zone and read back through each driver's
     * spelling of a six-digit column: the MySQL family prints six fraction
     * digits, PostgreSQL trims trailing zeros, and a page's next cursor in
     * that spelling selects the following page. A timestamptz column's
     * offset spelling is refused.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_timestamps_round_trip_through_six_digit_columns_and_compare_as_instants(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $utc = new DateTimeZone('UTC');
        $instants = ['2026-03-04 05:06:07.123456', '2026-03-04 05:06:07.500000', '2026-03-04 05:06:07.000000', '0001-01-01 00:00:00.000000', '9999-12-31 23:59:59.999999'];

        foreach ($instants as $i => $instant) {
            $event = new StoredEvent();
            $event->id = $i + 1;
            $event->occurredAt = new DateTimeImmutable($instant, $utc)->setTimezone(new DateTimeZone('+02:00'));
            $event->archivedAt = $i === 0 ? new DateTimeImmutable($instant, $utc) : null;
            $entities->persist($event);
        }

        $entities->flush();

        self::assertSame(
            $dialect === 'mysql'
                ? $instants
                : ['2026-03-04 05:06:07.123456', '2026-03-04 05:06:07.5', '2026-03-04 05:06:07', '0001-01-01 00:00:00', '9999-12-31 23:59:59.999999'],
            new Query($this->link())->table('kin_orm_events')->orderBy('id')->pluck('occurred_at'),
        );

        $events = $factory->open()->repository(StoredEvent::class);
        self::assertSame(
            array_map(static fn (string $instant): string => "{$instant} UTC", $instants),
            array_map(static fn (StoredEvent $event): string => $event->occurredAt->format('Y-m-d H:i:s.u e'), $events->query()->orderBy('id')->get()),
        );
        self::assertSame([2], array_column($events->query()->where('occurredAt', '=', new DateTimeImmutable('2026-03-04 07:06:07.5', new DateTimeZone('+02:00')))->get(), 'id'));
        self::assertSame([1, 3, 4], array_column($events->query()->where('occurredAt', '<', '2026-03-04 05:06:07.5')->orderBy('id')->get(), 'id'));
        self::assertSame([1], array_column($events->findBy(['archivedAt' => '2026-03-04 05:06:07.123456']), 'id'));

        $first = $events->query()->cursorPaginate(2, null, 'occurredAt');
        $second = $events->query()->cursorPaginate(2, $first->nextCursor, 'occurredAt');
        self::assertSame([[4, 3], [1, 2]], [array_column($first->data, 'id'), array_column($second->data, 'id')]);
        self::assertSame(
            $dialect === 'mysql' ? ['2026-03-04 05:06:07.000000', '2026-03-04 05:06:07.500000'] : ['2026-03-04 05:06:07', '2026-03-04 05:06:07.5'],
            [$first->nextCursor, $second->nextCursor],
        );

        if ($dialect === 'pgsql') {
            $this->link()->execute('ALTER TABLE kin_orm_events ALTER COLUMN occurred_at TYPE TIMESTAMP(6) WITH TIME ZONE');

            try {
                $factory->open()->repository(StoredEvent::class)->find(1);
                self::fail('A timestamptz value was loaded.');
            } catch (MappingException $e) {
                self::assertStringStartsWith(StoredEvent::class . '::$occurredAt takes a DateTimeImmutable, or a UTC', $e->getMessage());
            }
        }
    }

    /**
     * One persist() writes a whole aggregate: every row lands after the rows
     * its NOT NULL foreign keys name, and the key each parent insert
     * generated is what the server stored in its children.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_new_aggregate_is_inserted_in_dependency_order_with_its_generated_keys(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $crate = StoredCrate::of('fresh');
        $crate->items = [StoredItem::in($crate, 'first'), StoredItem::in($crate, 'second')];
        $crate->seal = StoredSeal::on($crate, 'foil');
        $entities->persist($crate);

        $entities->flush();

        self::assertNotNull($crate->id);
        $stored = $this->crate($factory->open(), 'fresh', 'items', 'seal');
        self::assertSame($crate->id, $stored->id);
        self::assertSame(['first', 'second'], array_map(static fn (StoredItem $item): string => $item->name, $stored->items));
        self::assertSame('foil', $stored->seal?->stamp);
    }

    /**
     * A row whose nullable foreign key names itself cannot be inserted with
     * its key: the flush sends NULL and fills it in before COMMIT, and the
     * server's own foreign key accepts both statements.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_nullable_loop_is_inserted_as_null_and_fixed_up_inside_one_transaction(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $cell = StoredCell::named('self');
        $cell->twin = $cell;
        $entities->persist($cell);

        $entities->flush();

        self::assertNotNull($cell->id);
        $stored = $factory->open()->repository(StoredCell::class)->query()->where('id', '=', $cell->id)->with('twin')->first();
        self::assertInstanceOf(StoredCell::class, $stored);
        self::assertSame($stored, $stored->twin, 'the fix-up wrote the key the insert could not carry');
    }

    /**
     * The unique foreign key of an owned #[HasOne] admits one row at a time,
     * so a replacement proves the flush deleted before it inserted.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_replaced_owned_has_one_frees_its_unique_slot_before_the_replacement(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $crate = $this->crate($entities, 'seeded-one', 'seal');
        $crate->seal = StoredSeal::on($crate, 'resin');

        $entities->flush();

        self::assertSame(1, new Query($this->link())->table('kin_orm_seals')->where('crate_id', '=', $crate->id)->count());
        self::assertSame('resin', $this->crate($factory->open(), 'seeded-one', 'seal')->seal?->stamp);
    }

    /**
     * Every child row's foreign key refuses its owner's DELETE while it
     * exists, so a committed aggregate removal is the ordering evidence.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_removing_an_aggregate_deletes_its_children_before_its_owner(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $entities->remove($this->crate($entities, 'seeded-one', 'items', 'seal'));

        $entities->flush();

        self::assertSame(0, new Query($this->link())->table('kin_orm_items')->count());
        self::assertSame(1, new Query($this->link())->table('kin_orm_seals')->count(), 'the other crate keeps its seal');
        self::assertNull($factory->open()->repository(StoredCrate::class)->query()->where('code', '=', 'seeded-one')->first());
    }

    /**
     * A statement after a fix-up fails on the server's unique key. The
     * rollback takes the insert and the fix-up with it, and the manager
     * keeps the whole graph pending.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_failure_after_a_fix_up_rolls_the_whole_graph_back(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $cell = StoredCell::named('rolled back');
        $cell->twin = $cell;
        $entities->persist($cell);
        $seal = $entities->repository(StoredSeal::class)->query()->where('stamp', '=', 'lead')->first();
        self::assertInstanceOf(StoredSeal::class, $seal);
        // The crate it moves to is already sealed, and one crate has one seal.
        $seal->crate = $this->crate($entities, 'seeded-one');

        try {
            $entities->flush();
            self::fail('The duplicate seal was accepted.');
        } catch (QueryException) {
        }

        self::assertSame(0, new Query($this->link())->table('kin_orm_cells')->count());
        self::assertNull($cell->id);
        self::assertFalse($entities->isClosed());
        self::assertTrue($entities->contains($cell));
    }

    /**
     * Both ends of a join row can have keys the inserts of the same flush
     * generate, and the server's two foreign keys accept the row only once
     * both of them exist.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_new_owner_links_the_keys_its_endpoint_inserts_generated(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $parcel = StoredParcel::of('fresh');
        $gold = StoredRibbon::named('gold');
        $entities->persist($parcel);
        $entities->persist($gold);
        $silk = $entities->repository(StoredRibbon::class)->query()->where('name', '=', 'silk')->first();
        self::assertInstanceOf(StoredRibbon::class, $silk);
        $parcel->ribbons = [$gold, $silk];

        $entities->flush();

        self::assertNotNull($parcel->id);
        self::assertNotNull($gold->id);
        $linked = [$silk->id, $gold->id];
        sort($linked);
        self::assertSame($linked, self::ribbons($this->parcel($factory->open(), 'fresh')));
    }

    /**
     * A diff of a loaded collection touches the pairs that changed and no
     * other row of the join table, and removes no target entity.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_loaded_diff_writes_only_the_pairs_that_changed(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $parcel = $this->parcel($entities, 'seeded-parcel');
        $linen = $entities->repository(StoredRibbon::class)->query()->where('name', '=', 'linen')->first();
        self::assertInstanceOf(StoredRibbon::class, $linen);
        // silk goes, satin stays and is reordered, linen joins.
        $parcel->ribbons = [$linen, $parcel->ribbons[1]];

        $entities->flush();

        $stored = $this->parcel($factory->open(), 'seeded-parcel');
        self::assertSame([$parcel->ribbons[1]->id, $linen->id], self::ribbons($stored));
        self::assertSame(3, new Query($this->link())->table('kin_orm_ribbons')->count(), 'no target entity was removed');
    }

    /**
     * The join table's foreign key to the owner refuses that owner's DELETE
     * while a link names it, so a committed removal is the ordering
     * evidence. Its foreign key to a target refuses that target's DELETE the
     * same way, which is the policy a shared row keeps.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_removing_an_owner_deletes_its_join_rows_first_and_removing_a_linked_target_is_refused(
        string $dialect,
        string $driver,
    ): void {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $silk = $entities->repository(StoredRibbon::class)->query()->where('name', '=', 'silk')->first();
        self::assertInstanceOf(StoredRibbon::class, $silk);
        $entities->remove($silk);

        try {
            $entities->flush();
            self::fail('A linked ribbon was deleted.');
        } catch (QueryException) {
        }

        $removing = $factory->open();
        $removing->remove($this->parcel($removing, 'seeded-parcel'));

        $removing->flush();

        self::assertSame(0, new Query($this->link())->table('kin_orm_parcel_ribbon')->count());
        self::assertSame(0, new Query($this->link())->table('kin_orm_parcels')->count());
        self::assertSame(3, new Query($this->link())->table('kin_orm_ribbons')->count(), 'the shared targets outlive the owner');
    }

    /**
     * A link another writer added first is the join table's own uniqueness
     * failure. The rollback takes the whole flush with it, and the manager
     * keeps every link it was about to write, with no collection baseline
     * acknowledged.
     *
     * @param 'mysql'|'pgsql' $dialect
     * @param 'native'|'pdo' $driver
     */
    #[DataProvider('drivers')]
    public function test_a_duplicate_link_rolls_back_and_leaves_the_join_work_pending(string $dialect, string $driver): void
    {
        $factory = $this->factory($dialect, $driver);
        $entities = $factory->open();
        $parcel = $this->parcel($entities, 'seeded-parcel');
        $linen = $entities->repository(StoredRibbon::class)->query()->where('name', '=', 'linen')->first();
        self::assertInstanceOf(StoredRibbon::class, $linen);
        $parcel->ribbons = [$parcel->ribbons[1], $linen];
        $pair = ['parcel_id' => $parcel->id, 'ribbon_id' => $linen->id];
        new Query($this->link())->table('kin_orm_parcel_ribbon')->insert($pair);

        try {
            $entities->flush();
            self::fail('The duplicate link was accepted.');
        } catch (QueryException) {
        }

        self::assertFalse($entities->isClosed());
        self::assertSame(3, new Query($this->link())->table('kin_orm_parcel_ribbon')->count(), 'the rollback kept the seeded pairs');

        new Query($this->link())->table('kin_orm_parcel_ribbon')
            ->where('parcel_id', '=', $pair['parcel_id'])
            ->where('ribbon_id', '=', $pair['ribbon_id'])
            ->delete();

        $entities->flush();

        self::assertSame([$parcel->ribbons[0]->id, $linen->id], self::ribbons($this->parcel($factory->open(), 'seeded-parcel')));
    }

    /** The parcel with $code, read through $manager with its ribbons loaded. */
    private function parcel(EntityManager $manager, string $code): StoredParcel
    {
        $parcel = $manager->repository(StoredParcel::class)->query()->where('code', '=', $code)->with('ribbons')->first();

        return $parcel ?? self::fail("No parcel is coded {$code}.");
    }

    /**
     * @return list<int|null> the identifiers of $parcel's ribbons, in ascending order
     */
    private static function ribbons(StoredParcel $parcel): array
    {
        $ids = array_map(static fn (StoredRibbon $ribbon): ?int => $ribbon->id, $parcel->ribbons);
        sort($ids);

        return $ids;
    }

    /** The crate with $code, read through $manager with $relations loaded. */
    private function crate(EntityManager $manager, string $code, string ...$relations): StoredCrate
    {
        $crate = $manager->repository(StoredCrate::class)->query()->where('code', '=', $code)->with(...$relations)->first();

        return $crate ?? self::fail("No crate is coded {$code}.");
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
                StoredCell::class,
                StoredCrate::class,
                StoredDocument::class,
                StoredEvent::class,
                StoredInvoice::class,
                StoredItem::class,
                StoredOrganization::class,
                StoredParcel::class,
                StoredPost::class,
                StoredProfile::class,
                StoredRibbon::class,
                StoredSeal::class,
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
        $link->execute('DROP TABLE IF EXISTS kin_orm_parcel_ribbon');
        $link->execute('DROP TABLE IF EXISTS kin_orm_parcels');
        $link->execute('DROP TABLE IF EXISTS kin_orm_ribbons');
        $link->execute('DROP TABLE IF EXISTS kin_orm_seals');
        $link->execute('DROP TABLE IF EXISTS kin_orm_items');
        $link->execute('DROP TABLE IF EXISTS kin_orm_crates');
        $link->execute('DROP TABLE IF EXISTS kin_orm_cells');
        $link->execute('DROP TABLE IF EXISTS kin_orm_posts');
        $link->execute('DROP TABLE IF EXISTS kin_orm_profiles');
        $link->execute('DROP TABLE IF EXISTS kin_orm_authors');
        $link->execute('DROP TABLE IF EXISTS kin_orm_organizations');
        $link->execute('DROP TABLE IF EXISTS kin_orm_articles');
        $link->execute('DROP TABLE IF EXISTS kin_orm_documents');
        $link->execute('DROP TABLE IF EXISTS kin_orm_tickets');
        $link->execute('DROP TABLE IF EXISTS kin_orm_invoices');
        $link->execute('DROP TABLE IF EXISTS kin_orm_events');
        $timestamp = $dialect === 'mysql' ? 'DATETIME(6)' : 'TIMESTAMP(6) WITHOUT TIME ZONE';
        $link->execute("CREATE TABLE kin_orm_events (id INT PRIMARY KEY, occurred_at {$timestamp} NOT NULL, archived_at {$timestamp} NULL)");
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

        $key = $dialect === 'mysql' ? 'AUTO_INCREMENT' : 'GENERATED BY DEFAULT AS IDENTITY';
        $link->execute("CREATE TABLE kin_orm_crates (id BIGINT {$key} PRIMARY KEY, code VARCHAR(50) NOT NULL)");
        $link->execute(
            "CREATE TABLE kin_orm_items (id BIGINT {$key} PRIMARY KEY, crate_id BIGINT NOT NULL, name VARCHAR(50) NOT NULL, "
            . 'FOREIGN KEY (crate_id) REFERENCES kin_orm_crates (id))',
        );
        $link->execute(
            "CREATE TABLE kin_orm_seals (id BIGINT {$key} PRIMARY KEY, crate_id BIGINT NOT NULL UNIQUE, stamp VARCHAR(50) NOT NULL, "
            . 'FOREIGN KEY (crate_id) REFERENCES kin_orm_crates (id))',
        );
        $link->execute(
            "CREATE TABLE kin_orm_cells (id BIGINT {$key} PRIMARY KEY, twin_id BIGINT NULL, name VARCHAR(50) NOT NULL, "
            . 'FOREIGN KEY (twin_id) REFERENCES kin_orm_cells (id))',
        );
        $link->execute("CREATE TABLE kin_orm_parcels (id BIGINT {$key} PRIMARY KEY, code VARCHAR(50) NOT NULL)");
        $link->execute("CREATE TABLE kin_orm_ribbons (id BIGINT {$key} PRIMARY KEY, name VARCHAR(50) NOT NULL)");
        $link->execute(
            'CREATE TABLE kin_orm_parcel_ribbon (parcel_id BIGINT NOT NULL, ribbon_id BIGINT NOT NULL, '
            . 'PRIMARY KEY (parcel_id, ribbon_id), '
            . 'FOREIGN KEY (parcel_id) REFERENCES kin_orm_parcels (id), '
            . 'FOREIGN KEY (ribbon_id) REFERENCES kin_orm_ribbons (id))',
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
        $first = new Query($link)->table('kin_orm_crates')->insertGetId(['code' => 'seeded-one'], 'id');
        $second = new Query($link)->table('kin_orm_crates')->insertGetId(['code' => 'seeded-two'], 'id');
        new Query($link)->table('kin_orm_items')->insert([
            ['crate_id' => $first, 'name' => 'bolt'],
            ['crate_id' => $first, 'name' => 'nut'],
        ]);
        new Query($link)->table('kin_orm_seals')->insert([
            ['crate_id' => $first, 'stamp' => 'wax'],
            ['crate_id' => $second, 'stamp' => 'lead'],
        ]);

        $silk = new Query($link)->table('kin_orm_ribbons')->insertGetId(['name' => 'silk'], 'id');
        $satin = new Query($link)->table('kin_orm_ribbons')->insertGetId(['name' => 'satin'], 'id');
        new Query($link)->table('kin_orm_ribbons')->insert(['name' => 'linen']);
        $parcel = new Query($link)->table('kin_orm_parcels')->insertGetId(['code' => 'seeded-parcel'], 'id');
        new Query($link)->table('kin_orm_parcel_ribbon')->insert([
            ['parcel_id' => $parcel, 'ribbon_id' => $silk],
            ['parcel_id' => $parcel, 'ribbon_id' => $satin],
        ]);
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
