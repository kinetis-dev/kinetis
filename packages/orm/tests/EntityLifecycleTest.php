<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Account;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Document;
use Kinetis\Orm\Tests\Fixtures\Note;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\StoredArticle;
use Kinetis\Orm\Tests\Fixtures\Ticket;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * What persist(), remove(), clear() and close() do to the objects a
 * manager holds, and what they leave for flush(). Every case that should
 * leave nothing to write proves it by a flush that begins no transaction.
 */
final class EntityLifecycleTest extends TestCase
{
    private const string UUID = '3f2c8a4e-9b1d-4c7a-8e5f-0a1b2c3d4e5f';

    private const string OTHER_UUID = '9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a';

    private SpyMysqlLink $link;

    private OrmFactory $factory;

    private EntityManager $manager;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->factory = OrmFactory::create(
            $this->link,
            MetadataRegistry::fromClasses([Article::class, Document::class, Note::class, StoredArticle::class, Ticket::class]),
        );
        $this->manager = $this->factory->open();
    }

    public function test_an_assigned_identifier_enters_the_identity_map_when_persisted(): void
    {
        $document = self::document(self::UUID);

        $this->manager->persist($document);
        $this->manager->persist($document);

        self::assertTrue($this->manager->contains($document));
        self::assertSame($document, $this->manager->repository(Document::class)->find(self::UUID));
        self::assertSame([], $this->link->calls);
    }

    public function test_an_entity_with_a_generated_identifier_is_held_until_its_insert(): void
    {
        $ticket = new Ticket('Printer on fire');

        $this->manager->persist($ticket);
        $this->manager->persist($ticket);

        self::assertTrue($this->manager->contains($ticket));
        self::assertNull($ticket->id);
        self::assertSame([], $this->link->calls);
    }

    /**
     * @return iterable<string, array{Closure(): object, class-string<Throwable>, string}>
     */
    public static function refusedEntities(): iterable
    {
        yield 'an object of an unmapped class' => [
            static fn (): object => new Account(),
            InvalidEntityStateException::class,
            Account::class . ' is not an entity of this OrmFactory',
        ];
        yield 'an uninitialized property' => [
            static function (): object {
                $document = new Document();
                $document->id = self::UUID;

                return $document;
            },
            InvalidEntityStateException::class,
            Document::class . '::$title is not initialized',
        ];
        yield 'a non-finite float' => [
            static function (): object {
                $article = self::storedArticle(4);
                $article->rating = INF;

                return $article;
            },
            MappingException::class,
            StoredArticle::class . '::$rating takes a finite int, float or numeric string, got float',
        ];
        yield 'a null assigned identifier' => [
            static function (): object {
                $note = new Note();
                $note->id = null;
                $note->body = 'text';

                return $note;
            },
            InvalidEntityStateException::class,
            Note::class . '\'s assigned identifier is null',
        ];
        yield 'a generated identifier that is set' => [
            static function (): object {
                $ticket = new Ticket('Printer on fire');
                $ticket->id = 5;

                return $ticket;
            },
            InvalidEntityStateException::class,
            Ticket::class . '\'s generated identifier is not null',
        ];
        yield 'an uninitialized generated identifier' => [
            static function (): object {
                $ticket = new Ticket('Printer on fire');
                unset($ticket->id);

                return $ticket;
            },
            InvalidEntityStateException::class,
            Ticket::class . '::$id is not initialized',
        ];
    }

    /**
     * @param Closure(): object $entity
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusedEntities')]
    public function test_persist_refuses_an_entity_it_cannot_write(Closure $entity, string $exception, string $message): void
    {
        $refused = $entity();

        try {
            $this->manager->persist($refused);
            self::fail('The entity was persisted.');
        } catch (Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertFalse($this->manager->contains($refused));
        $this->manager->flush();
        self::assertSame(0, $this->link->begins);
    }

    public function test_a_second_object_for_a_held_identity_is_refused(): void
    {
        $this->link->queue([['id' => self::UUID, 'title' => 'Spec']]);
        $loaded = $this->manager->repository(Document::class)->findOrFail(self::UUID);
        $pending = self::document(self::OTHER_UUID);
        $this->manager->persist($pending);

        foreach ([self::document(self::UUID), self::document(self::OTHER_UUID)] as $conflicting) {
            try {
                $this->manager->persist($conflicting);
                self::fail('The conflicting object was persisted.');
            } catch (InvalidEntityStateException $e) {
                self::assertSame('This EntityManager already holds another ' . Document::class . ' object with the same identifier.', $e->getMessage());
            }

            self::assertFalse($this->manager->contains($conflicting));
        }

        self::assertSame($loaded, $this->manager->repository(Document::class)->find(self::UUID));
        self::assertSame($pending, $this->manager->repository(Document::class)->find(self::OTHER_UUID));
    }

    public function test_remove_refuses_an_object_the_manager_does_not_hold(): void
    {
        $this->link->queue([['id' => self::UUID, 'title' => 'Spec']]);
        $cleared = $this->manager->repository(Document::class)->findOrFail(self::UUID);
        $this->manager->clear();

        foreach ([self::document(self::UUID), $cleared, new \stdClass()] as $unheld) {
            try {
                $this->manager->remove($unheld);
                self::fail('An object the manager does not hold was removed.');
            } catch (InvalidEntityStateException $e) {
                self::assertStringStartsWith('This EntityManager does not hold this ' . $unheld::class . ' object', $e->getMessage());
            }
        }
    }

    public function test_removing_an_entity_awaiting_insert_detaches_it_without_sql(): void
    {
        $document = self::document(self::UUID);
        $ticket = new Ticket('Printer on fire');
        $this->manager->persist($document);
        $this->manager->persist($ticket);

        $this->manager->remove($document);
        $this->manager->remove($ticket);

        self::assertFalse($this->manager->contains($document));
        self::assertFalse($this->manager->contains($ticket));
        $this->manager->flush();
        self::assertSame(0, $this->link->begins);

        self::assertNull($this->manager->repository(Document::class)->find(self::UUID));
        self::assertCount(1, $this->link->calls, 'the identity was released, so find() queries');
        $this->manager->persist(self::document(self::UUID));
    }

    public function test_persist_cancels_a_scheduled_deletion(): void
    {
        $this->link->queue([['id' => self::UUID, 'title' => 'Spec']]);
        $document = $this->manager->repository(Document::class)->findOrFail(self::UUID);

        $this->manager->remove($document);

        self::assertTrue($this->manager->contains($document));
        self::assertSame($document, $this->manager->repository(Document::class)->find(self::UUID));

        $this->manager->persist($document);
        $this->manager->flush();

        self::assertTrue($this->manager->contains($document));
        self::assertSame(0, $this->link->begins);
    }

    public function test_clear_abandons_every_unflushed_insert_change_and_deletion(): void
    {
        [$changed, $removed, $inserted, $ticket] = $this->pendingWork();

        $this->manager->clear();

        foreach ([$changed, $removed, $inserted, $ticket] as $entity) {
            self::assertFalse($this->manager->contains($entity));
        }

        $this->manager->flush();
        self::assertSame(0, $this->link->begins);
        self::assertCount(1, $this->link->calls);
    }

    public function test_close_abandons_every_unflushed_change_and_never_flushes(): void
    {
        $this->pendingWork();

        $this->manager->close();

        self::assertTrue($this->manager->isClosed());
        $this->expectException(ClosedEntityManagerException::class);

        try {
            $this->manager->flush();
        } finally {
            self::assertSame(0, $this->link->begins);
            self::assertCount(1, $this->link->calls);
        }
    }

    public function test_an_entity_detached_from_another_manager_is_persisted_as_new(): void
    {
        $this->link->queue([['id' => self::UUID, 'title' => 'Spec']]);
        $document = $this->manager->repository(Document::class)->findOrFail(self::UUID);
        $this->manager->close();

        $other = $this->factory->open();
        $other->persist($document);
        $this->link->transaction = $transaction = new SpyMysqlTransaction();
        $transaction->queue(new BufferedSqlResult([], 1, null));
        $other->flush();

        self::assertSame([
            ['sql' => 'INSERT INTO `documents` (`id`, `title`) VALUES (?, ?)', 'params' => [self::UUID, 'Spec']],
        ], $transaction->calls);
    }

    public function test_a_value_changed_and_changed_back_is_not_written(): void
    {
        $this->link->queue([Article::row()]);
        $article = $this->manager->repository(Article::class)->findOrFail(1);

        $article->summary = 'Lead';
        $article->status = ArticleStatus::Draft;
        $article->summary = null;
        $article->status = ArticleStatus::Published;
        $this->manager->flush();

        self::assertSame(0, $this->link->begins);
    }

    public function test_loading_a_held_row_again_keeps_the_snapshot_it_was_loaded_with(): void
    {
        $this->link->queue([Article::row()], [Article::row(['summary' => 'Lead'])]);
        $articles = $this->manager->repository(Article::class);
        $article = $articles->findOrFail(1);
        $article->summary = 'Lead';

        self::assertSame([$article], $articles->query()->get());

        $this->link->transaction = $transaction = new SpyMysqlTransaction();
        $transaction->queue(new BufferedSqlResult([], 1, null));
        $this->manager->flush();

        self::assertSame(
            [['sql' => 'UPDATE `articles` SET `summary` = ? WHERE `id` = ?', 'params' => ['Lead', 1]]],
            $transaction->calls,
            'the second row did not replace the snapshot the change is measured against',
        );
    }

    /**
     * @return iterable<string, array{Closure(EntityManager, SpyMysqlLink): void}>
     */
    public static function changedIdentifiers(): iterable
    {
        yield 'a loaded entity' => [static function (EntityManager $manager, SpyMysqlLink $link): void {
            $link->queue([['id' => self::UUID, 'title' => 'Spec']]);
            $manager->repository(Document::class)->findOrFail(self::UUID)->id = self::OTHER_UUID;
        }];
        yield 'an entity scheduled for deletion' => [static function (EntityManager $manager, SpyMysqlLink $link): void {
            $link->queue([['id' => self::UUID, 'title' => 'Spec']]);
            $document = $manager->repository(Document::class)->findOrFail(self::UUID);
            $manager->remove($document);
            $document->id = self::OTHER_UUID;
        }];
        yield 'an assigned identifier awaiting insert' => [static function (EntityManager $manager): void {
            $document = self::document(self::UUID);
            $manager->persist($document);
            $document->id = self::OTHER_UUID;
        }];
        yield 'a generated identifier awaiting insert' => [static function (EntityManager $manager): void {
            $ticket = new Ticket('Printer on fire');
            $manager->persist($ticket);
            $ticket->id = 7;
        }];
    }

    /**
     * @param Closure(EntityManager, SpyMysqlLink): void $change
     */
    #[DataProvider('changedIdentifiers')]
    public function test_an_identifier_changed_while_held_fails_the_flush_before_a_transaction(Closure $change): void
    {
        $change($this->manager, $this->link);
        // A transaction that would accept whatever statement the flush sends.
        $this->link->transaction = $transaction = new SpyMysqlTransaction();
        $transaction->queue(new BufferedSqlResult([], 1, null));

        try {
            $this->manager->flush();
            self::fail('The flush was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertStringContainsString('changed while this EntityManager held it', $e->getMessage());
        }

        self::assertSame(0, $this->link->begins);
        self::assertSame([], $transaction->calls);
        self::assertSame([], $transaction->ends);
        self::assertFalse($this->manager->isClosed());
    }

    /**
     * A changed loaded article, a loaded document scheduled for deletion,
     * a new document and a new ticket.
     *
     * @return array{Article, Document, Document, Ticket}
     */
    private function pendingWork(): array
    {
        $this->link->queue([Article::row()]);
        $changed = $this->manager->repository(Article::class)->findOrFail(1);
        $changed->summary = 'Lead';
        $removed = self::document(self::OTHER_UUID);
        $this->manager->persist($removed);
        $this->manager->remove($removed);
        $inserted = self::document(self::UUID);
        $this->manager->persist($inserted);
        $ticket = new Ticket('Printer on fire');
        $this->manager->persist($ticket);

        return [$changed, $removed, $inserted, $ticket];
    }

    private static function document(string $id, string $title = 'Spec'): Document
    {
        $document = new Document();
        $document->id = $id;
        $document->title = $title;

        return $document;
    }

    private static function storedArticle(int $id): StoredArticle
    {
        $article = new StoredArticle();
        $article->id = $id;
        $article->title = 'Fourth';
        $article->summary = null;
        $article->status = ArticleStatus::Draft;
        $article->priority = null;
        $article->featured = false;
        $article->rating = 1.5;
        $article->authorId = 8;

        return $article;
    }
}
