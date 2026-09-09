<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;
use InvalidArgumentException;

/**
 * An upper bound on how many elements a list-shaped `array` field
 * carries — a plain `array` property or a #[ListOf] one, both of which
 * Hydrator has already established is a JSON array by the time a
 * constraint runs.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MaxItems implements Constraint
{
    public function __construct(
        private int $count,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException("MaxItems count must not be negative, got {$count}.");
        }
    }

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_array($value) || !array_is_list($value)) {
            return 'must be a JSON array.';
        }

        if (count($value) > $this->count) {
            return "must contain at most {$this->count} items.";
        }

        return null;
    }

    public function count(): int
    {
        return $this->count;
    }
}
