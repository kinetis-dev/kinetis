<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
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
    public function validate(mixed $value): ?Violation
    {
        if (in_array($value, $this->choices, true)) {
            return null;
        }

        return new Violation(
            [],
            'in',
            'must be one of: ' . implode(', ', array_map('strval', $this->choices)) . '.',
            ['choices' => $this->choices],
        );
    }

    #[\Override]
    public function schema(): array
    {
        return ['enum' => $this->choices];
    }
}
