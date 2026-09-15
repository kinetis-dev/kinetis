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
use Kinetis\Orm\Tests\Fixtures\Topic;
use Kinetis\Persistence\Contract\SqlResult;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * #[HasOne] and #[HasMany] against a scripted client and transaction: the
 * selects an inverse load sends and on which link, the entity each returned
 * row joins, the objects it assigns or leaves alone, every refusal, and a
 * flush that never reads an inverse relationship. An author's identifier is
 * a string, bound as a parameter; a post's, an organization's and a topic's
 * are ints, which the query builder writes into the SQL.
 */
final class InverseRelationshipTest extends TestCase
{
    private const string AUTHORS = 'SELECT `id`, `name`, `organization_id` FROM `authors`';

    private const string POSTS = 'SELECT `id`, `title`, `written_by`, `version` FROM `posts`';

    private const string COMMENTS = 'SELECT `id`, `post_id`, `author_id`, `body` FROM `comments`';

    private const string PROFILES = 'SELECT `id`, `author_id`, `bio` FROM `profiles`';

    private const string ORGANIZATIONS = 'SELECT `id`, `name` FROM `organizations`';

    private const string CHARTERS = 'SELECT `id`, `organization_id`, `text` FROM `charters`';

    private const string TOPICS = 'SELECT `id`, `parent_id`, `supersedes_id` FROM `topics`';

