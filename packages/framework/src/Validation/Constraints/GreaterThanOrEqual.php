<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * An inclusive bound a numeric value must reach.
 *
 * A non-finite threshold is refused at construction. JSON spells neither
 * INF nor NAN, so a `minimum` carrying one has no document it could be
 * published in and the `threshold` parameter of a violation could not be
 * encoded — and every comparison against NAN is false, so the rule would
 * reject every value it was asked about.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class GreaterThanOrEqual implements Constraint
{
    public function __construct(
        private int|float $threshold,
    ) {
        if (is_float($threshold) && !is_finite($threshold)) {
            throw new InvalidArgumentException(
                'GreaterThanOrEqual threshold must be a finite number, ' . var_export($threshold, true) . ' given.',
            );
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_int($value) && !is_float($value)) {
            return new Violation([], 'not_a_number', 'must be a number.');
        }

        if ($value < $this->threshold) {
            return new Violation(
                [],
                'greater_than_or_equal',
                "must be greater than or equal to {$this->threshold}.",
                ['threshold' => $this->threshold],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['minimum' => $this->threshold];
    }
}
