<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use Fiber;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityQuery;
use Kinetis\Orm\Exception\ClosedEntityManagerException;
use Kinetis\Orm\Exception\InvalidEntityStateException;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Author;
use Kinetis\Orm\Tests\Fixtures\Charter;
use Kinetis\Orm\Tests\Fixtures\Comment;
use Kinetis\Orm\Tests\Fixtures\Organization;
use Kinetis\Orm\Tests\Fixtures\Post;
use Kinetis\Orm\Tests\Fixtures\Profile;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * #[BelongsTo] against a scripted client and transaction: the selects an
 * eager load sends and on which link, the objects it assigns or leaves
 * alone, the foreign keys a flush writes, and every refusal before SQL.
 * An author's identifier is a string, bound as a parameter; an
 * organization's is an int, which the query builder writes into the SQL.
 */
final class RelationshipTest extends TestCase
{
    private const string POSTS = 'SELECT `id`, `title`, `written_by`, `version` FROM `posts`';

    private const string AUTHORS = 'SELECT `id`, `name`, `organization_id` FROM `authors`';

    private const string ORGANIZATIONS = 'SELECT `id`, `name` FROM `organizations`';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private OrmFactory $factory;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->factory = OrmFactory::create($this->link, MetadataRegistry::fromClasses([
            Author::class,
            Charter::class,
            Comment::class,
            Organization::class,
            Post::class,
            Profile::class,
        ]));
        $this->entities = $this->factory->open();
    }

    public function test_an_eager_load_selects_each_distinct_key_once_and_reuses_a_held_target_untouched(): void
    {
        $this->link->queue([self::author('ada')]);
        $ada = $this->entities->repository(Author::class)->findOrFail('ada');
        $ada->name = 'Ada, edited';
        $this->link->queue(
            [self::post(1, 'ada'), self::post(2, 'grace'), self::post(3, 'ada')],
            [self::author('grace', 1), self::author('ada', 2)],
        );

        $posts = $this->entities->repository(Post::class)->query()->with('author')->get();

        self::assertSame([
            ['sql' => self::POSTS, 'params' => []],
            ['sql' => self::AUTHORS . ' WHERE `id` IN (?, ?)', 'params' => ['ada', 'grace']],
        ], array_slice($this->link->calls, 1));
        self::assertSame($ada, $posts[0]->author);
        self::assertSame($ada, $posts[2]->author);
        self::assertSame('grace', $posts[1]->author->id);
        self::assertSame('Ada, edited', $ada->name);
        self::assertFalse(self::initialized($ada, 'organization'), 'nothing below author was requested');
    }

    public function test_keys_are_selected_a_thousand_per_statement_and_a_level_without_keys_selects_nothing(): void
    {
        $this->link->queue(
            array_map(static fn (int $id): array => self::author("author-{$id}", $id), range(1, 1001)),
            array_map(static fn (int $id): array => self::organization($id), range(1, 1000)),
            [self::organization(1001)],
        );

        $authors = $this->entities->repository(Author::class)->query()->with('organization')->get();

        self::assertSame([
            self::AUTHORS,
            self::ORGANIZATIONS . ' WHERE `id` IN (' . implode(', ', range(1, 1000)) . ')',
            self::ORGANIZATIONS . ' WHERE `id` IN (1001)',
        ], $this->link->statements());
        self::assertSame(1001, $authors[1000]->organization?->id);

        $this->entities->clear();
        $this->link->calls = [];
        $this->link->queue([self::author('ada'), self::author('grace')]);

        $unaffiliated = $this->entities->repository(Author::class)->query()->with('organization')->get();

        self::assertSame([self::AUTHORS], $this->link->statements());
        self::assertSame([true, true], array_map(static fn (Author $author): bool => self::initialized($author, 'organization'), $unaffiliated));
        self::assertSame([null, null], array_map(static fn (Author $author): ?Organization => $author->organization, $unaffiliated));
    }

    public function test_a_nested_path_selects_each_level_by_the_snapshots_of_the_level_above(): void
    {
        $this->link->queue([self::author('ada', 1)]);
        $ada = $this->entities->repository(Author::class)->findOrFail('ada');
        $this->link->calls = [];
        $this->link->queue(
            [self::post(1, 'ada'), self::post(2, 'grace')],
            [self::author('ada', 3), self::author('grace', 2)],
            [self::organization(2), self::organization(1)],
        );

        $posts = $this->entities->repository(Post::class)->query()->with('author')->with('author.organization', 'author')->get();

        self::assertSame([
            self::POSTS,
            self::AUTHORS . ' WHERE `id` IN (?, ?)',
            self::ORGANIZATIONS . ' WHERE `id` IN (1, 2)',
        ], $this->link->statements());
        self::assertSame($ada, $posts[0]->author);
        self::assertSame(1, $ada->organization?->id, 'the held author\'s snapshot names its organization, not the row selected again');
        self::assertSame(2, $posts[1]->author->organization?->id);
    }

    public function test_an_initialized_relationship_is_kept_and_seeds_the_next_level_only_with_a_target_this_manager_manages(): void
    {
        $posts = $this->entities->repository(Post::class);
        $this->link->queue([self::author('grace', 2)], [self::post(1, 'ada')]);
        $grace = $this->entities->repository(Author::class)->findOrFail('grace');
        $post = $posts->findOrFail(1);
        $post->author = $grace;
        $this->link->calls = [];
        $this->link->queue([self::post(1, 'ada')], [self::organization(2)]);

        self::assertSame([$post], $posts->query()->with('author.organization')->get());
        self::assertSame($grace, $post->author, 'not replaced by the target of the snapshot\'s key');
        self::assertSame(2, $grace->organization?->id);
        self::assertSame([self::POSTS, self::ORGANIZATIONS . ' WHERE `id` IN (2)'], $this->link->statements());

        $this->link->queue([self::author('ada', 1)]);
        $post->author = $this->factory->open()->repository(Author::class)->findOrFail('ada');
        $this->link->calls = [];
        $this->link->queue([self::post(1, 'ada')]);

        try {
            $posts->query()->with('author.organization')->get();
            self::fail('A target another manager holds seeded the next level.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame(InvalidEntityStateException::relationTargetNotHeld(Post::class, 'author')->getMessage(), $e->getMessage());
        }

        self::assertSame([self::POSTS], $this->link->statements(), 'refused before the next level\'s select');
    }

    public function test_a_relationship_that_was_never_loaded_keeps_its_foreign_key_out_of_the_update(): void
    {
        $this->link->queue([self::post(1, 'ada')]);
        $post = $this->entities->repository(Post::class)->findOrFail(1);
        $post->title = 'Edited';
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();
        $this->entities->flush();

        self::assertSame([
            ['sql' => 'UPDATE `posts` SET `title` = ?, `version` = ? WHERE `id` = ? AND `version` = ?', 'params' => ['Edited', 2, 1, 1]],
        ], $this->transaction->calls);
        self::assertSame(1, $this->link->begins);
        self::assertFalse(self::initialized($post, 'author'));
    }

    public function test_reassigning_a_relationship_to_a_managed_target_writes_its_key_under_the_version_predicate(): void
    {
        $this->link->queue([self::post(1, 'ada')], [self::author('ada')], [self::author('grace')]);
        $post = $this->entities->repository(Post::class)->query()->with('author')->first();
        self::assertInstanceOf(Post::class, $post);
        $post->author = $this->entities->repository(Author::class)->findOrFail('grace');
        $this->transaction->queue(self::affected(1));

        $this->entities->flush();
        $this->entities->flush();

        self::assertSame([
            ['sql' => 'UPDATE `posts` SET `written_by` = ?, `version` = ? WHERE `id` = ? AND `version` = ?', 'params' => ['grace', 2, 1, 1]],
        ], $this->transaction->calls);
        self::assertSame(1, $this->link->begins, 'the committed snapshot holds the new key');
    }

    public function test_a_session_applies_a_reassignment_only_once_its_commit_returns(): void
    {
        $this->transaction->queue([self::post(1, 'ada')], [self::author('grace')], self::affected(1));
        $post = null;
        $versionAtCommit = null;
        $this->transaction->onCommit = static function () use (&$post, &$versionAtCommit): void {
            self::assertInstanceOf(Post::class, $post);
            $versionAtCommit = $post->version;
        };

        $this->factory->transaction(static function (EntityManager $entities) use (&$post): void {
            $post = $entities->repository(Post::class)->findOrFail(1);
            $post->author = $entities->repository(Author::class)->findOrFail('grace');
            $entities->flush();
        });

        self::assertInstanceOf(Post::class, $post);
        self::assertSame(
            ['sql' => 'UPDATE `posts` SET `written_by` = ?, `version` = ? WHERE `id` = ? AND `version` = ?', 'params' => ['grace', 2, 1, 1]],
            $this->transaction->calls[2],
        );
        self::assertSame(1, $versionAtCommit);
        self::assertSame(2, $post->version);
        self::assertSame([], $this->link->calls);
    }

    /**
     * @return iterable<string, array{Closure(EntityManager, OrmFactory, SpyMysqlLink): (Closure(): mixed), string}>
     */
    public static function unwritableRelationships(): iterable
    {
        $notHeld = InvalidEntityStateException::relationTargetNotHeld(Post::class, 'author')->getMessage();

        yield 'a new entity with an uninitialized relationship' => [
            static fn (EntityManager $entities): Closure => static fn (): mixed => $entities->persist(self::newPost()),
            InvalidEntityStateException::uninitialized(Post::class, 'author')->getMessage(),
        ];
        yield 'a target awaiting insert' => [
            static function (EntityManager $entities): Closure {
                $post = self::newPost();
                $post->author = self::newAuthor('lin');
                $entities->persist($post->author);

                return static fn (): mixed => $entities->persist($post);
            },
            $notHeld,
        ];
        yield 'a target another manager holds' => [
            static function (EntityManager $entities, OrmFactory $factory, SpyMysqlLink $link): Closure {
                $link->queue([self::author('ada')]);
                $post = self::newPost();
                $post->author = $factory->open()->repository(Author::class)->findOrFail('ada');

                return static fn (): mixed => $entities->persist($post);
            },
            $notHeld,
        ];
        yield 'a managed entity reassigned to a target awaiting insert' => [
            static function (EntityManager $entities, OrmFactory $factory, SpyMysqlLink $link): Closure {
                $link->queue([self::post(1, 'ada')]);
                $post = $entities->repository(Post::class)->findOrFail(1);
                $post->author = self::newAuthor('lin');
                $entities->persist($post->author);

                return static fn (): mixed => $entities->flush();
            },
            $notHeld,
        ];
    }

    /**
     * @param Closure(EntityManager, OrmFactory, SpyMysqlLink): (Closure(): mixed) $setup
     */
    #[DataProvider('unwritableRelationships')]
    public function test_a_relationship_this_manager_cannot_write_is_refused_before_any_statement(Closure $setup, string $message): void
    {
        $write = $setup($this->entities, $this->factory, $this->link);
        $statements = count($this->link->calls);

        try {
            $write();
            self::fail('The relationship was accepted.');
        } catch (InvalidEntityStateException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertCount($statements, $this->link->calls);
        self::assertSame(0, $this->link->begins);
    }

    public function test_a_missing_target_row_fails_the_load_even_for_a_held_target_and_rolls_a_session_back(): void
    {
        $missing = MappingException::missingRelationTarget(Post::class, 'author', 'written_by', Author::class)->getMessage();
        $this->link->queue([self::author('grace')], [self::post(1, 'ada'), self::post(2, 'grace')], [self::author('ada')]);
        $this->entities->repository(Author::class)->findOrFail('grace');

        try {
            $this->entities->repository(Post::class)->query()->with('author')->get();
            self::fail('The held author stood in for its missing row.');
        } catch (MappingException $e) {
            self::assertSame($missing, $e->getMessage());
        }

        $this->transaction->queue([self::post(1, 'ada')], []);
        [$caught, $later, $thrown] = [null, null, null];

        try {
            $this->factory->transaction(static function (EntityManager $entities) use (&$caught, &$later): void {
                try {
                    $entities->repository(Post::class)->query()->with('author')->get();
                } catch (MappingException $e) {
                    $caught = $e;
                }

                try {
                    $entities->repository(Post::class);
                } catch (InvalidEntityStateException $e) {
                    $later = $e;
                }
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(MappingException::class, $caught);
        self::assertSame($missing, $caught->getMessage());
        self::assertSame($caught, $thrown);
        self::assertInstanceOf(InvalidEntityStateException::class, $later);
        self::assertSame($caught, $later->getPrevious());
        self::assertSame(['rollback'], $this->transaction->ends);
    }

    public function test_a_locking_read_locks_only_its_root_select_and_every_select_runs_on_the_session_transaction(): void
    {
        $this->transaction->queue([self::post(1, 'ada')], [self::author('ada')]);

        $author = $this->factory->transaction(static fn (EntityManager $entities): ?Author => $entities->repository(Post::class)
            ->query()
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->with('author')
            ->first()
            ?->author);

        self::assertSame('ada', $author?->id);
        self::assertSame([
            ['sql' => self::POSTS . ' WHERE `id` = 1 LIMIT 1 FOR UPDATE', 'params' => []],
            ['sql' => self::AUTHORS . ' WHERE `id` IN (?)', 'params' => ['ada']],
        ], $this->transaction->calls);
        self::assertSame([], $this->link->calls);
        self::assertSame(['commit'], $this->transaction->ends);
    }

    /**
     * @return iterable<string, array{Closure(EntityQuery<Post>): list<Post|null>, list<list<array<string, mixed>>>}>
     */
    public static function entityTerminals(): iterable
    {
        yield 'get()' => [static fn (EntityQuery $query): array => $query->get(), [[self::post(1, 'ada')]]];
        yield 'first()' => [static fn (EntityQuery $query): array => [$query->first()], [[self::post(1, 'ada')]]];
        yield 'paginate()' => [static fn (EntityQuery $query): array => $query->orderBy('id')->paginate(1)->data, [[['aggregate' => 1]], [self::post(1, 'ada')]]];
        yield 'cursorPaginate()' => [static fn (EntityQuery $query): array => $query->cursorPaginate(1, null)->data, [[self::post(1, 'ada')]]];
    }

    /**
     * @param Closure(EntityQuery<Post>): list<Post|null> $terminal
     * @param list<list<array<string, mixed>>> $rootResults
     */
    #[DataProvider('entityTerminals')]
    public function test_every_entity_terminal_loads_the_requested_relationships_after_its_root_statements(Closure $terminal, array $rootResults): void
    {
        $this->link->queue(...$rootResults);
        $this->link->queue([self::author('ada')]);

        $posts = $terminal($this->entities->repository(Post::class)->query()->with('author'));

        self::assertInstanceOf(Post::class, $posts[0]);
        self::assertSame('ada', $posts[0]->author->id);
        self::assertCount(count($rootResults) + 1, $this->link->calls);
        self::assertSame(self::AUTHORS . ' WHERE `id` IN (?)', $this->link->calls[count($rootResults)]['sql']);
    }

    public function test_count_exists_and_find_load_no_relationship(): void
    {
        $this->link->queue([['aggregate' => 3]], [['aggregate' => 1]], [self::post(1, 'ada')]);
        $query = $this->entities->repository(Post::class)->query()->with('author.organization');

        self::assertSame(3, $query->count());
        self::assertTrue($query->exists());
        $post = $this->entities->repository(Post::class)->find(1);

        self::assertSame([
            'SELECT COUNT(*) AS aggregate FROM `posts`',
            'SELECT CASE WHEN EXISTS (' . self::POSTS . ') THEN 1 ELSE 0 END AS aggregate',
            self::POSTS . ' WHERE `id` = 1 LIMIT 1',
        ], $this->link->statements());
        self::assertNotNull($post);
        self::assertFalse(self::initialized($post, 'author'));
    }

    public function test_clear_releases_loaded_targets_and_a_close_during_a_relationship_select_assigns_nothing(): void
    {
        $posts = $this->entities->repository(Post::class);
        $this->link->queue([self::post(1, 'ada')], [self::author('ada')], [self::post(1, 'ada')], [self::author('ada')]);
        $before = $posts->query()->with('author')->first();
        $this->entities->clear();
        $after = $posts->query()->with('author')->first();

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
        self::assertNotSame($before->author, $after->author);

        $post = null;
        $fiber = new Fiber(function () use (&$post): ?Throwable {
            $entities = $this->factory->open();
            $this->link->queue([self::post(1, 'ada')]);
            $post = $entities->repository(Post::class)->findOrFail(1);
            Fiber::suspend($entities);

            try {
                $entities->repository(Post::class)->query()->with('author')->get();
            } catch (Throwable $e) {
                return $e;
            }

            return null;
        });
        $entities = $fiber->start();
        self::assertInstanceOf(EntityManager::class, $entities);
        $this->link->calls = [];
        $this->link->queue([self::post(1, 'ada')], [self::author('ada')]);
        $this->link->onStatement = fn (): mixed => count($this->link->calls) === 2 ? Fiber::suspend() : null;

        $fiber->resume();
        self::assertSame(self::AUTHORS . ' WHERE `id` IN (?)', $this->link->calls[1]['sql'], 'suspended inside the relationship select');
        $entities->close();
        $fiber->resume();

        self::assertInstanceOf(ClosedEntityManagerException::class, $fiber->getReturn());
        self::assertInstanceOf(Post::class, $post);
        self::assertFalse(self::initialized($post, 'author'));
    }

    public function test_a_relationship_property_filters_orders_and_pages_by_its_converted_foreign_key(): void
    {
        $posts = $this->entities->repository(Post::class);
        $authors = $this->entities->repository(Author::class);

        $posts->query()->where('author', '=', 'ada')->whereIn('author', ['ada', 'grace'])->orderBy('author', 'DESC')->get();
        $authors->query()->where('organization', '=', '7')->get();
        $authors->query()->where('organization', '=', null)->get();
        $posts->query()->cursorPaginate(2, 'ada', 'author');

        self::assertSame([
            ['sql' => self::POSTS . ' WHERE `written_by` = ? AND `written_by` IN (?, ?) ORDER BY `written_by` DESC', 'params' => ['ada', 'ada', 'grace']],
            ['sql' => self::AUTHORS . ' WHERE `organization_id` = 7', 'params' => []],
            ['sql' => self::AUTHORS . ' WHERE `organization_id` IS NULL', 'params' => []],
            ['sql' => self::POSTS . ' WHERE `written_by` > ? ORDER BY `written_by` ASC LIMIT 3', 'params' => ['ada']],
        ], $this->link->calls);
    }

    /**
     * @return iterable<string, array{Closure(EntityQuery<Post>): mixed, string}>
     */
    public static function refusedBeforeSql(): iterable
    {
        $entity = Post::class . '::$author takes a string, got ' . Author::class . '.';

        yield 'an entity as a predicate value' => [static fn (EntityQuery $q): mixed => $q->where('author', '=', self::newAuthor('ada')), $entity];
        yield 'an entity in whereIn()' => [static fn (EntityQuery $q): mixed => $q->whereIn('author', ['ada', self::newAuthor('ada')]), $entity];
        yield 'null on a non-nullable relationship' => [static fn (EntityQuery $q): mixed => $q->where('author', '=', null), Post::class . '::$author takes a string, got null.'];
        yield 'an empty path' => [static fn (EntityQuery $q): mixed => $q->with(''), MappingException::invalidRelationPath(Post::class, '')->getMessage()];
        yield 'an empty segment' => [static fn (EntityQuery $q): mixed => $q->with('author', 'author..organization'), MappingException::invalidRelationPath(Post::class, 'author..organization')->getMessage()];
        yield 'an unknown property' => [static fn (EntityQuery $q): mixed => $q->with('writer'), MappingException::unknownProperty(Post::class, 'writer')->getMessage()];
        yield 'a property that is not a relationship' => [static fn (EntityQuery $q): mixed => $q->with('title'), MappingException::notARelation(Post::class, 'title')->getMessage()];
        yield 'an unknown nested property' => [static fn (EntityQuery $q): mixed => $q->with('author.publisher'), MappingException::unknownProperty(Author::class, 'publisher')->getMessage()];
        yield 'a nested property that is not a relationship' => [static fn (EntityQuery $q): mixed => $q->with('author.name'), MappingException::notARelation(Author::class, 'name')->getMessage()];
    }

    /**
     * @param Closure(EntityQuery<Post>): mixed $refused
     */
    #[DataProvider('refusedBeforeSql')]
    public function test_a_relationship_misuse_is_refused_before_sql(Closure $refused, string $message): void
    {
        try {
            $refused($this->entities->repository(Post::class)->query());
            self::fail('The misuse was accepted.');
        } catch (MappingException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    /**
     * @return array<string, int|string>
     */
    private static function post(int $id, string $author): array
    {
        return ['id' => $id, 'title' => "Post {$id}", 'written_by' => $author, 'version' => 1];
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function author(string $id, ?int $organization = null): array
    {
        return ['id' => $id, 'name' => ucfirst($id), 'organization_id' => $organization];
    }

    /**
     * @return array<string, int|string>
     */
    private static function organization(int $id): array
    {
        return ['id' => $id, 'name' => "Organization {$id}"];
    }

    private static function newPost(): Post
    {
        $post = new Post();
        [$post->id, $post->title, $post->version] = [9, 'Draft', 1];

        return $post;
    }

    private static function newAuthor(string $id): Author
    {
        $author = new Author();
        [$author->id, $author->name, $author->organization] = [$id, ucfirst($id), null];

        return $author;
    }

    private static function affected(int $rows): SqlResult
    {
        return new BufferedSqlResult([], $rows, null);
    }

    private static function initialized(object $entity, string $property): bool
    {
        return new ReflectionProperty($entity, $property)->isInitialized($entity);
    }
}