    private const string BY_ID = ' ORDER BY `id` ASC';

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
            Topic::class,
        ]));
        $this->entities = $this->factory->open();
    }

    public function test_each_inverse_relationship_selects_the_distinct_identifiers_once_and_groups_rows_by_their_foreign_key(): void
    {
        $this->link->queue([self::post(2, 'grace')]);
        $held = $this->entities->repository(Post::class)->findOrFail(2);
        $held->title = 'Edited in memory';
        $this->link->calls = [];
        $this->link->queue(
            [self::author('ada'), self::author('grace'), self::author('lin'), self::author('ada')],
            [self::profile(4, 'grace')],
            [self::post(1, 'ada'), self::post(2, 'grace'), self::post(3, 'ada')],
        );

        $authors = $this->entities->repository(Author::class)->query()->with('profile', 'posts')->get();

        self::assertSame([
            ['sql' => self::AUTHORS, 'params' => []],
            ['sql' => self::PROFILES . ' WHERE `author_id` IN (?, ?, ?)' . self::BY_ID, 'params' => ['ada', 'grace', 'lin']],
            ['sql' => self::POSTS . ' WHERE `written_by` IN (?, ?, ?)' . self::BY_ID, 'params' => ['ada', 'grace', 'lin']],
        ], $this->link->calls);
        [$ada, $grace, $lin] = $authors;
        self::assertSame($ada, $authors[3]);
        self::assertNull($ada->profile);
        self::assertSame(4, $grace->profile?->id);
        self::assertNull($lin->profile);
        self::assertSame([1, 3], array_column($ada->posts, 'id'));
        self::assertSame([$held], $grace->posts, 'the held post, not a second object');
        self::assertSame('Edited in memory', $held->title);
        self::assertSame([], $lin->posts);
        self::assertFalse(self::initialized($ada->posts[0], 'author'), 'nothing below posts was requested');
    }

    public function test_identifiers_are_selected_a_thousand_per_statement_and_a_level_without_an_unloaded_entity_selects_nothing(): void
    {
        $this->link->queue(
            array_map(static fn (int $id): array => self::post($id, 'ada'), range(1, 1001)),
            [self::comment(1, 1000), self::comment(2, 1)],
            [self::comment(3, 1001)],
        );

        $posts = $this->entities->repository(Post::class)->query()->with('comments')->get();

        self::assertSame([
            self::POSTS,
            self::COMMENTS . ' WHERE `post_id` IN (' . implode(', ', range(1, 1000)) . ')' . self::BY_ID,
            self::COMMENTS . ' WHERE `post_id` IN (1001)' . self::BY_ID,
        ], $this->link->statements());
        self::assertSame([2], array_column($posts[0]->comments, 'id'));
        self::assertSame([], $posts[1]->comments);
        self::assertSame([1], array_column($posts[999]->comments, 'id'));
        self::assertSame([3], array_column($posts[1000]->comments, 'id'));

        $this->entities->clear();
        $this->link->calls = [];
        $this->link->queue([], [self::post(1, 'ada'), self::post(2, 'ada')], [self::post(1, 'ada'), self::post(2, 'ada')]);
        $repository = $this->entities->repository(Post::class);

        self::assertSame([], $repository->query()->with('comments')->get());
        $held = $repository->query()->get();
        $held[0]->comments = [];
        $held[1]->comments = [];
        self::assertSame($held, $repository->query()->with('comments')->get());
        self::assertSame([self::POSTS, self::POSTS, self::POSTS], $this->link->statements());
    }

    public function test_a_path_nests_a_belongs_to_below_an_inverse_relationship_and_an_inverse_relationship_below_a_belongs_to(): void
    {
        $this->link->queue(
            [self::post(1, 'ada'), self::post(2, 'grace')],
            [self::comment(5, 1, 'grace'), self::comment(6, 2, 'grace'), self::comment(7, 1, 'ada')],
            [self::author('grace'), self::author('ada')],
        );

        $posts = $this->entities->repository(Post::class)->query()->with('comments.author')->get();

        self::assertSame([
            ['sql' => self::POSTS, 'params' => []],
            ['sql' => self::COMMENTS . ' WHERE `post_id` IN (1, 2)' . self::BY_ID, 'params' => []],
            ['sql' => self::AUTHORS . ' WHERE `id` IN (?, ?)', 'params' => ['grace', 'ada']],
        ], $this->link->calls);
        self::assertSame([5, 7], array_column($posts[0]->comments, 'id'));
        self::assertSame([6], array_column($posts[1]->comments, 'id'));
        self::assertSame($posts[0]->comments[0]->author, $posts[1]->comments[0]->author);
        self::assertSame('ada', $posts[0]->comments[1]->author->id);

        $this->entities->clear();
        $this->link->calls = [];
        $this->link->queue(
            [self::post(1, 'ada'), self::post(3, 'grace')],
            [self::author('ada'), self::author('grace')],
            [self::post(1, 'ada'), self::post(2, 'ada'), self::post(3, 'grace')],
        );

        [$first, $third] = $this->entities->repository(Post::class)->query()->with('author.posts')->get();

        self::assertSame([
            self::POSTS,
            self::AUTHORS . ' WHERE `id` IN (?, ?)',
            self::POSTS . ' WHERE `written_by` IN (?, ?)' . self::BY_ID,
        ], $this->link->statements());
        self::assertSame([1, 2], array_column($first->author->posts, 'id'));
        self::assertSame($first, $first->author->posts[0], 'a root post is the object its author\'s posts hold');
        self::assertSame([$third], $third->author->posts);
    }

    public function test_inverse_relationships_to_the_entity_s_own_class_load_level_by_level(): void
    {
        $this->link->queue(
            [self::topic(1)],
            [self::topic(2, 1), self::topic(3, 1)],
            [self::topic(4, 2)],
            [self::topic(5, null, 1)],
        );

        $topic = $this->entities->repository(Topic::class)->query()->with('children.children', 'supersededBy')->first();

        self::assertSame([
            self::TOPICS . ' LIMIT 1',
            self::TOPICS . ' WHERE `parent_id` IN (1)' . self::BY_ID,
            self::TOPICS . ' WHERE `parent_id` IN (2, 3)' . self::BY_ID,
            self::TOPICS . ' WHERE `supersedes_id` IN (1)' . self::BY_ID,
        ], $this->link->statements());
        self::assertNotNull($topic);
        self::assertSame([2, 3], array_column($topic->children, 'id'));
        self::assertSame([4], array_column($topic->children[0]->children, 'id'));
        self::assertSame([], $topic->children[1]->children);
        self::assertSame(5, $topic->supersededBy?->id);
        self::assertFalse(self::initialized($topic->children[0], 'supersededBy'), 'nothing below children but children was requested');
    }

    public function test_an_initialized_inverse_relationship_is_kept_and_everything_it_holds_is_checked_before_further_sql(): void
    {
        $this->link->queue([self::post(1, 'ada')], [self::comment(5, 1, 'grace')], [self::author('ada')]);
        $posts = $this->entities->repository(Post::class);
        $post = $posts->findOrFail(1);
        $comment = $this->entities->repository(Comment::class)->findOrFail(5);
        $ada = $this->entities->repository(Author::class)->findOrFail('ada');
        $post->comments = ['pinned' => $comment];
        $this->link->calls = [];
        $this->link->queue([self::post(1, 'ada')], [self::author('grace')]);

        self::assertSame([$post], $posts->query()->with('comments.author')->get());
        self::assertSame(['pinned' => $comment], $post->comments, 'kept as the application set it');
        self::assertSame('grace', $comment->author->id);
        self::assertSame([self::POSTS, self::AUTHORS . ' WHERE `id` IN (?)'], $this->link->statements());

        $this->link->queue([self::comment(6, 1)], [self::profile(4, 'ada')]);
        $other = $this->factory->open();
        $foreignComment = $other->repository(Comment::class)->findOrFail(6);
        $foreignProfile = $other->repository(Profile::class)->findOrFail(4);
        $cases = [
            'a comment no manager holds' => [$post, 'comments', [$comment, new Comment()]],
            'a comment another manager holds' => [$post, 'comments', [$foreignComment]],
            'a managed entity of another class' => [$post, 'comments', [$ada]],
            'a value that is not an entity' => [$post, 'comments', [5]],
            'a profile another manager holds' => [$ada, 'profile', $foreignProfile],
        ];

        foreach ($cases as $case => [$entity, $property, $value]) {
            $entity->{$property} = $value;
            $root = $entity instanceof Post ? self::post(1, 'ada') : self::author('ada');

            foreach ([$property, $property . '.author'] as $path) {
                $this->link->calls = [];
                $this->link->queue([$root]);

                try {
                    $this->entities->repository($entity::class)->query()->with($path)->get();
                    self::fail("{$case} was accepted under {$path}.");
                } catch (InvalidEntityStateException $e) {
                    self::assertSame(InvalidEntityStateException::relationTargetNotHeld($entity::class, $property)->getMessage(), $e->getMessage());
                }

                self::assertSame([$entity instanceof Post ? self::POSTS : self::AUTHORS], $this->link->statements(), "{$case} under {$path}: no further select");
                self::assertSame($value, $entity->{$property});
            }
        }

        $ada->profile = null;
        $this->link->calls = [];
        $this->link->queue([self::author('ada')]);

        self::assertSame([$ada], $this->entities->repository(Author::class)->query()->with('profile.author')->get());
        self::assertNull($ada->profile);
        self::assertSame([self::AUTHORS], $this->link->statements());
    }

    public function test_a_row_joins_the_entity_its_foreign_key_names_and_the_held_target_keeps_its_property_and_snapshot(): void
    {
        $this->link->queue(
            [self::post(1, 'ada'), self::post(2, 'ada'), self::post(3, 'ada')],
            [self::comment(5, 1), self::comment(6, 1)],
        );
        [$first, $second, $third] = $this->entities->repository(Post::class)->query()->get();
        [$moved, $removed] = $this->entities->repository(Comment::class)->query()->get();
        $moved->post = $third;
        $this->entities->remove($removed);
        $this->link->calls = [];
        $this->link->queue([self::post(1, 'ada'), self::post(2, 'ada')], [self::comment(5, 2), self::comment(6, 1)]);

        $posts = $this->entities->repository(Post::class)->query()->where('id', '<', 3)->with('comments')->get();

        self::assertSame([$first, $second], $posts);
        self::assertSame(self::COMMENTS . ' WHERE `post_id` IN (1, 2)' . self::BY_ID, $this->link->statements()[1]);
        self::assertSame([$removed], $first->comments, 'a comment scheduled for removal loads until its DELETE commits');
        self::assertSame([$moved], $second->comments, 'its row names post 2');
        self::assertSame($third, $moved->post, 'the unflushed reassignment is untouched');
        self::assertFalse(self::initialized($third, 'comments'));

        $moved->post = $first;
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'DELETE FROM `comments` WHERE `id` = 6', 'params' => []]],
            $this->transaction->calls,
            'the snapshot still names post 1, so the comment reassigned back to it writes nothing',
        );
    }

    public function test_a_has_one_without_a_row_is_null_only_when_nullable_and_more_than_one_row_fails_the_load_and_the_session(): void
    {
        $this->link->queue([self::organization(1), self::organization(2)], [self::charter(3, 1)]);

        try {
            $this->entities->repository(Organization::class)->query()->with('charter')->get();
            self::fail('A non-nullable #[HasOne] without a row was accepted.');
        } catch (MappingException $e) {
            self::assertSame(
                MappingException::missingInverseTarget(Organization::class, 'charter', Charter::class, 'organization_id')->getMessage(),
                $e->getMessage(),
            );
        }

        self::assertSame(self::CHARTERS . ' WHERE `organization_id` IN (1, 2)' . self::BY_ID, $this->link->statements()[1]);
        $unassigned = $this->entities->repository(Organization::class)->find(2);
        self::assertNotNull($unassigned);
        self::assertFalse(self::initialized($unassigned, 'charter'));

        $ambiguous = MappingException::ambiguousInverseTarget(Author::class, 'profile', Profile::class, 'author_id')->getMessage();

        foreach (['two rows' => [self::profile(4, 'ada'), self::profile(5, 'ada')], 'one row returned twice' => [self::profile(4, 'ada'), self::profile(4, 'ada')]] as $case => $rows) {
            $this->entities->clear();
            $this->link->queue([self::author('ada')], $rows);

            try {
                $this->entities->repository(Author::class)->query()->with('profile')->get();
                self::fail("{$case} for one #[HasOne] were accepted.");
            } catch (MappingException $e) {
                self::assertSame($ambiguous, $e->getMessage());
            }

            $ada = $this->entities->repository(Author::class)->find('ada');
            self::assertNotNull($ada);
            self::assertFalse(self::initialized($ada, 'profile'), $case);
        }

        $this->transaction->queue([self::organization(1)], []);
        [$caught, $later, $thrown] = [null, null, null];

        try {
            $this->factory->transaction(static function (EntityManager $entities) use (&$caught, &$later): void {
                try {
                    $entities->repository(Organization::class)->query()->with('charter')->get();
                } catch (MappingException $e) {
                    $caught = $e;
                }

                try {
                    $entities->repository(Organization::class);
                } catch (InvalidEntityStateException $e) {
                    $later = $e;
                }
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(MappingException::class, $caught);
        self::assertSame($caught, $thrown);
        self::assertInstanceOf(InvalidEntityStateException::class, $later);
        self::assertSame($caught, $later->getPrevious());
        self::assertSame(['rollback'], $this->transaction->ends);
    }

    public function test_a_locking_read_locks_only_its_root_select_and_its_inverse_select_runs_on_the_session_transaction(): void
    {
        $this->transaction->queue([self::post(1, 'ada')], [self::comment(5, 1)]);

        $comments = $this->factory->transaction(static fn (EntityManager $entities): ?array => $entities->repository(Post::class)
            ->query()
            ->where('id', '=', 1)
            ->lockForUpdate()
            ->with('comments')
            ->first()
            ?->comments);

        self::assertSame([5], array_column($comments ?? [], 'id'));
        self::assertSame([
            ['sql' => self::POSTS . ' WHERE `id` = 1 LIMIT 1 FOR UPDATE', 'params' => []],
            ['sql' => self::COMMENTS . ' WHERE `post_id` IN (1)' . self::BY_ID, 'params' => []],
        ], $this->transaction->calls);
        self::assertSame([], $this->link->calls);
        self::assertSame(['commit'], $this->transaction->ends);
    }

    public function test_a_close_during_an_inverse_select_keeps_earlier_levels_and_assigns_nothing_more(): void
    {
        $author = null;
        $fiber = new Fiber(function () use (&$author): ?Throwable {
            $entities = $this->factory->open();
            $this->link->queue([self::author('ada')]);
            $author = $entities->repository(Author::class)->findOrFail('ada');
            Fiber::suspend($entities);

            try {
                $entities->repository(Author::class)->query()->with('profile', 'posts')->get();
            } catch (Throwable $e) {
                return $e;
            }

            return null;
        });
        $entities = $fiber->start();
        self::assertInstanceOf(EntityManager::class, $entities);
        $this->link->calls = [];
        $this->link->queue([self::author('ada')], [self::profile(4, 'ada')], [self::post(1, 'ada')]);
        $this->link->onStatement = fn (): mixed => count($this->link->calls) === 3 ? Fiber::suspend() : null;

        $fiber->resume();
        self::assertSame(self::POSTS . ' WHERE `written_by` IN (?)' . self::BY_ID, $this->link->calls[2]['sql'], 'suspended inside the posts select');
        $entities->close();
        $fiber->resume();

        self::assertInstanceOf(ClosedEntityManagerException::class, $fiber->getReturn());
        self::assertInstanceOf(Author::class, $author);
        self::assertSame(4, $author->profile?->id, 'loaded before the close');
        self::assertFalse(self::initialized($author, 'posts'));
    }

    /**
     * @return iterable<string, array{Closure(EntityManager): mixed, string}>
     */
    public static function refusedBeforeSql(): iterable
    {
        $comments = MappingException::notAColumn(Post::class, 'comments')->getMessage();
        $posts = static fn (EntityManager $entities): EntityQuery => $entities->repository(Post::class)->query();

        yield 'where()' => [static fn (EntityManager $e): mixed => $posts($e)->where('comments', '=', 5), $comments];
        yield 'whereIn()' => [static fn (EntityManager $e): mixed => $posts($e)->whereIn('comments', [5]), $comments];
        yield 'orderBy()' => [static fn (EntityManager $e): mixed => $posts($e)->orderBy('comments'), $comments];
        yield 'a cursor property' => [static fn (EntityManager $e): mixed => $posts($e)->cursorPaginate(10, null, 'comments'), $comments];
        yield 'findBy()' => [static fn (EntityManager $e): mixed => $e->repository(Post::class)->findBy(['title' => 'Post 1', 'comments' => []]), $comments];
        yield 'a #[HasOne] in where()' => [
            static fn (EntityManager $e): mixed => $e->repository(Author::class)->query()->where('profile', '=', null),
            MappingException::notAColumn(Author::class, 'profile')->getMessage(),
        ];
        yield 'a property that is not a relationship below an inverse one' => [
            static fn (EntityManager $e): mixed => $posts($e)->with('comments.body'),
            MappingException::notARelation(Comment::class, 'body')->getMessage(),
        ];
        yield 'an unknown property below an inverse one' => [
            static fn (EntityManager $e): mixed => $e->repository(Author::class)->query()->with('profile.avatar'),
            MappingException::unknownProperty(Profile::class, 'avatar')->getMessage(),
        ];
    }

    /**
     * @param Closure(EntityManager): mixed $refused
     */
    #[DataProvider('refusedBeforeSql')]
    public function test_an_inverse_relationship_misuse_is_refused_before_sql(Closure $refused, string $message): void
    {
        try {
            $refused($this->entities);
            self::fail('The misuse was accepted.');
        } catch (MappingException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
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
    public function test_every_entity_terminal_loads_inverse_relationships_after_its_root_statements(Closure $terminal, array $rootResults): void
    {
        $this->link->queue(...$rootResults);
        $this->link->queue([self::comment(5, 1)]);

        $posts = $terminal($this->entities->repository(Post::class)->query()->with('comments'));

        self::assertInstanceOf(Post::class, $posts[0]);
        self::assertSame([5], array_column($posts[0]->comments, 'id'));
        self::assertCount(count($rootResults) + 1, $this->link->calls);
        self::assertSame(self::COMMENTS . ' WHERE `post_id` IN (1)' . self::BY_ID, $this->link->calls[count($rootResults)]['sql']);
    }

    public function test_count_exists_and_find_load_no_inverse_relationship(): void
    {
        $this->link->queue([['aggregate' => 3]], [['aggregate' => 1]], [self::post(1, 'ada')]);
        $query = $this->entities->repository(Post::class)->query()->with('comments.author.profile');

        self::assertSame(3, $query->count());
        self::assertTrue($query->exists());
        $post = $this->entities->repository(Post::class)->find(1);

        self::assertSame([
            'SELECT COUNT(*) AS aggregate FROM `posts`',
            'SELECT CASE WHEN EXISTS (' . self::POSTS . ') THEN 1 ELSE 0 END AS aggregate',
            self::POSTS . ' WHERE `id` = 1 LIMIT 1',
        ], $this->link->statements());
        self::assertNotNull($post);
        self::assertFalse(self::initialized($post, 'comments'));
    }

    public function test_a_flush_never_reads_an_inverse_relationship_and_writes_only_the_owning_foreign_key(): void
    {
        $this->entities->persist(self::newAuthor('lin'));
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'INSERT INTO `authors` (`id`, `name`, `organization_id`) VALUES (?, ?, ?)', 'params' => ['lin', 'Lin', null]]],
            $this->transaction->calls,
            'a new author leaves both inverse relationships uninitialized',
        );

        $this->link->queue([self::post(1, 'ada')], [self::post(2, 'grace')], [self::author('grace')], [self::comment(5, 1)]);
        $posts = $this->entities->repository(Post::class);
        [$first, $second] = [$posts->findOrFail(1), $posts->findOrFail(2)];
        $grace = $this->entities->repository(Author::class)->findOrFail('grace');
        $comment = $this->entities->repository(Comment::class)->findOrFail(5);
        $orphan = new Comment();
        [$orphan->id, $orphan->post, $orphan->author, $orphan->body] = [9, $first, $grace, 'Never persisted'];
        $first->comments = [$orphan];
        $second->comments = [$comment];
        $grace->posts = [];
        $grace->profile = null;
        $this->transaction->calls = [];

        $this->entities->flush();

        self::assertSame([], $this->transaction->calls, 'a change to inverse relationships alone writes nothing');
        self::assertSame(1, $this->link->begins);
        self::assertFalse($this->entities->contains($orphan), 'nothing cascades from an inverse relationship');

        $comment->post = $second;
        $this->transaction->queue(self::affected(1));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'UPDATE `comments` SET `post_id` = 2 WHERE `id` = 5', 'params' => []]],
            $this->transaction->calls,
        );
        self::assertSame([$orphan], $first->comments, 'no inverse relationship is fixed up');
        self::assertSame([$comment], $second->comments);
        self::assertSame(2, $this->link->begins);
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
    private static function comment(int $id, int $post, string $author = 'ada'): array
    {
        return ['id' => $id, 'post_id' => $post, 'author_id' => $author, 'body' => "Comment {$id}"];
    }

    /**
     * @return array<string, int|string>
     */
    private static function profile(int $id, string $author): array
    {
        return ['id' => $id, 'author_id' => $author, 'bio' => "Bio {$id}"];
    }

    /**
     * @return array<string, int|string>
     */
    private static function organization(int $id): array
    {
        return ['id' => $id, 'name' => "Organization {$id}"];
    }

    /**
     * @return array<string, int|string>
     */
    private static function charter(int $id, int $organization): array
    {
        return ['id' => $id, 'organization_id' => $organization, 'text' => "Charter {$id}"];
    }

    /**
     * @return array<string, int|null>
     */
    private static function topic(int $id, ?int $parent = null, ?int $supersedes = null): array
    {
        return ['id' => $id, 'parent_id' => $parent, 'supersedes_id' => $supersedes];
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
