<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use InvalidArgumentException;
use Kinetis\Orm\EntityQuery;
use Kinetis\Orm\EntityRepository;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Article;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyPostgresLink;
use Kinetis\QueryBuilder\CursorPaginator;
use Kinetis\QueryBuilder\Exception\QueryBuilderException;
use Kinetis\QueryBuilder\Paginator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class EntityQueryTest extends TestCase
{
    private const string COLUMNS = '`id`, `title`, `summary`, `status`, `priority`, `featured`, `rating`, `author`, `slug`';

    private SpyMysqlLink $link;

    /** @var EntityRepository<Article> */
    private EntityRepository $articles;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->articles = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Article::class]))
            ->open()
            ->repository(Article::class);
    }

    public function test_properties_resolve_to_columns_and_values_convert_before_they_reach_query(): void
    {
        $this->link->queue([Article::row()]);

        $this->articles->query()
            ->where('authorId', '=', '7')
            ->where('status', '=', ArticleStatus::Draft)
            ->where('featured', '!=', '0')
            ->whereIn('priority', [Priority::High, '1'])
            ->orderBy('authorId', 'desc')
            ->limit(5)
            ->offset(10)
            ->get();

        self::assertSame([[
            'sql' => 'SELECT ' . self::COLUMNS . ' FROM `articles` WHERE `author` = ? AND `status` = ? AND `featured` != ? '
                . 'AND `priority` IN (?, ?) ORDER BY `author` DESC LIMIT 5 OFFSET 10',
            'params' => [7, 'draft', false, 2, 1],
        ]], $this->link->calls);
    }

    public function test_the_link_dialect_quotes_the_mapped_names(): void
    {
        $link = new SpyPostgresLink();

        OrmFactory::create($link, MetadataRegistry::fromClasses([Article::class]))
            ->open()
            ->repository(Article::class)
            ->query()
            ->where('title', 'LIKE', 'Fir%')
            ->get();

        self::assertSame([[
            'sql' => 'SELECT "id", "title", "summary", "status", "priority", "featured", "rating", "author", "slug" '
                . 'FROM "articles" WHERE "title" LIKE ?',
            'params' => ['Fir%'],
        ]], $link->calls);
    }

    public function test_a_null_value_on_a_nullable_property_compiles_to_query_builder_null_predicates(): void
    {
        $this->articles->query()->where('summary', '=', null)->where('priority', '!=', null)->get();

        self::assertSame(
            'SELECT ' . self::COLUMNS . ' FROM `articles` WHERE `summary` IS NULL AND `priority` IS NOT NULL',
            $this->link->calls[0]['sql'],
        );
    }

    /**
     * @return iterable<string, array{Closure(EntityQuery<Article>): mixed, class-string<Throwable>, string}>
     */
    public static function refusedBeforeSql(): iterable
    {
        yield 'where() on a column name' => [static fn (EntityQuery $q): mixed => $q->where('author', '=', 7)->get(), MappingException::class, 'has no mapped property "author"'];
        yield 'where() with an inadmissible value' => [static fn (EntityQuery $q): mixed => $q->where('authorId', '=', 'seven')->get(), MappingException::class, '::$authorId takes an int'];
        yield 'where() with null on a non-nullable property' => [static fn (EntityQuery $q): mixed => $q->where('title', '=', null)->get(), MappingException::class, '::$title takes a string, got null'];
        yield 'where() comparing null by order' => [static fn (EntityQuery $q): mixed => $q->where('summary', '>', null)->get(), InvalidArgumentException::class, 'cannot be used with a null value'];
        yield 'whereIn() on an unknown property' => [static fn (EntityQuery $q): mixed => $q->whereIn('tags', [1])->get(), MappingException::class, 'has no mapped property "tags"'];
        yield 'whereIn() with an unknown enum value' => [static fn (EntityQuery $q): mixed => $q->whereIn('status', ['draft', 'archived'])->get(), MappingException::class, 'the string value names none'];
        yield 'whereIn() with a null member' => [static fn (EntityQuery $q): mixed => $q->whereIn('summary', ['a', null])->get(), InvalidArgumentException::class, 'cannot take null'];
        yield 'orderBy() on an unknown property' => [static fn (EntityQuery $q): mixed => $q->orderBy('author')->get(), MappingException::class, 'has no mapped property "author"'];
        yield 'orderBy() with an unknown direction' => [static fn (EntityQuery $q): mixed => $q->orderBy('id', 'sideways')->get(), InvalidArgumentException::class, 'is not allowed'];
        yield 'cursorPaginate() on an unknown property' => [static fn (EntityQuery $q): mixed => $q->cursorPaginate(2, null, 'author'), MappingException::class, 'has no mapped property "author"'];
        yield 'paginate() without an order' => [static fn (EntityQuery $q): mixed => $q->paginate(2), QueryBuilderException::class, 'needs an orderBy()'];
    }

    /**
     * @param Closure(EntityQuery<Article>): mixed $read
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusedBeforeSql')]
    public function test_an_unknown_property_or_refused_value_fails_before_sql(Closure $read, string $exception, string $message): void
    {
        try {
            $read($this->articles->query());
            self::fail('The read was accepted.');
        } catch (Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertStringContainsString($message, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    public function test_builder_returns_a_copy_whose_changes_and_rows_bypass_the_entity_query(): void
    {
        $query = $this->articles->query()->where('authorId', '=', 7);
        $builder = $query->builder();
        $builder->where('slug', '=', 'first')->limit(1);
        $this->link->queue([Article::row()], [Article::row()], [Article::row()]);

        self::assertSame([Article::row()], $builder->get());
        self::assertSame(
            ['sql' => 'SELECT ' . self::COLUMNS . ' FROM `articles` WHERE `author` = ? AND `slug` = ? LIMIT 1', 'params' => [7, 'first']],
            $this->link->calls[0],
        );

        $article = $this->articles->find(1);
        self::assertCount(2, $this->link->calls, 'rows read through builder() are never managed');

        self::assertSame([$article], $query->get());
        self::assertSame('SELECT ' . self::COLUMNS . ' FROM `articles` WHERE `author` = 7', $this->link->calls[2]['sql']);
    }

    public function test_first_exists_and_count(): void
    {
        $this->link->queue([Article::row()], [['aggregate' => 1]], [['aggregate' => 4]]);
        $query = $this->articles->query();

        $article = $query->first();

        self::assertInstanceOf(Article::class, $article);
        self::assertTrue($query->exists());
        self::assertSame(4, $query->count());
        self::assertNull($query->first());
        self::assertSame([
            'SELECT ' . self::COLUMNS . ' FROM `articles` LIMIT 1',
            'SELECT CASE WHEN EXISTS (SELECT ' . self::COLUMNS . ' FROM `articles`) THEN 1 ELSE 0 END AS aggregate',
            'SELECT COUNT(*) AS aggregate FROM `articles`',
            'SELECT ' . self::COLUMNS . ' FROM `articles` LIMIT 1',
        ], $this->link->statements());
        self::assertSame($article, $this->articles->find(1));
    }

    public function test_paginate_keeps_the_envelope_and_loads_managed_entities(): void
    {
        $this->link->queue([['aggregate' => 3]], [Article::row(), Article::row(['id' => 2, 'slug' => 'second'])]);

        $page = $this->articles->query()->orderBy('id')->paginate(2);

        self::assertInstanceOf(Paginator::class, $page);
        self::assertSame([1, 2, 3, 2], [$page->currentPage, $page->perPage, $page->total, $page->lastPage]);
        self::assertCount(2, $page->data);
        self::assertSame($page->data[0], $this->articles->find(1));
        self::assertSame($page->data[1], $this->articles->find(2));
        self::assertCount(2, $this->link->calls);
    }

    public function test_cursor_paginate_maps_its_property_and_loads_managed_entities(): void
    {
        $this->link->queue(
            [Article::row(), Article::row(['id' => 2, 'slug' => 'second']), Article::row(['id' => 3, 'slug' => 'third'])],
            [Article::row(['id' => 2, 'author' => 9, 'slug' => 'second'])],
        );

        $page = $this->articles->query()->cursorPaginate(2, null);

        self::assertInstanceOf(CursorPaginator::class, $page);
        self::assertTrue($page->hasMore);
        self::assertSame('2', $page->nextCursor);
        self::assertSame($page->data[0], $this->articles->find(1));
        self::assertSame('SELECT ' . self::COLUMNS . ' FROM `articles` ORDER BY `id` ASC LIMIT 3', $this->link->calls[0]['sql']);

        $byAuthor = $this->articles->query()->cursorPaginate(2, '7', 'authorId');

        self::assertFalse($byAuthor->hasMore);
        self::assertNull($byAuthor->nextCursor);
        self::assertSame([$page->data[1]], $byAuthor->data, 'a held identity is reused, not overwritten');
        self::assertSame(7, $page->data[1]->authorId);
        self::assertSame(
            ['sql' => 'SELECT ' . self::COLUMNS . ' FROM `articles` WHERE `author` > ? ORDER BY `author` ASC LIMIT 3', 'params' => ['7']],
            $this->link->calls[1],
        );
    }
}
