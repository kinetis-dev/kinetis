<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * Matches a value against a delimited PHP PCRE with `preg_match()`.
 *
 * Runtime-only: JSON Schema's `pattern` holds an undelimited ECMA-262
 * expression, a different dialect, so republishing this pattern there
 * would state a rule the request is not checked against.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Regex implements Constraint
{
    public function __construct(
        private string $pattern,
    ) {}

    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || preg_match($this->pattern, $value) !== 1) {
            return new Violation(
                [],
                'regex',
                "must match the pattern {$this->pattern}.",
                ['pattern' => $this->pattern],
            );
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return [];
    }
}
