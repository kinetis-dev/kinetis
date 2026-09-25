<?php

declare(strict_types=1);

namespace Kinetis\Orm\Tests;

use InvalidArgumentException;
use Kinetis\Orm\Date;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The calendar value itself: its domain, its one string form and its parser. */
final class DateTest extends TestCase
{
    private const string NO_SUCH_DAY = 'A Date is a day that exists in the Gregorian calendar in years 0001 to 9999.';

    private const string NOT_Y_M_D = 'A Date string is exactly "Y-m-d".';

    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function days(): iterable
    {
        yield 'a day of the year' => [2026, 9, 25, '2026-09-25'];
        yield 'a leap day in a year divisible by 4' => [2024, 2, 29, '2024-02-29'];
        yield 'a leap day in a year divisible by 400' => [2000, 2, 29, '2000-02-29'];
        yield 'the first day of year 0001' => [1, 1, 1, '0001-01-01'];
        yield 'the last day of year 9999' => [9999, 12, 31, '9999-12-31'];
    }

    #[DataProvider('days')]
    public function test_a_day_formats_zero_padded_and_parses_back_to_the_same_fields(int $year, int $month, int $day, string $form): void
    {
        $date = new Date($year, $month, $day);

        self::assertSame($form, (string) $date);
        self::assertSame([$year, $month, $day], [$date->year, $date->month, $date->day]);

        $parsed = Date::fromString($form);

        self::assertSame([$year, $month, $day], [$parsed->year, $parsed->month, $parsed->day]);
        self::assertSame($form, (string) $parsed);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function daysThatDoNotExist(): iterable
    {
        yield 'year 0000' => [0, 1, 1];
        yield 'a negative year' => [-1, 1, 1];
        yield 'year 10000' => [10000, 1, 1];
        yield 'month 0' => [2026, 0, 1];
        yield 'month 13' => [2026, 13, 1];
        yield 'day 0' => [2026, 1, 0];
        yield 'February 30' => [2026, 2, 30];
        yield 'a leap day outside a leap year' => [2025, 2, 29];
        yield 'a leap day in a century year not divisible by 400' => [1900, 2, 29];
        yield 'April 31' => [2026, 4, 31];
    }

    #[DataProvider('daysThatDoNotExist')]
    public function test_a_day_that_does_not_exist_in_years_0001_to_9999_is_refused(int $year, int $month, int $day): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(self::NO_SUCH_DAY);

        new Date($year, $month, $day);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedStrings(): iterable
    {
        yield 'an empty string' => ['', self::NOT_Y_M_D];
        yield 'a timestamp' => ['2026-09-25 00:00:00', self::NOT_Y_M_D];
        yield 'ISO 8601 with a time' => ['2026-09-25T00:00:00Z', self::NOT_Y_M_D];
        yield 'an offset' => ['2026-09-25+00', self::NOT_Y_M_D];
        yield 'leading whitespace' => [' 2026-09-25', self::NOT_Y_M_D];
        yield 'a trailing newline' => ["2026-09-25\n", self::NOT_Y_M_D];
        yield 'an unpadded month' => ['2026-9-25', self::NOT_Y_M_D];
        yield 'a two-digit year' => ['26-09-25', self::NOT_Y_M_D];
        yield 'year 10000' => ['10000-01-01', self::NOT_Y_M_D];
        yield 'a signed year' => ['+2026-09-25', self::NOT_Y_M_D];
        yield 'slashes' => ['2026/09/25', self::NOT_Y_M_D];
        yield 'non-ASCII digits' => ['２０２６-09-25', self::NOT_Y_M_D];
        yield 'a relative word' => ['today', self::NOT_Y_M_D];
        yield 'year 0000' => ['0000-01-01', self::NO_SUCH_DAY];
        yield 'the zero date' => ['0000-00-00', self::NO_SUCH_DAY];
        yield 'February 30' => ['2026-02-30', self::NO_SUCH_DAY];
        yield 'a leap day outside a leap year' => ['2025-02-29', self::NO_SUCH_DAY];
        yield 'month 13' => ['2026-13-01', self::NO_SUCH_DAY];
    }

    #[DataProvider('refusedStrings')]
    public function test_any_string_but_an_existing_day_as_y_m_d_is_refused_without_quoting_it(string $value, string $message): void
    {
        try {
            Date::fromString($value);
            self::fail('The string was accepted.');
        } catch (InvalidArgumentException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
