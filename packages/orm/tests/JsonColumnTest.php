<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use Closure;
use DateTimeImmutable;
use JsonSerializable;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\EntityQuery;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\ArticleStatus;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\Survey;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Array properties over JSON columns: the JSON text a load decodes, the
 * arrays an entity may hold, the one encoding every snapshot and write
 * binds, and the predicates a JSON column refuses.
 */
final class JsonColumnTest extends TestCase
{
    private const string EXPECTED = 'takes an array of null, bool, int, finite float, UTF-8 string and nested array values at most 511 levels deep, or the JSON text of one';

    private const string ANSWERS = ' It maps to table "surveys", column "answers".';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->entities = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Survey::class]))->open();
    }

    public function test_json_text_loads_decoded_and_sql_null_loads_null_where_nullable(): void
    {
        $this->link->queue([
            ['id' => 1, 'answers' => '{"b": [1, 2.5, null], "a": {"ok": true, "name": "Zoë"}}', 'extra_data' => null],
            ['id' => 2, 'answers' => '[]', 'extra_data' => '{}'],
        ]);

        [$first, $second] = $this->entities->repository(Survey::class)->query()->get();

        self::assertSame(['b' => [1, 2.5, null], 'a' => ['ok' => true, 'name' => 'Zoë']], $first->answers);
        self::assertNull($first->extra);
        self::assertSame([], $second->answers);
        self::assertSame([], $second->extra, 'an empty JSON object loads as an empty array');
    }

    public function test_text_the_server_respaced_or_reordered_is_no_change_and_a_nested_mutation_writes_its_encoding(): void
    {
        // MySQL's JSON type answers with a space after each separator and
        // its own key order, never the text the flush sent.
        $this->link->queue([['id' => 1, 'answers' => '{"b": 1, "a": {"x": "é/ü", "f": 1.0}}', 'extra_data' => '[1, 2]']]);
        $survey = $this->entities->repository(Survey::class)->findOrFail(1);

        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'the loaded value compares equal to its snapshot');

        $survey->answers['a']['x'] = 'changed';
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'UPDATE `surveys` SET `answers` = ? WHERE `id` = ?', 'params' => ['{"b":1,"a":{"x":"changed","f":1.0}}', 1]]],
            $this->transaction->calls,
        );

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the snapshot holds the string the flush sent');
    }

    public function test_an_insert_writes_each_array_as_its_json_encoding(): void
    {
        $survey = new Survey();
        $survey->id = 1;
        $survey->answers = ['path' => 'a/b', 'name' => 'Zoë', 'ratio' => 1.0, 'list' => [true, null, -3]];
        $survey->extra = null;
        $this->entities->persist($survey);
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [[
                'sql' => 'INSERT INTO `surveys` (`id`, `answers`, `extra_data`) VALUES (?, ?, ?)',
                'params' => [1, '{"path":"a/b","name":"Zoë","ratio":1.0,"list":[true,null,-3]}', null],
            ]],
            $this->transaction->calls,
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedDriverValues(): iterable
    {
        yield 'SQL NULL in a non-nullable column' => [null];
        yield 'a JSON null' => ['null'];
        yield 'a JSON number' => ['1'];
        yield 'a JSON string' => ['"text"'];
        yield 'malformed JSON' => ['{"a":'];
        yield 'an empty string' => [''];
        yield 'a number beyond the float range' => ['{"a": 1e999}'];
        yield 'nesting beyond the decode depth' => [self::nested(512)];
        yield 'an int' => [1];
    }

    #[DataProvider('refusedDriverValues')]
    public function test_an_inadmissible_driver_value_is_refused_without_quoting_it(mixed $value): void
    {
        $this->link->queue([['id' => 1, 'answers' => is_array($value) ? json_encode($value) : $value, 'extra_data' => null]]);

        try {
            $this->entities->repository(Survey::class)->query()->get();
            self::fail('The result was accepted.');
        } catch (MappingException $e) {
            $read = is_array($value) ? 'string' : get_debug_type($value);
            self::assertSame(Survey::class . '::$answers ' . self::EXPECTED . ", got {$read}." . self::ANSWERS, $e->getMessage());
        }
    }

    public function test_the_deepest_admitted_nesting_loads_and_writes(): void
    {
        $deepest = self::nested(511);
        $this->link->queue([['id' => 1, 'answers' => json_encode($deepest, depth: 512), 'extra_data' => null]]);

        $survey = $this->entities->repository(Survey::class)->findOrFail(1);
        self::assertSame($deepest, $survey->answers);

        $survey->answers = ['x' => $deepest[0]];
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertCount(1, $this->transaction->calls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedEntityValues(): iterable
    {
        yield 'a nested DateTimeImmutable' => [['at' => new DateTimeImmutable('2026-09-25')]];
        yield 'a JsonSerializable' => [[new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return 1;
            }
        }]];
        yield 'a backed enum case' => [['status' => ArticleStatus::Draft]];
        yield 'a stdClass' => [[new stdClass()]];
        yield 'a closure' => [[static fn (): int => 1]];
        yield 'INF' => [[INF]];
        yield 'a nested NAN' => [['a' => ['b' => NAN]]];
        yield 'a malformed UTF-8 string' => [["\xC3\x28"]];
        yield 'a malformed UTF-8 key' => [["\xFF" => 1]];
        yield 'nesting beyond the decode depth' => [self::nested(512)];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    #[DataProvider('refusedEntityValues')]
    public function test_an_inadmissible_entity_value_is_refused_before_sql(array $value): void
    {
        $survey = new Survey();
        $survey->id = 1;
        $survey->answers = $value;
        $survey->extra = null;

        try {
            $this->entities->persist($survey);
            self::fail('The value was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Survey::class . '::$answers ' . self::EXPECTED . ', got array.' . self::ANSWERS, $e->getMessage());
        }

        self::assertSame([], $this->transaction->calls);
    }

    /**
     * @return iterable<string, array{Closure(EntityQuery<Survey>): mixed}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'where' => [static fn (EntityQuery $query): mixed => $query->where('answers', '=', ['a' => 1])];
        yield 'where null' => [static fn (EntityQuery $query): mixed => $query->where('extra', '=', null)];
        yield 'whereIn' => [static fn (EntityQuery $query): mixed => $query->whereIn('answers', [[1]])];
        yield 'orderBy' => [static fn (EntityQuery $query): mixed => $query->orderBy('extra')];
        yield 'cursorPaginate' => [static fn (EntityQuery $query): mixed => $query->cursorPaginate(2, null, 'answers')];
    }

    /**
     * @param Closure(EntityQuery<Survey>): mixed $apply
     */
    #[DataProvider('refusedQueries')]
    public function test_a_predicate_order_or_cursor_on_a_json_property_is_refused_before_sql(Closure $apply): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote(Survey::class, '/') . '::\$(answers|extra) maps a JSON column, so no predicate, order or cursor can name it\. '
            . 'Filter or order by its content in SQL on EntityQuery::builder\(\), with whereRaw\(\) or orderByRaw\(\)\.$/');

        try {
            $apply($this->entities->repository(Survey::class)->query());
        } finally {
            self::assertSame([], $this->link->calls);
        }
    }

    public function test_find_by_a_json_property_is_refused_before_sql(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Survey::class . '::$extra maps a JSON column');

        try {
            $this->entities->repository(Survey::class)->findBy(['id' => 1, 'extra' => null]);
        } finally {
            self::assertSame([], $this->link->calls);
        }
    }

    /**
     * An array $levels arrays deep: the innermost is empty.
     *
     * @return array<array-key, mixed>
     */
    private static function nested(int $levels): array
    {
        $array = [];

        for ($level = 1; $level < $levels; $level++) {
            $array = [$array];
        }

        return $array;
    }
}
