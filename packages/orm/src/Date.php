<?php

declare(strict_types=1);

namespace Kinetis\Orm;

use InvalidArgumentException;

/**
 * A calendar date, as a SQL DATE column holds one: a day of the Gregorian
 * calendar in years 0001 to 9999, with no time, time zone or instant. Its
 * string form is "Y-m-d", each field zero-padded.
 */
final readonly class Date
{
    private const string FORMAT = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    /**
     * @throws InvalidArgumentException for a year outside 0001 to 9999, or a month and day that do not exist in it
     */
    public function __construct(public int $year, public int $month, public int $day)
    {
        // checkdate() admits years 1 to 32767.
        if ($year > 9999 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('A Date is a day that exists in the Gregorian calendar in years 0001 to 9999.');
        }
    }

    /**
     * Parses exactly "Y-m-d": four year digits, two month digits and two day
     * digits, with nothing before or after them. The message of a refusal
     * never quotes the string.
     *
     * @throws InvalidArgumentException for any other string, or one naming a day the constructor refuses
     */
    public static function fromString(string $value): self
    {
        if (preg_match(self::FORMAT, $value, $fields) !== 1) {
            throw new InvalidArgumentException('A Date string is exactly "Y-m-d".');
        }

        return new self((int) $fields[1], (int) $fields[2], (int) $fields[3]);
    }

    public function __toString(): string
    {
        return sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);
    }
}
