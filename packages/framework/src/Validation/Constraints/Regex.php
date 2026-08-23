<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Regex implements Constraint
{
    public function __construct(
        private string $pattern,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?string
    {
        if (!is_string($value) || preg_match($this->pattern, $value) !== 1) {
            return "must match the pattern {$this->pattern}.";
        }

        return null;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }
}
