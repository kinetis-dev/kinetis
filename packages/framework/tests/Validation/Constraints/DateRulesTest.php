<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Constraints;

use Kinetis\Validation\Constraints\Date;
use Kinetis\Validation\Constraints\DateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two temporal rules read a grammar, not a PHP date parser, and the
 * difference is the whole point: a parser accepts spellings the
 * published `format` does not name, rolls an impossible day forward into
 * the next month, and reads a local timezone the request never
 * mentioned. Each case below is a value the two answers differ on, or a
 * boundary the explicit range checks own.
 */
final class DateRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function dates(): iterable
    {
        yield 'a leap day in a leap year' => ['2024-02-29', true];
        yield 'the first admitted year' => ['0001-01-01', true];
        yield 'the last admitted year' => ['9999-12-31', true];
        yield 'a leap day in a common year' => ['2023-02-29', false];
        // 1900 is divisible by 4 and not a leap year: the century rule
        // is checkdate()'s, and a grammar alone would have admitted it.
        yield 'a leap day in a non-leap century year' => ['1900-02-29', false];
        yield 'unpadded month and day' => ['2024-1-1', false];
        yield 'year zero' => ['0000-01-01', false];
        yield 'a trailing newline' => ["2024-01-01\n", false];
        yield 'a date-time' => ['2024-01-01T10:00:00Z', false];
        yield 'Bengali digits' => ['২০২৪-০১-০১', false];
        yield 'a thirteenth month' => ['2024-13-01', false];
        yield 'a zeroth day' => ['2024-01-00', false];
        yield 'an empty string' => ['', false];
    }

    #[DataProvider('dates')]
    public function test_date_admits_exactly_the_calendar_dates_it_publishes(string $value, bool $accepted): void
    {
        self::assertSame($accepted, new Date()->validate($value) === null);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function instants(): iterable
    {
        yield 'a UTC instant' => ['2024-01-01T10:00:00Z', true];
        yield 'lowercase separators' => ['2024-01-01t10:00:00z', true];
        yield 'a one-digit fraction' => ['2024-01-01T10:00:00.5Z', true];
        yield 'a long fraction' => ['2024-01-01T10:00:00.123456789012Z', true];
        yield 'a half-hour offset' => ['2024-01-01T10:00:00+05:30', true];
        // RFC 3339's spelling for "the offset to local time is unknown",
        // which is an offset like any other to a rule reading grammar.
        yield 'the unknown-offset marker' => ['2024-01-01T10:00:00-00:00', true];
        yield 'midnight' => ['2024-01-01T00:00:00Z', true];
        yield 'the last second of a day' => ['2024-12-31T23:59:59Z', true];
        yield 'a leap day' => ['2024-02-29T00:00:00Z', true];

        yield 'hour 24' => ['2024-01-01T24:00:00Z', false];
        yield 'minute 60' => ['2024-01-01T10:60:00Z', false];
        yield 'a leap second' => ['2024-01-01T23:59:60Z', false];
        yield 'an offset hour of 24' => ['2024-01-01T10:00:00+24:00', false];
        yield 'an offset minute of 60' => ['2024-01-01T10:00:00+05:60', false];
        yield 'an impossible calendar date' => ['2023-02-29T10:00:00Z', false];
        yield 'a space separator' => ['2024-01-01 10:00:00Z', false];
        yield 'a missing offset' => ['2024-01-01T10:00:00', false];
        yield 'an empty fraction' => ['2024-01-01T10:00:00.Z', false];
        yield 'a trailing newline' => ["2024-01-01T10:00:00Z\n", false];
        yield 'a date alone' => ['2024-01-01', false];
        yield 'an unsigned offset' => ['2024-01-01T10:00:0005:30', false];
    }

    #[DataProvider('instants')]
    public function test_date_time_admits_exactly_the_rfc_3339_subset_it_publishes(
        string $value,
        bool $accepted,
    ): void {
        self::assertSame($accepted, new DateTime()->validate($value) === null);
    }
}
