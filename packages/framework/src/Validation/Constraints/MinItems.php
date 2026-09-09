<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;
use InvalidArgumentException;

/**
 * A lower bound on how many elements a list-shaped `array` field
 * carries — a plain `array` property or a #[ListOf] one, both of which
 * Hydrator has already established is a JSON array by the time a
 * constraint runs.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class MinItems implements Constraint
{
    public function __construct(
        private int $count,
    ) {
        if ($count < 0) {
            throw new InvalidArgumentException("MinItems count must not be negative, got {$count}.");
        }
    }

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_array($value) || !array_is_list($value)) {
            return new Violation([], 'not_a_list', 'must be a JSON array.');
        }

        if (count($value) < $this->count) {
            return new Violation(
                [],
                'min_items',
                "must contain at least {$this->count} items.",
                ['count' => $this->count],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['minItems' => $this->count];
    }
}
