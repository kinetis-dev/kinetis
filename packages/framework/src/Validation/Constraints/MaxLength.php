<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * An upper bound on a string's length in characters.
 *
 * A negative bound is a definition error, refused at construction: JSON
 * Schema's own `maxLength` is a non-negative integer, so publishing one
 * would produce an invalid document for a rule no string could satisfy.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MaxLength implements Constraint
{
    public function __construct(
        private int $length,
    ) {
        if ($length < 0) {
            throw new InvalidArgumentException("MaxLength length must not be negative, got {$length}.");
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || mb_strlen($value) > $this->length) {
            return new Violation(
                [],
                'max_length',
                "must be at most {$this->length} characters.",
                ['length' => $this->length],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['maxLength' => $this->length];
    }
}
