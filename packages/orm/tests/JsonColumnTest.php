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
use Kinetis\Orm\Tests\Fixtures\Payload;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Orm\Tests\Fixtures\Survey;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Array, stdClass and array|stdClass properties over JSON columns: the JSON
 * text a load decodes, the values an entity may hold, the one encoding
 * every snapshot and write binds, and the predicates a JSON column refuses.
 */
final class JsonColumnTest extends TestCase
{
    private const string EXPECTED = 'takes an array of null, bool, int, finite float, UTF-8 string and nested array values at most 31 levels deep, or the JSON text of one';

    private const string ANSWERS = ' It maps to table "surveys", column "answers".';

    private const string OBJECT_EXPECTED = 'takes a stdClass of null, bool, int, finite float, UTF-8 string, list and nested stdClass values at most 31 levels deep, or the JSON text of a JSON object';

    private const string OBJECT_OR_LIST_EXPECTED = 'takes a stdClass or a list of null, bool, int, finite float, UTF-8 string, list and stdClass values at most 31 levels deep, or the JSON text of a JSON object or array';

    private const string BODY = ' It maps to table "payloads", column "body".';

    private const string DOCUMENT = ' It maps to table "payloads", column "document".';

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
        yield 'nesting beyond the decode depth' => [self::nested(32)];
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
        $deepest = self::nested(31);
        $this->link->queue([['id' => 1, 'answers' => json_encode($deepest), 'extra_data' => null]]);

        $survey = $this->entities->repository(Survey::class)->findOrFail(1);
        self::assertSame($deepest, $survey->answers);

