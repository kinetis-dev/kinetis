<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Event;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DateTimeImmutable properties over UTC timestamp columns: the driver
 * spellings a load admits, the string every predicate, cursor, snapshot and
 * write binds, and the values refused before allocation or SQL. The process's
 * default time zone is UTC+14 throughout, so a value parsed or formatted
 * outside UTC changes what is asserted.
 */
final class TimestampTest extends TestCase
{
    private const string EXPECTED = 'takes a DateTimeImmutable, or a UTC "Y-m-d H:i:s" string with up to six fraction digits and no offset, in UTC years 0001 to 9999';

    private const string OCCURRED_AT = ' It maps to table "events", column "occurred_at".';

    private const string ARCHIVED_AT = ' It maps to table "events", column "archived_at".';

    private string $defaultZone;

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->defaultZone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->entities = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Event::class]))->open();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultZone);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function driverSpellings(): iterable
    {
        yield 'six fraction digits, as MySQL and MariaDB return DATETIME(6)' => ['2026-03-04 05:06:07.123456', '2026-03-04 05:06:07.123456'];
        yield 'trailing fraction zeros trimmed, as PostgreSQL returns them' => ['2026-03-04 05:06:07.5', '2026-03-04 05:06:07.500000'];
        yield 'no fraction, as PostgreSQL returns a whole second' => ['2026-03-04 05:06:07', '2026-03-04 05:06:07.000000'];
        yield 'a leap day' => ['2024-02-29 23:59:59.000001', '2024-02-29 23:59:59.000001'];
        yield 'the first instant of year 0001' => ['0001-01-01 00:00:00', '0001-01-01 00:00:00.000000'];
        yield 'the last microsecond of year 9999' => ['9999-12-31 23:59:59.999999', '9999-12-31 23:59:59.999999'];
    }

    #[DataProvider('driverSpellings')]
    public function test_a_driver_spelling_loads_as_a_utc_date_time_immutable_and_writes_nothing_back(string $spelling, string $utc): void
    {
        $this->link->queue([['id' => 1, 'occurred_at' => $spelling, 'archived_at' => null]]);

        $event = $this->entities->repository(Event::class)->findOrFail(1);

        self::assertSame(DateTimeImmutable::class, $event->occurredAt::class);
        self::assertSame("{$utc} UTC", $event->occurredAt->format('Y-m-d H:i:s.u e'));
        self::assertNull($event->archivedAt);

        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'the loaded value compares equal to its snapshot');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedDriverValues(): iterable
    {
        yield 'a dot without digits' => ['2026-03-04 05:06:07.'];
        yield 'seven fraction digits' => ['2026-03-04 05:06:07.1234567'];
        yield 'an offset, as timestamptz returns' => ['2026-03-04 05:06:07.123456+00'];
        yield 'an offset with minutes' => ['2026-03-04 05:06:07+00:00'];
        yield 'ISO 8601 separators' => ['2026-03-04T05:06:07Z'];
        yield 'a non-ISO DateStyle' => ['03/04/2026 05:06:07.123456'];
        yield 'a day that does not exist' => ['2026-02-30 00:00:00'];
        yield 'a leap day outside a leap year' => ['2025-02-29 00:00:00'];
        yield 'hour 24' => ['2026-03-04 24:00:00'];
        yield 'a leap second' => ['2016-12-31 23:59:60'];
        yield 'the zero date' => ['0000-00-00 00:00:00.000000'];
        yield 'year 0000' => ['0000-01-01 00:00:00'];
        yield 'year 10000' => ['10000-01-01 00:00:00'];
        yield 'a BC suffix' => ['0044-03-15 00:00:00 BC'];
        yield 'leading whitespace' => [' 2026-03-04 05:06:07'];
        yield 'a trailing newline' => ["2026-03-04 05:06:07\n"];
        yield 'a relative word' => ['now'];
        yield 'an int' => [1772600767];
        yield 'a mutable DateTime' => [new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'))];
    }

    #[DataProvider('refusedDriverValues')]
    public function test_a_result_holding_an_inadmissible_timestamp_allocates_no_entity(mixed $value): void
    {
        gc_collect_cycles();
        Event::$destroyed = 0;
        $this->link->queue([
            ['id' => 1, 'occurred_at' => '2026-03-04 05:06:07', 'archived_at' => null],
            ['id' => 2, 'occurred_at' => $value, 'archived_at' => null],
        ]);

        try {
            $this->entities->repository(Event::class)->query()->get();
            self::fail('The result was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Event::class . '::$occurredAt ' . self::EXPECTED . ', got ' . get_debug_type($value) . '.' . self::OCCURRED_AT, $e->getMessage());
        }

        gc_collect_cycles();
        self::assertSame(0, Event::$destroyed, 'no entity of the failed result was allocated and dropped');
    }

    public function test_a_predicate_value_binds_its_utc_microsecond_string_in_placeholder_order(): void
    {
        $this->entities->repository(Event::class)->query()
            ->where('occurredAt', '>=', new DateTimeImmutable('2026-03-04 07:06:07.123456', new DateTimeZone('+02:00')))
            ->whereIn('archivedAt', ['2026-03-04 05:06:07.5', new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'))])
            ->where('occurredAt', '<', '2026-03-05 00:00:00')
            ->get();

        self::assertSame([[
            'sql' => 'SELECT `id`, `occurred_at`, `archived_at` FROM `events` WHERE `occurred_at` >= ? AND `archived_at` IN (?, ?) AND `occurred_at` < ?',
            'params' => ['2026-03-04 05:06:07.123456', '2026-03-04 05:06:07.500000', '2026-03-04 05:06:07.000000', '2026-03-05 00:00:00.000000'],
        ]], $this->link->calls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedPredicateValues(): iterable
    {
        yield 'an offset, which the MySQL family converts through the session time zone' => ['2026-03-04 07:06:07+02:00'];
        yield 'a day that does not exist' => ['2026-02-30 00:00:00'];
        yield 'an instant in UTC year 10000' => [new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC'))->modify('+1 second')];
        yield 'an instant in local year 0001 and UTC year 0000' => [new DateTimeImmutable('0001-01-01 00:30:00', new DateTimeZone('+01:00'))];
        yield 'a mutable DateTime' => [new DateTime('2026-03-04 05:06:07', new DateTimeZone('UTC'))];
    }

    #[DataProvider('refusedPredicateValues')]
    public function test_an_inadmissible_predicate_value_fails_before_sql(mixed $value): void
    {
        $query = $this->entities->repository(Event::class)->query()->where('id', '=', 1);

        try {
            $query->where('archivedAt', '=', $value)->get();
            self::fail('The value was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Event::class . '::$archivedAt ' . self::EXPECTED . ', or null, got ' . get_debug_type($value) . '.' . self::ARCHIVED_AT, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    /**
     * @return iterable<string, array{?string, array{sql: string, params: list<mixed>}}>
     */
    public static function acceptedCursors(): iterable
    {
        $select = 'SELECT `id`, `occurred_at`, `archived_at` FROM `events`';

        yield 'no cursor, on a property that is not nullable' => [null, ['sql' => "{$select} ORDER BY `occurred_at` ASC LIMIT 3", 'params' => []]];
        yield 'six fraction digits, as a MySQL or MariaDB page returns' => ['2026-03-04 05:06:07.123456', ['sql' => "{$select} WHERE `occurred_at` > ? ORDER BY `occurred_at` ASC LIMIT 3", 'params' => ['2026-03-04 05:06:07.123456']]];
        yield 'trailing fraction zeros trimmed, as a PostgreSQL page returns' => ['2026-03-04 05:06:07.5', ['sql' => "{$select} WHERE `occurred_at` > ? ORDER BY `occurred_at` ASC LIMIT 3", 'params' => ['2026-03-04 05:06:07.500000']]];
    }

    /**
     * @param array{sql: string, params: list<mixed>} $statement
     */
    #[DataProvider('acceptedCursors')]
    public function test_a_timestamp_cursor_binds_its_utc_microsecond_string(?string $cursor, array $statement): void
    {
        $this->entities->repository(Event::class)->query()->cursorPaginate(2, $cursor, 'occurredAt');

        self::assertSame([$statement], $this->link->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCursors(): iterable
    {
        yield 'an offset, which the MySQL family converts through the session time zone' => ['2026-03-04 07:06:07.123456+02:00'];
        yield 'ISO 8601 separators' => ['2026-03-04T05:06:07Z'];
        yield 'an empty string' => [''];
        yield 'year 0000' => ['0000-12-31 23:59:59.999999'];
        yield 'year 10000' => ['10000-01-01 00:00:00'];
        yield 'a day that does not exist' => ['2026-02-30 00:00:00'];
    }

    #[DataProvider('refusedCursors')]
    public function test_an_inadmissible_timestamp_cursor_fails_before_sql(string $cursor): void
    {
        try {
            $this->entities->repository(Event::class)->query()->cursorPaginate(2, $cursor, 'occurredAt');
            self::fail('The cursor was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Event::class . '::$occurredAt ' . self::EXPECTED . ', got string.' . self::OCCURRED_AT, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    public function test_the_same_instant_in_another_zone_is_no_change_and_one_microsecond_is(): void
    {
        $this->link->queue([['id' => 1, 'occurred_at' => '2026-03-04 05:06:07.123456', 'archived_at' => null]]);
        $event = $this->entities->repository(Event::class)->findOrFail(1);

        $event->occurredAt = new DateTimeImmutable('2026-03-04 07:06:07.123456', new DateTimeZone('+02:00'));
        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'one instant has one database value in every zone');

        $event->occurredAt = new DateTimeImmutable('2026-03-04 07:06:07.123457', new DateTimeZone('+02:00'));
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'UPDATE `events` SET `occurred_at` = ? WHERE `id` = ?', 'params' => ['2026-03-04 05:06:07.123457', 1]]],
            $this->transaction->calls,
        );
        self::assertSame(['commit'], $this->transaction->ends);

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the snapshot holds the string the flush sent');
    }

    public function test_an_insert_writes_each_instant_as_its_utc_string_and_refuses_one_past_year_9999_before_sql(): void
    {
        $late = new Event();
        $late->id = 2;
        $late->occurredAt = new DateTimeImmutable('9999-12-31 23:59:59', new DateTimeZone('UTC'))->modify('+1 second');
        $late->archivedAt = null;

        try {
            $this->entities->persist($late);
            self::fail('The instant was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Event::class . '::$occurredAt ' . self::EXPECTED . ', got DateTimeImmutable.' . self::OCCURRED_AT, $e->getMessage());
        }

        self::assertFalse($this->entities->contains($late));

        $event = new Event();
        $event->id = 1;
        $event->occurredAt = new class ('2026-03-04 10:36:07.000001', new DateTimeZone('Asia/Kolkata')) extends DateTimeImmutable {};
        $event->archivedAt = new DateTimeImmutable('2026-03-04 00:00:00', new DateTimeZone('America/New_York'));
        $this->entities->persist($event);
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'INSERT INTO `events` (`id`, `occurred_at`, `archived_at`) VALUES (?, ?, ?)', 'params' => [1, '2026-03-04 05:06:07.000001', '2026-03-04 05:00:00.000000']]],
            $this->transaction->calls,
        );
        self::assertSame([], $this->link->calls);
    }
}
