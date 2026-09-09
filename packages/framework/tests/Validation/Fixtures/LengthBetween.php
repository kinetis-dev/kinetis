<?php

declare(strict_types=1);

namespace Kinetis\Tests\Validation\Fixtures;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * An application rule taking two constructor arguments, so a #[Each]
 * declaration can hand it one positionally and one by name and the
 * rule still receives what it was written with.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class LengthBetween implements Constraint
{
    public function __construct(
        private int $min,
        private int $max,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        $length = is_string($value) ? mb_strlen($value) : 0;

        if ($length >= $this->min && $length <= $this->max) {
            return null;
        }

        return new Violation(
            [],
            'length_between',
            "must be between {$this->min} and {$this->max} characters.",
            ['min' => $this->min, 'max' => $this->max],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return ['minLength' => $this->min, 'maxLength' => $this->max];
    }
}
