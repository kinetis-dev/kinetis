<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * Divisibility by a whole number: a quantity sold in packs of six, a
 * duration counted in whole minutes.
 *
 * Both sides are integers. A float divisor would make the check a
 * question about binary floating point rather than about divisibility —
 * `0.3 % 0.1` is not zero — and answering it would need a tolerance the
 * published `multipleOf` does not carry. A float value is refused for
 * the same reason, with the same `not_an_integer` code the divisor's own
 * domain implies.
 *
 * The divisor must be at least 1: zero divides nothing, and a negative
 * divisor names the same multiples as its absolute value while
 * publishing a `multipleOf` JSON Schema does not allow. Negative
 * multiples and zero satisfy the rule.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MultipleOf implements Constraint
{
    public function __construct(
        private int $divisor,
    ) {
        if ($divisor < 1) {
            throw new InvalidArgumentException("MultipleOf divisor must be at least 1, got {$divisor}.");
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_int($value)) {
            return new Violation([], 'not_an_integer', 'must be an integer.');
        }

        if ($value % $this->divisor !== 0) {
            return new Violation(
                [],
                'multiple_of',
                "must be a multiple of {$this->divisor}.",
                ['divisor' => $this->divisor],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['multipleOf' => $this->divisor];
    }
}
