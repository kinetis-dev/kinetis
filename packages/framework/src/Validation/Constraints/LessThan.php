<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class LessThan implements Constraint
{
    public function __construct(
        private int|float $threshold,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_int($value) && !is_float($value)) {
            return new Violation([], 'not_a_number', 'must be a number.');
        }

        if ($value >= $this->threshold) {
            return new Violation(
                [],
                'less_than',
                "must be less than {$this->threshold}.",
                ['threshold' => $this->threshold],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['exclusiveMaximum' => $this->threshold];
    }
}
