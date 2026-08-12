<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MaxLength implements Constraint
{
    public function __construct(
        private int $length,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || mb_strlen($value) > $this->length) {
            return "must be at most {$this->length} characters.";
        }

        return null;
    }

    public function length(): int
    {
        return $this->length;
    }
}
