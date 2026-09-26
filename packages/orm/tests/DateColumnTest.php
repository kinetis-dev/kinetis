<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Date;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\Exception\MappingException;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactory;
use Kinetis\Orm\Tests\Fixtures\Booking;
use Kinetis\Orm\Tests\Fixtures\Event;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlLink;
use Kinetis\Orm\Tests\Fixtures\SpyMysqlTransaction;
use Kinetis\Persistence\Driver\BufferedSqlResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Date properties over SQL DATE columns: the "Y-m-d" string a load admits,
 * and the same string every predicate, cursor, snapshot and write binds.
 */
final class DateColumnTest extends TestCase
{
    private const string EXPECTED = 'takes a Kinetis\\Orm\\Date, or a "Y-m-d" string naming a day that exists in years 0001 to 9999';

    private const string ARRIVES_ON = ' It maps to table "bookings", column "arrives_on".';

    private const string CANCELLED_ON = ' It maps to table "bookings", column "cancelled_on".';

    private SpyMysqlLink $link;

    private SpyMysqlTransaction $transaction;

    private EntityManager $entities;

    protected function setUp(): void
    {
        $this->link = new SpyMysqlLink();
        $this->link->transaction = $this->transaction = new SpyMysqlTransaction();
        $this->entities = OrmFactory::create($this->link, MetadataRegistry::fromClasses([Booking::class, Event::class]))->open();
    }

    public function test_a_sql_date_loads_into_a_date_property_and_a_timestamp_property_still_refuses_it(): void
    {
        $this->link->queue([['id' => 1, 'arrives_on' => '2026-09-25', 'cancelled_on' => '2024-02-29']]);

        $booking = $this->entities->repository(Booking::class)->findOrFail(1);

        self::assertSame([2026, 9, 25], [$booking->arrivesOn->year, $booking->arrivesOn->month, $booking->arrivesOn->day]);
        self::assertSame('2024-02-29', (string) $booking->cancelledOn);

        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'the loaded value compares equal to its snapshot');

        $this->link->queue([['id' => 1, 'occurred_at' => '2026-09-25', 'archived_at' => null]]);

