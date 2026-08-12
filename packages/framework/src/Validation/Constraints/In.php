<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class In implements Constraint
{
    /**
     * @param list<scalar> $choices
     */
    public function __construct(
        private array $choices,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!in_array($value, $this->choices, true)) {
            return 'must be one of: ' . implode(', ', array_map('strval', $this->choices)) . '.';
        }

        return null;
    }

    /**
     * @return list<scalar>
     */
    public function choices(): array
    {
        return $this->choices;
    }
}
