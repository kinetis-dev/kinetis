<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MinLength implements Constraint
{
    public function __construct(
        private int $length,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || mb_strlen($value) < $this->length) {
            return new Violation(
                [],
                'min_length',
                "must be at least {$this->length} characters.",
                ['length' => $this->length],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['minLength' => $this->length];
    }
}
