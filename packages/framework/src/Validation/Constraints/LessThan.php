<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class LessThan implements Constraint
{
    public function __construct(
        private int|float $threshold,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value)) {
            return 'must be a number.';
        }

        if ($value >= $this->threshold) {
            return "must be less than {$this->threshold}.";
        }

        return null;
    }

    public function threshold(): int|float
    {
        return $this->threshold;
    }
}
