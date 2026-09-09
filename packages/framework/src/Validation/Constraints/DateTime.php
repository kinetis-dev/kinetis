<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An instant written in RFC 3339's `date-time` form, the one JSON
 * Schema's `format: date-time` names: a `YYYY-MM-DD` date, `T`, a
 * `HH:MM:SS` time with an optional fraction of any length, and either
 * `Z` or a signed `HH:MM` offset. The two letters may be upper or lower
 * case, as RFC 3339 permits, and `-00:00` — its spelling for "the
 * offset is unknown" — is an offset like any other.
 *
 * Two things RFC 3339 allows are not admitted. A leap second (`:60`) is
 * not a second this rule counts, because nothing downstream of a
 * validated string can place one. A space in place of `T` is the
 * document's own readability alternative, not the interchange form, and
 * admitting it would publish two spellings under one `format`.
 *
 * The grammar is ASCII and anchored with PCRE's `D`, so a trailing
 * newline is not a date-time; `checkdate()` and explicit range checks
 * then decide the numbers, giving years `0001` through `9999`, hours
 * `00`-`23`, minutes and seconds `00`-`59`, and the same bounds on the
 * offset's own two fields. No PHP date parser runs and no date object is
 * constructed: a parser accepts a missing offset, rolls an impossible
 * day forward, and reads a local timezone the request never mentioned.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class DateTime implements Constraint
{
    private const string PATTERN = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})[Tt]([0-9]{2}):([0-9]{2}):([0-9]{2})'
        . '(?:\.[0-9]+)?(?:[Zz]|[+-]([0-9]{2}):([0-9]{2}))$/D';

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value, $parts) !== 1 || !self::inRange($parts)) {
            return new Violation([], 'date_time', 'must be an RFC 3339 date-time.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['format' => 'date-time'];
    }

    /**
     * The numbers the grammar has already shaped. A `Z` offset leaves
     * the two trailing groups unmatched, and PCRE reports trailing
     * unmatched groups by omitting them, so their absence is the
     * difference between `Z` and an explicit offset — nothing left to
     * range-check rather than a missing one.
     *
     * @param list<string> $parts
     */
    private static function inRange(array $parts): bool
    {
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] <= 23
            && (int) $parts[5] <= 59
            && (int) $parts[6] <= 59
            && (!isset($parts[7]) || ((int) $parts[7] <= 23 && (int) $parts[8] <= 59));
    }
}
