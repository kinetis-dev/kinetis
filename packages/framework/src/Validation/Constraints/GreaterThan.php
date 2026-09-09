<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * An exclusive bound a numeric value must be greater than.
 *
 * A non-finite threshold is refused at construction. JSON spells
 * neither INF nor NAN, so an `exclusiveMinimum` carrying one has no
 * document it could be published in and the `threshold` parameter of a
 * violation could not be encoded — and every comparison against NAN is
 * false, so the rule would admit every value it was asked about.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class GreaterThan implements Constraint
{
    public function __construct(
        private int|float $threshold,
    ) {
        if (is_float($threshold) && !is_finite($threshold)) {
            throw new InvalidArgumentException(
                'GreaterThan threshold must be a finite number, ' . var_export($threshold, true) . ' given.',
            );
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_int($value) && !is_float($value)) {
            return new Violation([], 'not_a_number', 'must be a number.');
        }

        if ($value <= $this->threshold) {
            return new Violation(
                [],
                'greater_than',
                "must be greater than {$this->threshold}.",
                ['threshold' => $this->threshold],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['exclusiveMinimum' => $this->threshold];
    }
}
