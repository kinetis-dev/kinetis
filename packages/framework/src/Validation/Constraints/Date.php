<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * A calendar date written as `YYYY-MM-DD`, the one form JSON Schema's
 * `format: date` names.
 *
 * The grammar is ASCII and fixed-width, and the anchors carry PCRE's `D`
 * modifier so a trailing newline is not a date. `checkdate()` then
 * decides whether the three numbers name a day that exists, which is
 * what makes `2024-02-29` a date and `2023-02-29` and `1900-02-29` not.
 * It admits years 1 through 32767, so the four-digit grammar leaves the
 * accepted range `0001` through `9999`.
 *
 * No PHP date parser runs here. One would accept `2024-1-1`, roll
 * `2023-02-29` forward into March, and read a local timezone the request
 * never mentioned — three ways for the value a client sent to differ
 * from the value this rule admitted.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Date implements Constraint
{
    private const string PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D';

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (
            !is_string($value)
            || preg_match(self::PATTERN, $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            return new Violation([], 'date', 'must be a calendar date in YYYY-MM-DD form.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['format' => 'date'];
    }
}