        $survey->answers = ['x' => $deepest[0]];
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertCount(1, $this->transaction->calls);
    }

    public function test_a_managed_value_nested_one_level_too_deep_is_refused_before_the_update(): void
    {
        $this->link->queue([['id' => 1, 'answers' => '[]', 'extra_data' => null]]);
        $survey = $this->entities->repository(Survey::class)->findOrFail(1);
        $survey->answers = self::nested(32);

        try {
            $this->entities->flush();
            self::fail('The value was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Survey::class . '::$answers ' . self::EXPECTED . ', got array.' . self::ANSWERS, $e->getMessage());
        }

        self::assertSame([], $this->transaction->calls);
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
        yield 'nesting beyond the decode depth' => [self::nested(32)];
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

    public function test_json_objects_load_as_stdclass_at_every_depth_and_lists_as_arrays(): void
    {
        $this->link->queue([
            ['id' => 1, 'body' => '{}', 'meta_data' => '{"a": {}, "l": [{}, [], {"n": null}]}', 'document' => '[]', 'draft' => '[{"x": {}}, 1]'],
            ['id' => 2, 'body' => '{"": 1, "1": {"b": []}}', 'meta_data' => null, 'document' => '{}', 'draft' => null],
        ]);

        [$first, $second] = $this->payloads()->repository(Payload::class)->query()->get();

        self::assertEquals(new stdClass(), $first->body);
        self::assertSame('{}', json_encode($first->body));
        self::assertEquals((object) ['a' => new stdClass(), 'l' => [new stdClass(), [], (object) ['n' => null]]], $first->meta);
        self::assertSame('{"a":{},"l":[{},[],{"n":null}]}', json_encode($first->meta));
        self::assertSame([], $first->document);
        self::assertEquals([(object) ['x' => new stdClass()], 1], $first->draft);
        self::assertSame('[{"x":{}},1]', json_encode($first->draft));

        self::assertSame('{"":1,"1":{"b":[]}}', json_encode($second->body));
        self::assertNull($second->meta);
        self::assertEquals(new stdClass(), $second->document);
        self::assertNull($second->draft);
    }

    public function test_an_untouched_empty_object_is_no_change_and_an_object_replaced_by_a_list_is_written(): void
    {
        $this->link->queue([['id' => 1, 'body' => '{}', 'meta_data' => '{"a": {}}', 'document' => '{}', 'draft' => '{"x": []}']]);
        $entities = $this->payloads();
        $payload = $entities->repository(Payload::class)->findOrFail(1);

        $entities->flush();
        self::assertSame(0, $this->link->begins, 'an empty object compares equal to its snapshot');

        $payload->document = [];
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $entities->flush();

        self::assertSame(
            [['sql' => 'UPDATE `payloads` SET `document` = ? WHERE `id` = ?', 'params' => ['[]', 1]]],
            $this->transaction->calls,
        );

        $entities->flush();
        self::assertSame(1, $this->link->begins, 'the snapshot holds the string the flush sent');
    }

    public function test_an_in_place_mutation_of_a_nested_stdclass_is_written(): void
    {
        $this->link->queue([['id' => 1, 'body' => '{"a": {"b": 1}, "l": [{}]}', 'meta_data' => null, 'document' => '[{}]', 'draft' => null]]);
        $entities = $this->payloads();
        $payload = $entities->repository(Payload::class)->findOrFail(1);
        self::assertInstanceOf(stdClass::class, $payload->body->a);
        self::assertIsArray($payload->body->l);
        self::assertIsArray($payload->document);
        self::assertInstanceOf(stdClass::class, $payload->document[0]);

        $payload->body->a->b = 2;
        $payload->body->l[0]->c = 'é/ü';
        $payload->document[0]->d = new stdClass();
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $entities->flush();

        self::assertSame(
            [[
                'sql' => 'UPDATE `payloads` SET `body` = ?, `document` = ? WHERE `id` = ?',
                'params' => ['{"a":{"b":2},"l":[{"c":"é/ü"}]}', '[{"d":{}}]', 1],
            ]],
            $this->transaction->calls,
        );
    }

    public function test_an_insert_writes_each_stdclass_and_list_as_its_json_encoding(): void
    {
        $payload = new Payload();
        $payload->id = 1;
        $payload->body = new stdClass();
        $payload->meta = (object) ['a' => new stdClass(), 'l' => [new stdClass(), [], 1.0], '' => 'x', '7' => true];
        $payload->document = [];
        $payload->draft = null;
        $entities = $this->payloads();
        $entities->persist($payload);
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $entities->flush();

        self::assertSame(
            [[
                'sql' => 'INSERT INTO `payloads` (`id`, `body`, `meta_data`, `document`, `draft`) VALUES (?, ?, ?, ?, ?)',
                'params' => [1, '{}', '{"a":{},"l":[{},[],1.0],"":"x","7":true}', '[]', null],
            ]],
            $this->transaction->calls,
        );
    }

    /**
     * @return iterable<string, array{'body'|'document', mixed, string}>
     */
    public static function refusedObjectDriverValues(): iterable
    {
        yield 'a list for a stdClass property' => ['body', '[]', 'string'];
        yield 'a list of objects for a stdClass property' => ['body', '[{}]', 'string'];
        yield 'a JSON number for a stdClass property' => ['body', '1', 'string'];
        yield 'a JSON null for a stdClass property' => ['body', 'null', 'string'];
        yield 'a JSON string for a union property' => ['document', '"text"', 'string'];
        yield 'a JSON null for a union property' => ['document', 'null', 'string'];
        yield 'malformed JSON' => ['body', '{"a":', 'string'];
        yield 'a key the decoder cannot name a property' => ['body', '{"\u0000a": 1}', 'string'];
        yield 'mixed nesting beyond the decode depth' => ['document', json_encode(self::mixed(32)), 'string'];
        yield 'SQL NULL in a non-nullable column' => ['body', null, 'null'];
        yield 'an int' => ['document', 1, 'int'];
    }

    #[DataProvider('refusedObjectDriverValues')]
    public function test_an_inadmissible_driver_value_for_a_stdclass_property_is_refused(string $property, mixed $value, string $read): void
    {
        $row = ['id' => 1, 'body' => '{}', 'meta_data' => null, 'document' => '[]', 'draft' => null];
        $row[$property] = $value;
        $this->link->queue([$row]);

        try {
            $this->payloads()->repository(Payload::class)->query()->get();
            self::fail('The result was accepted.');
        } catch (MappingException $e) {
            self::assertSame(
                $property === 'body'
                    ? Payload::class . '::$body ' . self::OBJECT_EXPECTED . ", got {$read}." . self::BODY
                    : Payload::class . '::$document ' . self::OBJECT_OR_LIST_EXPECTED . ", got {$read}." . self::DOCUMENT,
                $e->getMessage(),
            );
        }
    }

    public function test_the_deepest_admitted_mixed_nesting_loads_and_writes(): void
    {
        $deepest = self::mixed(31);
        $this->link->queue([['id' => 1, 'body' => '{}', 'meta_data' => null, 'document' => json_encode($deepest), 'draft' => null]]);
        $entities = $this->payloads();

        $payload = $entities->repository(Payload::class)->findOrFail(1);
        self::assertEquals($deepest, $payload->document);

        $payload->document = (object) ['x' => $deepest[0]];
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $entities->flush();

        self::assertCount(1, $this->transaction->calls);
    }

    /**
     * @return iterable<string, array{'body'|'document', stdClass|list<mixed>}>
     */
    public static function refusedObjectEntityValues(): iterable
    {
        yield 'a malformed UTF-8 string inside an object' => ['body', (object) ['a' => (object) ['b' => "\xC3\x28"]]];
        yield 'a malformed UTF-8 key' => ['body', (object) ["\xFF" => 1]];
        yield 'a key json_encode() drops' => ['body', (object) ["\0a" => 1]];
        yield 'INF inside an object' => ['body', (object) ['a' => INF]];
        yield 'NAN in a list inside an object' => ['document', [(object) ['a' => [NAN]]]];
        yield 'mixed nesting beyond the decode depth' => ['document', self::mixed(32)];
        yield 'a stdClass subclass' => ['body', new class extends stdClass {}];
        yield 'a nested stdClass subclass' => ['document', [new class extends stdClass {}]];
        yield 'a nested JsonSerializable' => ['body', (object) ['a' => new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return 1;
            }
        }]];
        yield 'a nested DateTimeImmutable' => ['body', (object) ['at' => new DateTimeImmutable('2026-09-25')]];
        yield 'an array with keys inside an object' => ['body', (object) ['a' => ['k' => 1]]];
        yield 'an array with keys for a union property' => ['document', [1 => 'a']];
    }

    /**
     * @param stdClass|list<mixed> $value
     */
    #[DataProvider('refusedObjectEntityValues')]
    public function test_an_inadmissible_stdclass_or_list_entity_value_is_refused_before_sql(string $property, stdClass|array $value): void
    {
        $payload = new Payload();
        $payload->id = 1;
        $payload->body = new stdClass();
        $payload->meta = null;
        $payload->document = [];
        $payload->draft = null;
        $payload->{$property} = $value;
        $entities = $this->payloads();

        try {
            $entities->persist($payload);
            self::fail('The value was accepted.');
        } catch (MappingException $e) {
            $read = get_debug_type($value);
            self::assertSame(
                $property === 'body'
                    ? Payload::class . '::$body ' . self::OBJECT_EXPECTED . ", got {$read}." . self::BODY
                    : Payload::class . '::$document ' . self::OBJECT_OR_LIST_EXPECTED . ", got {$read}." . self::DOCUMENT,
                $e->getMessage(),
            );
        }

        self::assertSame([], $this->transaction->calls);
    }

    public function test_a_predicate_on_a_stdclass_property_is_refused_before_sql(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Payload::class . '::$body maps a JSON column');

        try {
            $this->payloads()->repository(Payload::class)->query()->where('body', '=', '{}');
        } finally {
            self::assertSame([], $this->link->calls);
        }
    }

    public function test_an_order_on_an_array_stdclass_property_is_refused_before_sql(): void
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Payload::class . '::$draft maps a JSON column');

        try {
            $this->payloads()->repository(Payload::class)->query()->orderBy('draft');
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

    private function payloads(): EntityManager
    {
        return OrmFactory::create($this->link, MetadataRegistry::fromClasses([Payload::class]))->open();
    }

    /**
     * $levels containers deep, alternating a list and a stdClass from the
     * outside in: the innermost is an empty list or stdClass.
     *
     * @return list<mixed>
     */
    private static function mixed(int $levels): array
    {
        $value = $levels % 2 === 0 ? new stdClass() : [];

        for ($level = $levels - 1; $level >= 1; $level--) {
            $value = $level % 2 === 1 ? [$value] : (object) ['k' => $value];
        }

        /** @var list<mixed> $value */
        return $value;
    }
}