        try {
            $this->entities->repository(Event::class)->findOrFail(1);
            self::fail('The timestamp property accepted a date.');
        } catch (MappingException $e) {
            self::assertStringStartsWith(Event::class . '::$occurredAt takes a DateTimeImmutable', $e->getMessage());
        }
    }

    public function test_a_date_the_row_already_holds_is_admitted_as_that_instance_and_null_only_where_nullable(): void
    {
        $day = new Date(2026, 9, 25);
        $this->link->queue([['id' => 1, 'arrives_on' => $day, 'cancelled_on' => null]]);

        $booking = $this->entities->repository(Booking::class)->findOrFail(1);

        self::assertSame($day, $booking->arrivesOn);
        self::assertNull($booking->cancelledOn);

        $this->link->queue([['id' => 2, 'arrives_on' => null, 'cancelled_on' => null]]);

        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(Booking::class . '::$arrivesOn ' . self::EXPECTED . ', got null.');

        $this->entities->repository(Booking::class)->findOrFail(2);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedDriverValues(): iterable
    {
        yield 'a timestamp' => ['2026-09-25 00:00:00'];
        yield 'an offset' => ['2026-09-25+00'];
        yield 'leading whitespace' => [' 2026-09-25'];
        yield 'a trailing newline' => ["2026-09-25\n"];
        yield 'year 0000' => ['0000-01-01'];
        yield 'the zero date' => ['0000-00-00'];
        yield 'year 10000' => ['10000-01-01'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'a leap day outside a leap year' => ['2025-02-29'];
        yield 'a BC suffix' => ['0044-03-15 BC'];
        yield 'an int' => [20260925];
        yield 'a DateTimeImmutable' => [new DateTimeImmutable('2026-09-25', new DateTimeZone('UTC'))];
    }

    #[DataProvider('refusedDriverValues')]
    public function test_an_inadmissible_date_is_refused_without_quoting_it(mixed $value): void
    {
        $this->link->queue([['id' => 1, 'arrives_on' => $value, 'cancelled_on' => null]]);

        try {
            $this->entities->repository(Booking::class)->query()->get();
            self::fail('The result was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Booking::class . '::$arrivesOn ' . self::EXPECTED . ', got ' . get_debug_type($value) . '.' . self::ARRIVES_ON, $e->getMessage());
        }
    }

    public function test_a_predicate_value_binds_its_y_m_d_string(): void
    {
        $this->entities->repository(Booking::class)->query()
            ->where('arrivesOn', '>=', new Date(2026, 9, 1))
            ->whereIn('cancelledOn', ['2024-02-29', new Date(1, 1, 1)])
            ->where('arrivesOn', '<', '2026-10-01')
            ->get();
        $this->entities->repository(Booking::class)->findBy(['arrivesOn' => new Date(2026, 9, 25), 'cancelledOn' => null]);

        self::assertSame([
            [
                'sql' => 'SELECT `id`, `arrives_on`, `cancelled_on` FROM `bookings` WHERE `arrives_on` >= ? AND `cancelled_on` IN (?, ?) AND `arrives_on` < ?',
                'params' => ['2026-09-01', '2024-02-29', '0001-01-01', '2026-10-01'],
            ],
            [
                'sql' => 'SELECT `id`, `arrives_on`, `cancelled_on` FROM `bookings` WHERE `arrives_on` = ? AND `cancelled_on` IS NULL',
                'params' => ['2026-09-25'],
            ],
        ], $this->link->calls);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedPredicateValues(): iterable
    {
        yield 'a timestamp' => ['2026-09-25 00:00:00'];
        yield 'a day that does not exist' => ['2026-02-30'];
        yield 'a DateTimeImmutable' => [new DateTimeImmutable('2026-09-25', new DateTimeZone('UTC'))];
    }

    #[DataProvider('refusedPredicateValues')]
    public function test_an_inadmissible_predicate_value_fails_before_sql(mixed $value): void
    {
        try {
            $this->entities->repository(Booking::class)->query()->where('cancelledOn', '=', $value)->get();
            self::fail('The value was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Booking::class . '::$cancelledOn ' . self::EXPECTED . ', or null, got ' . get_debug_type($value) . '.' . self::CANCELLED_ON, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    public function test_a_date_cursor_binds_its_y_m_d_string_and_a_malformed_one_fails_before_sql(): void
    {
        $this->entities->repository(Booking::class)->query()->cursorPaginate(2, '2026-09-25', 'arrivesOn');

        self::assertSame([[
            'sql' => 'SELECT `id`, `arrives_on`, `cancelled_on` FROM `bookings` WHERE `arrives_on` > ? ORDER BY `arrives_on` ASC LIMIT 3',
            'params' => ['2026-09-25'],
        ]], $this->link->calls);

        $this->link->calls = [];

        try {
            $this->entities->repository(Booking::class)->query()->cursorPaginate(2, '2026-09-25 00:00:00', 'arrivesOn');
            self::fail('The cursor was accepted.');
        } catch (MappingException $e) {
            self::assertSame(Booking::class . '::$arrivesOn ' . self::EXPECTED . ', got string.' . self::ARRIVES_ON, $e->getMessage());
        }

        self::assertSame([], $this->link->calls);
    }

    public function test_the_same_day_is_no_change_and_the_next_day_writes_its_y_m_d_string(): void
    {
        $this->link->queue([['id' => 1, 'arrives_on' => '2026-09-25', 'cancelled_on' => null]]);
        $booking = $this->entities->repository(Booking::class)->findOrFail(1);

        $booking->arrivesOn = new Date(2026, 9, 25);
        $this->entities->flush();
        self::assertSame(0, $this->link->begins, 'one day has one database value');

        $booking->arrivesOn = new Date(2026, 9, 26);
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'UPDATE `bookings` SET `arrives_on` = ? WHERE `id` = ?', 'params' => ['2026-09-26', 1]]],
            $this->transaction->calls,
        );

        $this->entities->flush();
        self::assertSame(1, $this->link->begins, 'the snapshot holds the string the flush sent');
    }

    public function test_an_insert_writes_each_date_as_its_y_m_d_string(): void
    {
        $booking = new Booking();
        $booking->id = 1;
        $booking->arrivesOn = new Date(9999, 12, 31);
        $booking->cancelledOn = null;
        $this->entities->persist($booking);
        $this->transaction->queue(new BufferedSqlResult([], 1, null));
        $this->entities->flush();

        self::assertSame(
            [['sql' => 'INSERT INTO `bookings` (`id`, `arrives_on`, `cancelled_on`) VALUES (?, ?, ?)', 'params' => [1, '9999-12-31', null]]],
            $this->transaction->calls,
        );
    }
}
