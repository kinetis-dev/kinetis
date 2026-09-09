<?php

declare(strict_types=1);

namespace Kinetis\Validation\Constraints;

use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Attribute;

/**
 * A syntactically valid email address, as PHP's own
 * FILTER_VALIDATE_EMAIL reads one. Deliverability is not a syntax
 * question and is not checked here.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Email implements Constraint
{
    #[\Override]
    public function validate(mixed $value): ?Violation
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return new Violation([], 'email', 'must be a valid email address.');
        }

        return null;
    }

    #[\Override]
    public function schema(): array
    {
        return ['format' => 'email'];
    }
}
