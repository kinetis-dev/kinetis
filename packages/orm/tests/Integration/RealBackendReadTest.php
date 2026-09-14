<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests\Integration;

use Kinetis\Orm\EntityRepository;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\Priority;
use Kinetis\Orm\Tests\Fixtures\StoredArticle;
use Kinetis\Orm\Tests\Fixtures\StoredDocument;
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\Query;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Entity reads against real servers, through both the native and the PDO
 * driver of each family, so every row spelling those drivers produce for
 * ints, floats, booleans, nulls and UUID text passes the conversion domain.
 *
 * Environment-gated: each case skips unless MYSQL_HOST or POSTGRES_HOST is
 * set and its driver's extension is loaded. CI's integration workflow runs
 * it against MySQL 8.4, MariaDB 11.4 and PostgreSQL 16.
 */
final class RealBackendReadTest extends TestCase
{
    private const string UUID = '6f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

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
        self::assertSame([1, 'First', null, ArticleStatus::Published, null, true, 4.5, 7], [
            $first->id, $first->title, $first->summary, $first->status, $first->priority, $first->featured, $first->rating, $first->authorId,
        ]);
        self::assertSame([2, 'Second', 'Lead', ArticleStatus::Draft, Priority::High, false, 3.0, 8], [
            $second->id, $second->title, $second->summary, $second->status, $second->priority, $second->featured, $second->rating, $second->authorId,
        ]);
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

        return OrmFactory::create($link, MetadataRegistry::fromClasses([StoredArticle::class, StoredDocument::class]));
    }

    private static function seed(MysqlLink|PostgresLink $link, string $dialect): void
    {
        $link->execute('DROP TABLE IF EXISTS kin_orm_articles');
        $link->execute('DROP TABLE IF EXISTS kin_orm_documents');
        $link->execute(
            'CREATE TABLE kin_orm_articles (id INT PRIMARY KEY, title VARCHAR(100) NOT NULL, summary VARCHAR(200) NULL, '
            . 'status VARCHAR(20) NOT NULL, priority INT NULL, featured BOOLEAN NOT NULL, rating DOUBLE PRECISION NOT NULL, '
            . 'author_id BIGINT NOT NULL)',
        );
        $link->execute(
            'CREATE TABLE kin_orm_documents (id ' . ($dialect === 'mysql' ? 'CHAR(36)' : 'UUID') . ' PRIMARY KEY, '
            . 'title VARCHAR(100) NOT NULL)',
        );

        new Query($link)->table('kin_orm_articles')->insert([
            ['id' => 1, 'title' => 'First', 'summary' => null, 'status' => 'published', 'priority' => null, 'featured' => true, 'rating' => 4.5, 'author_id' => 7],
            ['id' => 2, 'title' => 'Second', 'summary' => 'Lead', 'status' => 'draft', 'priority' => 2, 'featured' => false, 'rating' => 3.0, 'author_id' => 8],
            ['id' => 3, 'title' => 'Third', 'summary' => null, 'status' => 'draft', 'priority' => 1, 'featured' => false, 'rating' => 1.25, 'author_id' => 8],
        ]);
        new Query($link)->table('kin_orm_documents')->insert(['id' => self::UUID, 'title' => 'Spec']);
    }
}
